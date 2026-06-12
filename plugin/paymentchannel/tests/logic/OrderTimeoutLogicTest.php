<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代收订单超时关闭逻辑测试（mock 时间，脱离 DB）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\OrderTimeoutLogic;
use plugin\paymentchannel\app\model\Order;

/**
 * 可测试的订单超时逻辑：内存替代订单表，重写查询/关闭接缝，脱离数据库。
 */
class TestableOrderTimeoutLogic extends OrderTimeoutLogic
{
    /** 内存「过期待支付」候选池：由 loadExpiredPendingOrders 返回 */
    public array $expiredPool = [];
    /** 实际被关闭的订单ID（按调用顺序） */
    public array $closedIds = [];
    /** 模拟乐观并发失败（条件更新影响 0 行，已被回调置已支付）的订单ID集合 */
    public array $raceLostIds = [];

    public function __construct()
    {
        // 不调用父构造（避免实例化模型/触发 DB），本类仅用内存
    }

    protected function loadExpiredPendingOrders(string $nowTime, int $limit): array
    {
        // 返回内存候选池（模拟 SQL 已按 expire_time < now 过滤），并尊重单批上限
        return array_slice($this->expiredPool, 0, $limit);
    }

    protected function closeOrder(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        // 模拟乐观并发：命中 raceLostIds 的订单条件更新影响 0 行 → 关闭失败
        if (in_array($id, $this->raceLostIds, true)) {
            return false;
        }
        $this->closedIds[] = $id;
        return true;
    }
}

/**
 * 代收订单超时关闭逻辑测试
 *
 * 覆盖 README 6.1 要求的「超时判定」（mock 时间做确定性断言），以及扫描关闭的
 * 防御性二次确认、乐观并发关闭失败、单批上限等。全部脱离 DB。
 */
class OrderTimeoutLogicTest extends TestCase
{
    /** 固定参考时间，所有用例据此构造过期/未过期时间，保证确定性 */
    private const NOW = '2026-06-09 12:00:00';

    private function now(): int
    {
        return strtotime(self::NOW);
    }

    // ===== 超时判定 isTimeout（纯函数，mock 时间） =====

    /**
     * 待支付且已过期 → 应关闭
     */
    public function testIsTimeoutPendingExpired(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $order = ['status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:59:59'];
        $this->assertTrue($logic->isTimeout($order, $this->now()));
    }

    /**
     * 待支付但未到期 → 不关闭
     */
    public function testIsTimeoutPendingNotExpired(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $order = ['status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 12:00:01'];
        $this->assertFalse($logic->isTimeout($order, $this->now()));
    }

    /**
     * 过期时间恰等于当前时间 → 不关闭（严格小于才算过期，留 1 秒余量）
     */
    public function testIsTimeoutEqualBoundaryNotClosed(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $order = ['status' => Order::STATUS_PENDING, 'expire_time' => self::NOW];
        $this->assertFalse($logic->isTimeout($order, $this->now()));
    }

    /**
     * 非待支付状态（已支付/已失败/已关闭）即便过期也不关闭
     */
    public function testIsTimeoutNonPendingNeverClosed(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $expired = '2026-06-09 10:00:00';
        $now = $this->now();
        $this->assertFalse($logic->isTimeout(['status' => Order::STATUS_PAID, 'expire_time' => $expired], $now));
        $this->assertFalse($logic->isTimeout(['status' => Order::STATUS_FAILED, 'expire_time' => $expired], $now));
        $this->assertFalse($logic->isTimeout(['status' => Order::STATUS_CLOSED, 'expire_time' => $expired], $now));
    }

    /**
     * 无过期时间（空/缺失）保守处理为不关闭
     */
    public function testIsTimeoutEmptyExpireNotClosed(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $now = $this->now();
        $this->assertFalse($logic->isTimeout(['status' => Order::STATUS_PENDING, 'expire_time' => ''], $now));
        $this->assertFalse($logic->isTimeout(['status' => Order::STATUS_PENDING, 'expire_time' => null], $now));
        $this->assertFalse($logic->isTimeout(['status' => Order::STATUS_PENDING], $now)); // 缺字段
    }

    /**
     * 过期时间不可解析 → 保守不关闭
     */
    public function testIsTimeoutUnparseableExpireNotClosed(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $order = ['status' => Order::STATUS_PENDING, 'expire_time' => 'not-a-valid-datetime'];
        $this->assertFalse($logic->isTimeout($order, $this->now()));
    }

    // ===== 扫描关闭 closeTimeoutOrders =====

    /**
     * 候选池全为过期待支付单 → 全部关闭，返回关闭数
     */
    public function testCloseTimeoutOrdersClosesAllExpired(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $logic->expiredPool = [
            ['id' => 101, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:00:00'],
            ['id' => 102, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:30:00'],
            ['id' => 103, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:59:00'],
        ];

        $closed = $logic->closeTimeoutOrders($this->now());

        $this->assertSame(3, $closed);
        $this->assertSame([101, 102, 103], $logic->closedIds);
    }

    /**
     * 防御性二次确认：候选池混入「非超时」行（已支付 / 未到期）时被跳过，仅关闭真正超时的单
     */
    public function testCloseTimeoutOrdersSkipsNonTimeoutDefensively(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $logic->expiredPool = [
            ['id' => 201, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:00:00'], // 真超时
            ['id' => 202, 'status' => Order::STATUS_PAID, 'expire_time' => '2026-06-09 11:00:00'],    // 已支付，跳过
            ['id' => 203, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 12:30:00'], // 未到期，跳过
        ];

        $closed = $logic->closeTimeoutOrders($this->now());

        $this->assertSame(1, $closed);
        $this->assertSame([201], $logic->closedIds);
    }

    /**
     * 乐观并发：条件更新影响 0 行（订单刚被回调置已支付）→ 不计入关闭数
     */
    public function testCloseTimeoutOrdersRaceLostNotCounted(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $logic->expiredPool = [
            ['id' => 301, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:00:00'],
            ['id' => 302, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:00:00'],
        ];
        // 302 在扫描与关闭之间被回调置为已支付 → closeOrder 条件更新影响 0 行
        $logic->raceLostIds = [302];

        $closed = $logic->closeTimeoutOrders($this->now());

        $this->assertSame(1, $closed);
        $this->assertSame([301], $logic->closedIds);
    }

    /**
     * 空候选池 → 关闭 0 单
     */
    public function testCloseTimeoutOrdersEmptyPoolReturnsZero(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $this->assertSame(0, $logic->closeTimeoutOrders($this->now()));
        $this->assertSame([], $logic->closedIds);
    }

    /**
     * 单批上限：limit 限制单次关闭数量（防止单次扫描占用过久）
     */
    public function testCloseTimeoutOrdersRespectsLimit(): void
    {
        $logic = new TestableOrderTimeoutLogic();
        $logic->expiredPool = [
            ['id' => 401, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:00:00'],
            ['id' => 402, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:00:00'],
            ['id' => 403, 'status' => Order::STATUS_PENDING, 'expire_time' => '2026-06-09 11:00:00'],
        ];

        // 仅取前 2 条
        $closed = $logic->closeTimeoutOrders($this->now(), 2);

        $this->assertSame(2, $closed);
        $this->assertSame([401, 402], $logic->closedIds);
    }
}
