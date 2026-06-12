<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：日累计限额服务测试（内存存储，脱离 Redis）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\DayLimitService;
use RuntimeException;

/**
 * DayLimitService 单测：内存定点累加模拟 Redis 原子占用
 */
class DayLimitServiceTest extends TestCase
{
    /**
     * 构造内存版日限服务（key => 定点累计）
     *
     * @param array<string,int> $store 共享存储
     * @param string $dateYmd 固定日期键
     */
    private function memoryService(array &$store, string $dateYmd = '20260609'): DayLimitService
    {
        $toFixed = static fn (string $amount): int
            => (int) bcmul(AmountHelper::format($amount), '10000', 0);

        return new DayLimitService(
            enabled: true,
            dateProvider: static fn (): string => $dateYmd,
            reader: static function (string $key) use (&$store): string {
                $fixed = $store[$key] ?? 0;
                return AmountHelper::format(bcdiv((string) $fixed, '10000', 4));
            },
            reserver: static function (string $key, string $amount, string $limit, int $ttl) use (&$store, $toFixed): bool {
                $add = $toFixed($amount);
                $lim = $toFixed($limit);
                $cur = $store[$key] ?? 0;
                if ($cur + $add > $lim) {
                    return false;
                }
                $store[$key] = $cur + $add;
                return true;
            },
            releaser: static function (string $key, string $amount) use (&$store, $toFixed): void {
                $sub = $toFixed($amount);
                $store[$key] = max(0, ($store[$key] ?? 0) - $sub);
            },
        );
    }

    /**
     * day_limit=0 不限：预检与占用均跳过
     */
    public function testZeroDayLimitUnlimited(): void
    {
        $store = [];
        $svc = $this->memoryService($store);
        $svc->checkDayLimit(1, 3, '0', '99999');
        $svc->reserveDayAmount(1, 3, '0', '99999');
        $this->assertSame([], $store);
    }

    /**
     * 未超限：预检通过 + 占用成功
     */
    public function testWithinDayLimitPasses(): void
    {
        $store = [];
        $svc = $this->memoryService($store);
        $svc->checkDayLimit(1, 3, '1000.0000', '100.0000');
        $svc->reserveDayAmount(1, 3, '1000.0000', '100.0000');
        $key = $svc->buildKey(1, 3, '20260609');
        $this->assertSame(1000000, $store[$key]);
    }

    /**
     * 预检：累计+本次超过日限 → 拒绝
     */
    public function testCheckExceedsDayLimit(): void
    {
        $store = [];
        $svc = $this->memoryService($store);
        $key = $svc->buildKey(1, 3, '20260609');
        $store[$key] = 9500000; // 950 元

        try {
            $svc->checkDayLimit(1, 3, '1000.0000', '100.0000');
            $this->fail('应拒绝日限超限');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('日累计限额', $e->getMessage());
        }
    }

    /**
     * 刚好达到上限：占用成功；再多 0.0001 失败
     */
    public function testReserveAtExactLimitBoundary(): void
    {
        $store = [];
        $svc = $this->memoryService($store);
        $svc->reserveDayAmount(1, 3, '100.0000', '100.0000');

        $this->expectException(PaymentException::class);
        $svc->reserveDayAmount(1, 3, '100.0000', '0.0001');
    }

    /**
     * 跨日：日期键变化后计数重置
     */
    public function testCrossDayResetsCounter(): void
    {
        $store = [];
        $svcDay1 = $this->memoryService($store, '20260609');
        $svcDay1->reserveDayAmount(1, 3, '100.0000', '100.0000');

        $svcDay2 = $this->memoryService($store, '20260610');
        $svcDay2->checkDayLimit(1, 3, '100.0000', '50.0000');
        $svcDay2->reserveDayAmount(1, 3, '100.0000', '50.0000');

        $this->assertArrayHasKey($svcDay1->buildKey(1, 3, '20260609'), $store);
        $this->assertArrayHasKey($svcDay2->buildKey(1, 3, '20260610'), $store);
    }

    /**
     * 并发累加：第二次占用因原子校验失败
     */
    public function testConcurrentReserveSecondFails(): void
    {
        $store = [];
        $svc = $this->memoryService($store);
        $svc->reserveDayAmount(1, 3, '150.0000', '100.0000');

        try {
            $svc->reserveDayAmount(1, 3, '150.0000', '100.0000');
            $this->fail('第二次占用应超限');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('日累计限额', $e->getMessage());
        }
    }

    /**
     * 释放占用：上游失败后扣减累计
     */
    public function testReleaseAfterReserve(): void
    {
        $store = [];
        $svc = $this->memoryService($store);
        $svc->reserveDayAmount(1, 3, '500.0000', '100.0000');
        $svc->releaseDayAmount(1, 3, '100.0000');
        $key = $svc->buildKey(1, 3, '20260609');
        $this->assertSame(0, $store[$key] ?? 0);
    }

    /**
     * Redis 不可用：fail-close 拒单
     */
    public function testRedisUnavailableFailClose(): void
    {
        $svc = new DayLimitService(
            enabled: true,
            reader: static fn (): string => throw new RuntimeException('redis down'),
        );

        try {
            $svc->checkDayLimit(1, 3, '1000.0000', '10.0000');
            $this->fail('Redis 异常应 fail-close');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('日限额服务暂不可用', $e->getMessage());
        }
    }
}
