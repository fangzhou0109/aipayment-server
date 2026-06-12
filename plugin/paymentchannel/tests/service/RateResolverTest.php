<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：RateResolver 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\service\RateResolver;
use plugin\paymentchannel\tests\support\TestableRateResolver;

/**
 * RateResolver 代收费率解析测试
 *
 * 覆盖：优先级链、利润底线、merchant.rate 不参与、小数精度。
 */
class RateResolverTest extends TestCase
{
    private function resolver(array $override = []): TestableRateResolver
    {
        $r = new TestableRateResolver();
        $r->merchantChannel = $override['merchantChannel'] ?? null;
        $r->route = $override['route'] ?? ['id' => 1, 'rate' => '0.0000'];
        $r->channel = $override['channel'] ?? [
            'id'        => 10,
            'rate'      => '1.8000',
            'rate_self' => '2.6000',
        ];
        return $r;
    }

    /**
     * Phase 9.4.2：merchant_channel.rate_transfer > 0 时优先于通道代付默认
     */
    public function testMerchantChannelTransferRateOverrides(): void
    {
        $r = new TestableRateResolver();
        $r->merchantChannelTransfers = [
            1 => [
                10 => ['rate_transfer' => '3.2000', 'transfer_enabled' => 1],
            ],
        ];
        $r->channel = ['id' => 10, 'rate_transfer_self' => '1.5000'];

        $this->assertSame('3.2000', $r->resolveTransferRate(1, 10));
    }

    /**
     * Phase 9.4.2：rate_transfer=0 时回落 channel.rate_transfer_self
     */
    public function testChannelTransferRateSelfFallback(): void
    {
        $r = new TestableRateResolver();
        $r->merchantChannelTransfers = [
            1 => [
                10 => ['rate_transfer' => '0.0000', 'transfer_enabled' => 1],
            ],
        ];
        $r->channel = ['id' => 10, 'rate_transfer_self' => '2.1000'];

        $this->assertSame('2.1000', $r->resolveTransferRate(1, 10));
    }

    /**
     * 通道链均为 0 时解析为 0%（免手续费，不拒单）
     */
    public function testTransferRateZeroAllowed(): void
    {
        $r = new TestableRateResolver();
        $r->merchantChannelTransfers = [1 => [10 => ['rate_transfer' => '0.0000', 'transfer_enabled' => 1]]];
        $r->channel = ['id' => 10, 'rate_transfer_self' => '0.0000'];

        $this->assertSame('0.0000', $r->resolveTransferRate(1, 10));
    }

    /**
     * 无代付绑定时通道默认为 0，回落商户全局 rate_transfer
     */
    public function testMerchantGlobalTransferRateFallback(): void
    {
        $r = new TestableRateResolver();
        $r->merchantChannelTransfers = [];
        $r->channel = ['id' => 10, 'rate_transfer_self' => '0.0000'];

        $this->assertSame('2.8000', $r->resolveTransferRateForApply(1, 10, '2.8000'));
    }

    /**
     * 已授权且 rate_transfer=0 继承通道 0% 时，不回落商户全局（免手续费）
     */
    public function testMerchantChannelInheritZeroBeatsGlobal(): void
    {
        $r = new TestableRateResolver();
        $r->merchantChannelTransfers = [1 => [10 => ['rate_transfer' => '0.0000', 'transfer_enabled' => 1]]];
        $r->channel = ['id' => 10, 'rate_transfer_self' => '0.0000'];

        $this->assertSame('0.0000', $r->resolveTransferRateForApply(1, 10, '9.9000'));
    }

    /**
     * 无代付通道时仅用商户全局 rate_transfer
     */
    public function testApplyTransferRateWithoutChannel(): void
    {
        $r = new TestableRateResolver();
        $this->assertSame('1.2000', $r->resolveTransferRateForApply(1, null, '1.2000'));
    }

    /**
     * 无通道且全局费率为 0 时仍解析为 0%（免手续费提现）
     */
    public function testApplyTransferRateZeroWithoutChannel(): void
    {
        $r = new TestableRateResolver();
        $this->assertSame('0.0000', $r->resolveTransferRateForApply(1, null, '0'));
    }

    /**
     * merchant_channel.rate > 0 时优先于路由与通道默认
     */
    public function testMerchantChannelRateOverrides(): void
    {
        $r = $this->resolver([
            'merchantChannel' => ['rate' => '3.5000', 'status' => 1],
            'route'           => ['rate' => '2.8000'],
            'channel'         => ['rate' => '1.5000', 'rate_self' => '2.0000'],
        ]);

        $this->assertSame('3.5000', $r->resolvePayRate(1, 10, 1));
    }

    /**
     * merchant_channel.rate = 0 时忽略 route.rate，回落 channel.rate_self
     */
    public function testRouteRateIgnoredFallbackToChannel(): void
    {
        $r = $this->resolver([
            'merchantChannel' => ['rate' => '0.0000', 'status' => 1],
            'route'           => ['rate' => '2.8000'],
            'channel'         => ['rate' => '1.5000', 'rate_self' => '2.0000'],
        ]);

        $this->assertSame('2.0000', $r->resolvePayRate(1, 10, 1));
    }

