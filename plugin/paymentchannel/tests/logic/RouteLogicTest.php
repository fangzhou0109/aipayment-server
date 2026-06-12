<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：RouteLogic 路由试算测试（脱离 DB）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\RouteLogic;
use plugin\paymentchannel\service\RateResolver;
use plugin\paymentchannel\tests\support\TestableRateResolver;

/**
 * 可测试路由逻辑：内存注入授权/候选/通道与费率解析器
 */
class TestableRouteLogic extends RouteLogic
{
    public array $authorizedIds = [];
    public array $candidates = [];
    public array $channels = [];
    public ?TestableRateResolver $testRateResolver = null;

    public function __construct()
    {
        // 跳过父构造中的 Route 模型实例化依赖（本测试仅用内存接缝）
    }

    protected function loadAuthorizedChannelIds(int $merchantId): array
    {
        return $this->authorizedIds;
    }

    protected function loadRouteChannelCandidates(int $routeId): array
    {
        return $this->candidates;
    }

    protected function loadActiveChannel(int $channelId): ?array
    {
        return $this->channels[$channelId] ?? null;
    }

    protected function getRateResolver(): RateResolver
    {
        return $this->testRateResolver ??= new TestableRateResolver();
    }
}

/**
 * RouteLogic 商户上下文试算测试
 */
class RouteLogicTest extends TestCase
{
    private function logic(): TestableRouteLogic
    {
        $logic = new TestableRouteLogic();
        $logic->candidates = [
            ['channel_id' => 10, 'money_rule' => '', 'weight' => 1],
            ['channel_id' => 20, 'money_rule' => '', 'weight' => 1],
        ];
        $logic->channels = [
            10 => [
                'id' => 10, 'title' => '通道A', 'code' => 'ch_a', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000',
            ],
            20 => [
                'id' => 20, 'title' => '通道B', 'code' => 'ch_b', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.8000',
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->route = ['id' => 7, 'rate' => '0.0000'];
        return $logic;
    }

    /**
     * 无商户上下文：按路由规则命中并预览费率（走 rate_self）
     */
    public function testPreviewWithoutMerchant(): void
    {
        $logic = $this->logic();
        // 单候选避免 RouteService 同权重随机命中导致断言不稳定
        $logic->candidates = [
            ['channel_id' => 10, 'money_rule' => '', 'weight' => 1],
        ];
        $logic->testRateResolver->channel = ['id' => 10, 'rate' => '1.5000', 'rate_self' => '2.6000'];

        $result = $logic->previewRoute(7, '100', 0);

        $this->assertTrue($result['hit']);
        $this->assertSame(10, $result['channel_id']);
        $this->assertSame('通道A', $result['channel_title']);
        $this->assertSame('2.6000', $result['resolved_rate']);
        $this->assertSame('2.6000', $result['fee_preview']);
        $this->assertSame('97.4000', $result['real_amount_preview']);
        $this->assertTrue($result['profitable']);
        $this->assertNull($result['merchant_id']);
    }

    /**
     * 有商户但无授权：拒单语义一致
     */
    public function testPreviewMerchantWithoutAuth(): void
    {
        $logic = $this->logic();
        $logic->authorizedIds = [];

        $result = $logic->previewRoute(7, '100', 1);

        $this->assertFalse($result['hit']);
        $this->assertNull($result['channel_id']);
        $this->assertStringContainsString('未配置可用支付通道', $result['message']);
        $this->assertSame(1, $result['merchant_id']);
    }

    /**
     * 有授权：仅授权通道可命中，费率走 merchant_channel
     */
    public function testPreviewWithMerchantAuthAndRate(): void
    {
        $logic = $this->logic();
        $logic->authorizedIds = [20];
        $logic->testRateResolver->merchantChannel = ['rate' => '3.2000', 'status' => 1];
        $logic->testRateResolver->channel = ['id' => 20, 'rate' => '1.5000', 'rate_self' => '2.8000'];

        $result = $logic->previewRoute(7, '100', 5);

        $this->assertTrue($result['hit']);
        $this->assertSame(20, $result['channel_id']);
        $this->assertSame('3.2000', $result['resolved_rate']);
        $this->assertSame('3.2000', $result['fee_preview']);
        $this->assertSame(5, $result['merchant_id']);
    }

    /**
     * 授权外通道不在候选命中集合（仅授权 20，权重相同时应命中 20 而非 10）
     */
    public function testPreviewFiltersUnauthorizedChannel(): void
    {
        $logic = $this->logic();
        $logic->authorizedIds = [20];
        $logic->candidates = [
            ['channel_id' => 10, 'money_rule' => '', 'weight' => 100],
            ['channel_id' => 20, 'money_rule' => '', 'weight' => 1],
        ];
        $logic->testRateResolver->channel = ['id' => 20, 'rate' => '1.5000', 'rate_self' => '2.8000'];

        $result = $logic->previewRoute(7, '100', 1);

        $this->assertTrue($result['hit']);
        $this->assertSame(20, $result['channel_id']);
    }
}