    /**
     * merchant_channel 与 route 均为 0 时回落 channel.rate_self
     */
    public function testChannelRateSelfFallback(): void
    {
        $r = $this->resolver([
            'merchantChannel' => null,
            'route'           => ['rate' => '0.0000'],
            'channel'         => ['rate' => '1.8000', 'rate_self' => '2.6000'],
        ]);

        $this->assertSame('2.6000', $r->resolvePayRate(1, 10, 1));
    }

    /**
     * 三级费率全为 0 时拒单
     */
    public function testAllZeroRatesReject(): void
    {
        $r = $this->resolver([
            'merchantChannel' => ['rate' => '0.0000'],
            'route'           => ['rate' => '0.0000'],
            'channel'         => ['rate' => '1.0000', 'rate_self' => '0.0000'],
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('平台费率未配置');
        $r->resolvePayRate(1, 10, 1);
    }

    /**
     * 平台费率等于上游成本时 assertProfitable 拒绝
     */
    public function testAssertProfitableRejectsEqualUpstream(): void
    {
        $r = new RateResolver();

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('平台费率须大于上游成本');
        $r->assertProfitable('2.6000', '2.6000');
    }

    /**
     * 平台费率严格大于上游成本时通过
     */
    public function testAssertProfitablePassesWhenGreater(): void
    {
        $r = new RateResolver();
        $r->assertProfitable('2.6001', '2.6000');
        $this->addToAssertionCount(1);
    }

    /**
     * merchant.rate 不参与解析（即使商户表有高费率，仍走通道链）
     */
    public function testMerchantRateNotUsed(): void
    {
        // 模拟场景：商户表 rate=99.9，但绑定/路由为 0，仅 channel.rate_self 有效
        $r = $this->resolver([
            'merchantChannel' => ['rate' => '0.0000'],
            'route'           => ['rate' => '0.0000'],
            'channel'         => ['rate' => '1.0000', 'rate_self' => '2.5000'],
        ]);

        $rate = $r->resolvePayRate(1, 10, 1);
        $this->assertSame('2.5000', $rate);
        $this->assertNotSame('99.9000', $rate);
    }

    /**
     * 小数精度：费率保留 4 位小数
     */
    public function testDecimalPrecision(): void
    {
        $r = $this->resolver([
            'merchantChannel' => ['rate' => '2.3456'],
            'route'           => ['rate' => '0'],
            'channel'         => ['rate' => '1.0000', 'rate_self' => '2.0000'],
        ]);

        $this->assertSame('2.3456', $r->resolvePayRate(1, 10, 1));
    }

    /**
     * merchant_channel 有值时优先于更高的 route.rate
     */
    public function testMerchantChannelBeatsHigherRouteRate(): void
    {
        $r = $this->resolver([
            'merchantChannel' => ['rate' => '2.1000'],
            'route'           => ['rate' => '5.0000'],
            'channel'         => ['rate' => '1.0000', 'rate_self' => '3.0000'],
        ]);

        $this->assertSame('2.1000', $r->resolvePayRate(1, 10, 1));
    }

    /**
     * resolvePayRateDetail：merchant_channel 来源含绑定 ID
     */
    public function testResolvePayRateDetailMerchantChannelSource(): void
    {
        $r = $this->resolver([
            'merchantChannel' => ['id' => 501, 'rate' => '3.2000', 'status' => 1],
            'route'           => ['rate' => '2.0000'],
            'channel'         => ['rate' => '1.0000', 'rate_self' => '2.6000'],
        ]);

        $detail = $r->resolvePayRateDetail(1, 10, 1);
        $this->assertSame('3.2000', $detail['rate']);
        $this->assertSame(Order::RATE_SOURCE_MERCHANT_CHANNEL, $detail['rate_source']);
        $this->assertSame(501, $detail['merchant_channel_id']);
    }

    /**
     * resolvePayRateDetail：route.rate 已废弃，有值时仍回落 channel.rate_self
     */
    public function testResolvePayRateDetailIgnoresRouteRate(): void
    {
        $r = $this->resolver([
            'merchantChannel' => ['rate' => '0.0000'],
            'route'           => ['rate' => '2.8000'],
            'channel'         => ['rate' => '1.5000', 'rate_self' => '2.0000'],
        ]);

        $detail = $r->resolvePayRateDetail(1, 10, 1);
        $this->assertSame('2.0000', $detail['rate']);
        $this->assertSame(Order::RATE_SOURCE_CHANNEL, $detail['rate_source']);
        $this->assertSame(0, $detail['merchant_channel_id']);
    }

    /**
     * resolvePayRateDetail：channel.rate_self 来源
     */
    public function testResolvePayRateDetailChannelSource(): void
    {
        $r = $this->resolver([
            'merchantChannel' => null,
            'route'           => ['rate' => '0.0000'],
            'channel'         => ['rate' => '1.8000', 'rate_self' => '2.6000'],
        ]);

        $detail = $r->resolvePayRateDetail(1, 10, 1);
        $this->assertSame('2.6000', $detail['rate']);
        $this->assertSame(Order::RATE_SOURCE_CHANNEL, $detail['rate_source']);
        $this->assertSame(0, $detail['merchant_channel_id']);
    }
}
