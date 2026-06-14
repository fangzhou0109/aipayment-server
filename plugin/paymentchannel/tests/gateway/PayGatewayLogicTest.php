<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：下单网关逻辑测试（DB 接缝重写 + 注入假适配器）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\gateway;

use Closure;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\PayGatewayLogic;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\service\channel\ChannelAdapterInterface;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\CreateOrderResult;
use plugin\paymentchannel\service\channel\dto\PaymentStatusResult;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\DayLimitService;
use plugin\paymentchannel\service\RateResolver;
use plugin\paymentchannel\tests\support\TestableRateResolver;
use RuntimeException;

/**
 * 可测试的网关逻辑：重写 DB 接缝与事务，使其脱离数据库可纯单测。
 */
class TestablePayGatewayLogic extends PayGatewayLogic
{
    /** 是否模拟重复订单 */
    public bool $duplicate = false;
    /** 模拟路由结果（null=无可用通道） */
    public ?array $routed = null;
    /** 模拟授权通道 ID 集合 */
    public array $authorizedIds = [3];
    /** 捕获持久化的订单数据 */
    public array $persisted = [];
    /** persistOrder 调用次数 */
    public int $persistCalls = 0;
    /** 捕获回写补丁 */
    public array $updates = [];
    /** updateOrderResult 调用次数 */
    public int $updateCalls = 0;
    /** 注入费率解析器 */
    public ?TestableRateResolver $testRateResolver = null;
    /** merchantId => channelId => 绑定行（含 single_min/max） */
    public array $merchantChannelBindings = [];

    /** 事务：直接执行闭包（单测无真实 DB；异常时不调用回写即等价回滚） */
    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function findDuplicate(int $merchantId, string $outTradeNo): bool
    {
        return $this->duplicate;
    }

    protected function loadAuthorizedChannelIds(int $merchantId): array
    {
        return $this->authorizedIds;
    }

    protected function resolveChannel(int $merchantId, int $payType, string $amount, array $authorizedIds): ?array
    {
        return $this->routed;
    }

    protected function loadMerchantChannelBinding(int $merchantId, int $channelId): ?array
    {
        return $this->merchantChannelBindings[$merchantId][$channelId] ?? null;
    }

    protected function getRateResolver(): RateResolver
    {
        if ($this->testRateResolver === null) {
            $this->testRateResolver = new TestableRateResolver();
            $this->testRateResolver->route = ['rate' => '0.0000'];
            $this->testRateResolver->channel = [
                'id'        => 3,
                'rate'      => '1.5000',
                'rate_self' => '2.6000',
            ];
        }
        return $this->testRateResolver;
    }

    protected function persistOrder(array $data): int
    {
        $this->persisted = $data;
        $this->persistCalls++;
        return 1001;
    }

    protected function updateOrderResult(int $orderId, array $patch): void
    {
        $this->updates = $patch;
        $this->updateCalls++;
    }
}

/**
 * 授权选路专项测试：内存注入路由/通道/授权，走真实 resolveChannel 过滤逻辑。
 */
class ChannelAuthTestablePayGatewayLogic extends PayGatewayLogic
{
    public array $authorizedIds = [];
    public array $routes = [];
    /** routeId => route_channel 候选 */
    public array $routeChannels = [];
    /** channelId => 通道行 */
    public array $channels = [];
    public ?TestableRateResolver $testRateResolver = null;
    public array $persisted = [];
    public int $persistCalls = 0;
    /** merchantId => channelId => 绑定行 */
    public array $merchantChannelBindings = [];
    /** null=不过滤路由, []=白名单为空, int[]=授权 route_id */
    public ?array $merchantRouteFilter = null;

    public function __construct(
        ?Closure $adapterFactory = null,
        ?RateResolver $rateResolver = null,
        ?DayLimitService $dayLimitService = null,
    ) {
        parent::__construct($adapterFactory, $rateResolver, $dayLimitService);
    }

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function findDuplicate(int $merchantId, string $outTradeNo): bool
    {
        return false;
    }

    protected function loadAuthorizedChannelIds(int $merchantId): array
    {
        return $this->authorizedIds;
    }

    protected function loadActiveRoutes(int $payType): array
    {
        return $this->routes;
    }

    protected function loadRouteChannelCandidates(int $routeId): array
    {
        return $this->routeChannels[$routeId] ?? [];
    }

    protected function loadActiveChannel(int $channelId): ?array
    {
        return $this->channels[$channelId] ?? null;
    }

    protected function loadMerchantChannelBinding(int $merchantId, int $channelId): ?array
    {
        return $this->merchantChannelBindings[$merchantId][$channelId] ?? null;
    }

    protected function resolveMerchantRouteFilter(int $merchantId): ?array
    {
        return $this->merchantRouteFilter;
    }

    protected function loadDirectAuthorizedChannels(int $payType, array $authorizedIds): array
    {
        $rows = [];
        foreach ($authorizedIds as $id) {
            $ch = $this->channels[$id] ?? null;
            if ($ch === null) {
                continue;
            }
            if ((int) ($ch['status'] ?? 1) !== 1) {
                continue;
            }
            if ((int) ($ch['pay_type'] ?? 0) !== $payType) {
                continue;
            }
            $biz = (int) ($ch['channel_biz'] ?? Channel::BIZ_PAY_ONLY);
            if (!in_array($biz, [Channel::BIZ_PAY_ONLY, Channel::BIZ_BOTH], true)) {
                continue;
            }
            $rows[] = $ch;
        }

        return $rows;
    }

    protected function getRateResolver(): RateResolver
    {
        return $this->testRateResolver ??= new TestableRateResolver();
    }

    protected function persistOrder(array $data): int
    {
        $this->persisted = $data;
        $this->persistCalls++;
        return 2001;
    }

    protected function updateOrderResult(int $orderId, array $patch): void
    {
    }
}

/**
 * 下单网关逻辑测试
 *
 * 覆盖：参数校验、风控拦截、重复订单、授权白名单、路由命中/无通道、费率解析链、
 * 上游成功回写、上游失败回滚、上游异常回滚。全部脱离 DB/网络。
 */
class PayGatewayLogicTest extends TestCase
{
    /** 默认商户上下文 */
    private function merchant(array $override = []): array
    {
        return array_merge([
            'id' => 1,
            'mch_id' => 'M001',
            'rate' => '99.9', // Phase 9.1 起不参与计费，单测故意设高值以验证被忽略
            'single_min' => '0',
            'single_max' => '0',
            'status' => 1,
        ], $override);
    }

    /** 默认下单参数（money 单位分） */
    private function params(array $override = []): array
    {
        return array_merge([
            'order_id' => 'OUT20260608001',
            'money' => '10000',
            'pay_type' => 3,
            'notify_url' => 'https://merchant.example.com/notify',
            'return_url' => 'https://merchant.example.com/return',
            'commodity_name' => '测试商品',
            'client_ip' => '1.2.3.4',
            'extra' => 'ext_1',
            'sign_type' => 1,
        ], $override);
    }

    /** 默认命中通道（含上游成本，供 assertProfitable） */
    private function routedChannel(): array
    {
        return [
            'route_id' => 7,
            'channel' => [
                'id' => 3,
                'code' => 'mock_ch',
                'adapter' => 'mock',
                'gateway_url' => '',
                'upstream_mch_id' => '',
                'upstream_key' => '',
                'upstream_public_key' => '',
                'upstream_private_key' => '',
                'pay_type' => 3,
                'rate' => '1.5000',
                'rate_self' => '2.6000',
            ],
        ];
    }

    /**
     * 构造注入用的假适配器工厂
     *
     * @param string $mode ok|fail|throw
     * @return Closure
     */
    private function adapterFactory(string $mode): Closure
    {
        return function (array $channel) use ($mode): ChannelAdapterInterface {
            return new class($mode) implements ChannelAdapterInterface {
                public function __construct(private string $mode)
                {
                }

                public function createOrder(CreateOrderRequest $request): CreateOrderResult
                {
                    if ($this->mode === 'throw') {
                        throw new RuntimeException('upstream boom');
                    }
                    if ($this->mode === 'fail') {
                        return CreateOrderResult::fail('余额不足');
                    }
                    return CreateOrderResult::ok('https://pay.example.com/' . $request->orderNo, 'UP' . $request->orderNo);
                }

                public function parseNotify(array $payload): PaymentStatusResult
                {
                    return PaymentStatusResult::pending();
                }

                public function verifyNotify(array $payload): bool
                {
                    return true;
                }

                public function queryOrder(string $orderNo, string $upstreamNo = ''): PaymentStatusResult
                {
                    return PaymentStatusResult::pending();
                }

                public function successResponse(): string
                {
                    return 'success';
                }
            };
        };
    }

    /** 单测/E2E 默认关闭日限（避免依赖 Redis） */
    private function disabledDayLimit(): DayLimitService
    {
        return new DayLimitService(enabled: false);
    }

    /**
     * 内存日限服务（PayGatewayLogic 日限用例）
     *
     * @param array<string,int> $store 共享定点存储
     */
    private function memoryDayLimit(array &$store): DayLimitService
    {
        $toFixed = static fn (string $amount): int
            => (int) bcmul(AmountHelper::format($amount), '10000', 0);

        return new DayLimitService(
            enabled: true,
            dateProvider: static fn (): string => '20260609',
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
     * 构造一个已配置路由的可测逻辑
     */
    private function makeLogic(string $adapterMode = 'ok', ?DayLimitService $dayLimit = null): TestablePayGatewayLogic
    {
        $logic = new TestablePayGatewayLogic(
            $this->adapterFactory($adapterMode),
            null,
            $dayLimit ?? $this->disabledDayLimit(),
        );
        $logic->routed = $this->routedChannel();
        return $logic;
    }

    private function makeAuthLogic(string $adapterMode = 'ok'): ChannelAuthTestablePayGatewayLogic
    {
        return new ChannelAuthTestablePayGatewayLogic(
            $this->adapterFactory($adapterMode),
            null,
            $this->disabledDayLimit(),
        );
    }

    /**
     * 下单成功：建单 + 上游成功 + 回写支付链接，费率走 rate_self 而非 merchant.rate
     */
    public function testSubmitOrderSuccess(): void
    {
        $logic = $this->makeLogic('ok');
        $result = $logic->submitOrder($this->merchant(), $this->params());

        $this->assertArrayHasKey('order_no', $result);
        $this->assertStringStartsWith('P', $result['order_no']);
        $this->assertStringContainsString($result['order_no'], $result['pay_url']);
        $this->assertSame('100.0000', $result['amount']);

        $this->assertSame(1, $logic->persistCalls);
        $this->assertSame('100.0000', $logic->persisted['amount']);
        $this->assertSame('2.6000', $logic->persisted['rate']);  // rate_self，非 merchant.rate 99.9
        $this->assertSame('2.6000', $logic->persisted['fee']);
        $this->assertSame('97.4000', $logic->persisted['real_amount']);
        $this->assertSame(0, $logic->persisted['status']);
        $this->assertSame(3, $logic->persisted['channel_id']);
        $this->assertSame(7, $logic->persisted['route_id']);

        $this->assertSame(1, $logic->updateCalls);
        $this->assertArrayHasKey('pay_url', $logic->updates);
        $this->assertArrayHasKey('upstream_no', $logic->updates);
    }

    /**
     * 无授权绑定：严格模式拒单
     */
    public function testNoAuthorizationRejected(): void
    {
        $logic = $this->makeLogic('ok');
        $logic->authorizedIds = [];

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('商户未配置可用支付通道');
        $logic->submitOrder($this->merchant(), $this->params());
    }

    /**
     * 路由金额不命中时回落直连：仍可下单
     */
    public function testAuthorizedFallsBackToDirectWhenRouteMisses(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->authorizedIds = [10];
        $logic->routes = [['id' => 7, 'pay_type' => 3, 'status' => 1]];
        $logic->routeChannels = [
            7 => [['channel_id' => 10, 'money_rule' => '999-1000', 'weight' => 1]],
        ];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3, 'sort' => 50,
                'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->channel = ['id' => 10, 'rate' => '1.5000', 'rate_self' => '2.6000'];

        $result = $logic->submitOrder($this->merchant(), $this->params());
        $this->assertNotEmpty($result['order_no']);
        $this->assertSame(10, $logic->persisted['channel_id']);
        $this->assertSame(0, $logic->persisted['route_id']);
    }

    /**
     * 直连模式：通道 money_rule 过滤后仅命中范围内的通道
     */
    public function testDirectChannelMoneyRuleFilters(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->authorizedIds = [10, 20];
        $logic->routes = [];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3, 'sort' => 100,
                'money_rule' => '50-200', 'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
            20 => [
                'id' => 20, 'code' => 'ch20', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.8000', 'pay_type' => 3, 'sort' => 10,
                'money_rule' => '999-1000', 'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->channel = ['id' => 10, 'rate' => '1.5000', 'rate_self' => '2.6000'];

        $logic->submitOrder($this->merchant(), $this->params());
        $this->assertSame(10, $logic->persisted['channel_id']);
        $this->assertSame(0, $logic->persisted['route_id']);
    }

    /**
     * 直连模式：全部通道金额规则不命中时拒单
     */
    public function testDirectChannelMoneyRuleRejectsWhenNoMatch(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->authorizedIds = [10, 20];
        $logic->routes = [];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3, 'sort' => 100,
                'money_rule' => '999-1000', 'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
            20 => [
                'id' => 20, 'code' => 'ch20', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.8000', 'pay_type' => 3, 'sort' => 10,
                'money_rule' => '5000+8000', 'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
        ];

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('无可用支付通道');
        $logic->submitOrder($this->merchant(), $this->params());
    }

    /**
     * 无综合路由：多条同 pay_type 授权通道直连选路
     */
    public function testDirectChannelPickWithoutRoutes(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->authorizedIds = [10, 20];
        $logic->routes = [];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3, 'sort' => 10,
                'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
            20 => [
                'id' => 20, 'code' => 'ch20', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.8000', 'pay_type' => 3, 'sort' => 100,
                'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->channel = ['id' => 20, 'rate' => '1.5000', 'rate_self' => '2.8000'];

        $logic->submitOrder($this->merchant(), $this->params());
        $this->assertContains($logic->persisted['channel_id'], [10, 20]);
        $this->assertSame(0, $logic->persisted['route_id']);
    }

    /**
     * 有授权且有路由：下单成功
     */
    public function testAuthorizedWithRouteSuccess(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->authorizedIds = [10];
        $logic->routes = [['id' => 7, 'pay_type' => 3, 'status' => 1]];
        $logic->routeChannels = [
            7 => [['channel_id' => 10, 'money_rule' => '', 'weight' => 1]],
        ];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3,
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->route = ['rate' => '0.0000'];
        $logic->testRateResolver->channel = ['id' => 10, 'rate' => '1.5000', 'rate_self' => '2.6000'];

        $result = $logic->submitOrder($this->merchant(), $this->params());
        $this->assertNotEmpty($result['order_no']);
        $this->assertSame(10, $logic->persisted['channel_id']);
    }

    /**
     * 授权外通道不可被选中（仅授权 channel 20，路由含 10+20 时只能走 20）
     */
    public function testUnauthorizedChannelNotSelected(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->authorizedIds = [20];
        $logic->routes = [['id' => 7, 'pay_type' => 3, 'status' => 1]];
        $logic->routeChannels = [
            7 => [
                ['channel_id' => 10, 'money_rule' => '', 'weight' => 100],
                ['channel_id' => 20, 'money_rule' => '', 'weight' => 1],
            ],
        ];
        $logic->channels = [
            10 => ['id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'rate' => '1.0000', 'rate_self' => '3.0000', 'pay_type' => 3],
            20 => ['id' => 20, 'code' => 'ch20', 'adapter' => 'mock', 'rate' => '1.5000', 'rate_self' => '2.8000', 'pay_type' => 3],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->route = ['rate' => '0.0000'];
        $logic->testRateResolver->channel = ['id' => 20, 'rate' => '1.5000', 'rate_self' => '2.8000'];

        $logic->submitOrder($this->merchant(), $this->params());
        $this->assertSame(20, $logic->persisted['channel_id']);
        $this->assertSame('2.8000', $logic->persisted['rate']);
    }

    /**
     * 无 merchant_route 记录：不收紧，仍遍历全部启用路由（命中 route 7）
     */
    public function testNoMerchantRouteRecordsUsesAllRoutes(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->merchantRouteFilter = null;
        $logic->authorizedIds = [10];
        $logic->routes = [
            ['id' => 7, 'pay_type' => 3, 'status' => 1],
            ['id' => 8, 'pay_type' => 3, 'status' => 1],
        ];
        $logic->routeChannels = [
            7 => [['channel_id' => 10, 'money_rule' => '', 'weight' => 1]],
            8 => [['channel_id' => 10, 'money_rule' => '999-1000', 'weight' => 1]],
        ];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3,
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->route = ['rate' => '0.0000'];
        $logic->testRateResolver->channel = ['id' => 10, 'rate' => '1.5000', 'rate_self' => '2.6000'];

        $logic->submitOrder($this->merchant(), $this->params());
        $this->assertSame(7, $logic->persisted['route_id']);
    }

    /**
     * 有 merchant_route 启用记录：仅遍历授权路由（跳过未授权 route 7，走 route 99）
     */
    public function testMerchantRouteFilterRestrictsRoutes(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->merchantRouteFilter = [99];
        $logic->authorizedIds = [10];
        $logic->routes = [
            ['id' => 7, 'pay_type' => 3, 'status' => 1],
            ['id' => 99, 'pay_type' => 3, 'status' => 1],
        ];
        $logic->routeChannels = [
            7 => [['channel_id' => 10, 'money_rule' => '', 'weight' => 100]],
            99 => [['channel_id' => 10, 'money_rule' => '', 'weight' => 1]],
        ];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3,
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->route = ['rate' => '0.0000'];
        $logic->testRateResolver->channel = ['id' => 10, 'rate' => '1.5000', 'rate_self' => '2.6000'];

        $logic->submitOrder($this->merchant(), $this->params());
        $this->assertSame(99, $logic->persisted['route_id']);
    }

    /**
     * 授权路由内金额不命中：回落直连仍可下单
     */
    public function testMerchantRouteFilterNoCandidateFallsBackDirect(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->merchantRouteFilter = [99];
        $logic->authorizedIds = [10];
        $logic->routes = [
            ['id' => 7, 'pay_type' => 3, 'status' => 1],
            ['id' => 99, 'pay_type' => 3, 'status' => 1],
        ];
        $logic->routeChannels = [
            7 => [['channel_id' => 10, 'money_rule' => '', 'weight' => 1]],
            99 => [['channel_id' => 10, 'money_rule' => '999-1000', 'weight' => 1]],
        ];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3, 'sort' => 10,
                'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->channel = ['id' => 10, 'rate' => '1.5000', 'rate_self' => '2.6000'];

        $logic->submitOrder($this->merchant(), $this->params());
        $this->assertSame(0, $logic->persisted['route_id']);
        $this->assertSame(10, $logic->persisted['channel_id']);
    }

    /**
     * merchant_route 白名单为空：不走路由，直连下单
     */
    public function testMerchantRouteEmptyFilterUsesDirect(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->merchantRouteFilter = [];
        $logic->authorizedIds = [10];
        $logic->routes = [['id' => 7, 'pay_type' => 3, 'status' => 1]];
        $logic->routeChannels = [
            7 => [['channel_id' => 10, 'money_rule' => '', 'weight' => 1]],
        ];
        $logic->channels = [
            10 => [
                'id' => 10, 'code' => 'ch10', 'adapter' => 'mock', 'status' => 1,
                'rate' => '1.5000', 'rate_self' => '2.6000', 'pay_type' => 3, 'sort' => 10,
                'channel_biz' => Channel::BIZ_PAY_ONLY,
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->channel = ['id' => 10, 'rate' => '1.5000', 'rate_self' => '2.6000'];

        $logic->submitOrder($this->merchant(), $this->params());
        $this->assertSame(0, $logic->persisted['route_id']);
    }

    /**
     * 费率走 merchant_channel 解析链快照（忽略 merchant.rate）
     */
    public function testRateResolvedFromMerchantChannel(): void
    {
        $logic = $this->makeLogic('ok');
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->merchantChannel = ['id' => 501, 'rate' => '3.2000', 'status' => 1];
        $logic->testRateResolver->route = ['rate' => '2.0000'];
        $logic->testRateResolver->channel = ['id' => 3, 'rate' => '2.0000', 'rate_self' => '2.6000'];

        $logic->submitOrder($this->merchant(['rate' => '99.9000']), $this->params());
        $this->assertSame('3.2000', $logic->persisted['rate']);
        $this->assertSame('merchant_channel', $logic->persisted['rate_source']);
        $this->assertSame(501, $logic->persisted['merchant_channel_id']);
        $this->assertSame('3.2000', $logic->persisted['fee']); // 100 × 3.2%
        $this->assertSame('96.8000', $logic->persisted['real_amount']);
    }

    /**
     * 平台费率 ≤ 上游成本时拒单
     */
    public function testUpstreamCostInsufficientRejected(): void
    {
        $logic = $this->makeLogic('ok');
        // 上游成本与平台费率均为 2.0：assertProfitable 用 routed channel.rate 校验
        $logic->routed = $this->routedChannel();
        $logic->routed['channel']['rate'] = '2.0000';
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->merchantChannel = ['rate' => '2.0000'];
        $logic->testRateResolver->channel = ['id' => 3, 'rate' => '2.0000', 'rate_self' => '2.6000'];

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('平台费率须大于上游成本');
        $logic->submitOrder($this->merchant(), $this->params());
    }

    /**
     * 重复订单：同商户 + 同商户单号 → 拒绝且不建单
     */
    public function testDuplicateRejected(): void
    {
        $logic = $this->makeLogic('ok');
        $logic->duplicate = true;

        try {
            $logic->submitOrder($this->merchant(), $this->params());
            $this->fail('应拒绝重复订单');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('已存在', $e->getMessage());
        }
        $this->assertSame(0, $logic->persistCalls);
    }

    /**
     * 无可用通道：路由未命中 → 拒绝
     */
    public function testNoChannelRejected(): void
    {
        $logic = $this->makeLogic('ok');
        $logic->routed = null;

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('无可用支付通道');
        $logic->submitOrder($this->merchant(), $this->params());
    }

    /**
     * 风控拦截：商户全局单笔上限 → 拒绝且不建单（选路前兜底）
     */
    public function testRiskRejected(): void
    {
        $logic = $this->makeLogic('ok');
        try {
            $logic->submitOrder($this->merchant(['single_max' => '50']), $this->params());
            $this->fail('应被风控拦截');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('限额', $e->getMessage());
        }
        $this->assertSame(0, $logic->persistCalls);
    }

    /**
     * 通道单笔限额：商户全局不限、通道 max=50、金额 100 → 选路后拒绝
     */
    public function testChannelLimitRejected(): void
    {
        $logic = $this->makeLogic('ok');
        $logic->merchantChannelBindings[1][3] = [
            'single_min' => '0.0000',
            'single_max' => '50.0000',
        ];

        try {
            $logic->submitOrder($this->merchant(), $this->params());
            $this->fail('应被通道限额拦截');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('通道单笔最大限额', $e->getMessage());
        }
        $this->assertSame(0, $logic->persistCalls);
    }

    /**
     * 日累计限额：预检拒绝，不建单
     */
    public function testDayLimitCheckRejected(): void
    {
        $store = [];
        $svc = $this->memoryDayLimit($store);
        $key = $svc->buildKey(1, 3, '20260609');
        $store[$key] = 9500000; // 已占 950 元

        $logic = $this->makeLogic('ok', $svc);
        $logic->merchantChannelBindings[1][3] = [
            'single_min' => '0.0000',
            'single_max' => '0.0000',
            'day_limit'  => '1000.0000',
        ];

        try {
            $logic->submitOrder($this->merchant(), $this->params()); // 100 元，累计将超 1000
            $this->fail('应被日限拦截');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('日累计限额', $e->getMessage());
        }
        $this->assertSame(0, $logic->persistCalls);
    }

    /**
     * 日累计限额：上游失败时释放占用，可再次下单
     */
    public function testDayLimitReleasedOnUpstreamFail(): void
    {
        $store = [];
        $svc = $this->memoryDayLimit($store);
        $logic = $this->makeLogic('fail', $svc);
        $logic->merchantChannelBindings[1][3] = [
            'day_limit' => '500.0000',
        ];

        try {
            $logic->submitOrder($this->merchant(), $this->params());
            $this->fail('上游应失败');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('上游下单失败', $e->getMessage());
        }

        $key = $svc->buildKey(1, 3, '20260609');
        $this->assertSame(0, $store[$key] ?? 0);
    }

    /**
     * 分层限额：全局与通道均放行时下单成功
     */
    public function testLayeredLimitsPass(): void
    {
        $logic = $this->makeLogic('ok');
        $logic->merchantChannelBindings[1][3] = [
            'single_min' => '10.0000',
            'single_max' => '5000.0000',
        ];

        $result = $logic->submitOrder(
            $this->merchant(['single_min' => '1', 'single_max' => '10000']),
            $this->params()
        );

        $this->assertArrayHasKey('order_no', $result);
        $this->assertSame(1, $logic->persistCalls);
    }

    /**
     * 上游下单失败：建单后上游返回失败 → 抛异常，回写未执行（事务回滚）
     */
    public function testUpstreamFailRollback(): void
    {
        $logic = $this->makeLogic('fail');
        try {
            $logic->submitOrder($this->merchant(), $this->params());
            $this->fail('上游失败应抛异常');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('上游下单失败', $e->getMessage());
        }
        $this->assertSame(1, $logic->persistCalls);
        $this->assertSame(0, $logic->updateCalls);
    }

    /**
     * 上游异常：适配器抛异常 → 向上传播，回写未执行（事务回滚）
     */
    public function testUpstreamThrowRollback(): void
    {
        $logic = $this->makeLogic('throw');
        $this->expectException(RuntimeException::class);
        try {
            $logic->submitOrder($this->merchant(), $this->params());
        } finally {
            $this->assertSame(0, $logic->updateCalls);
        }
    }

    /**
     * 参数校验：缺商户单号 / 金额非法 / 缺通知地址 → 拒绝
     */
    public function testParamValidation(): void
    {
        $logic = $this->makeLogic('ok');

        try {
            $logic->submitOrder($this->merchant(), $this->params(['order_id' => '']));
            $this->fail('缺 order_id 应拒绝');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('order_id', $e->getMessage());
        }

        try {
            $logic->submitOrder($this->merchant(), $this->params(['money' => '0']));
            $this->fail('money=0 应拒绝');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('money', $e->getMessage());
        }

        try {
            $logic->submitOrder($this->merchant(), $this->params(['money' => '10.5']));
            $this->fail('money 非整数应拒绝');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('money', $e->getMessage());
        }

        try {
            $logic->submitOrder($this->merchant(), $this->params(['notify_url' => '']));
            $this->fail('缺 notify_url 应拒绝');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('notify_url', $e->getMessage());
        }
    }

    /**
     * 后台 testSubmit：_force_channel_id 跳过路由/权重，直指定通道并保留 client_ip/extra
     */
    public function testSubmitOrderWithForcedChannel(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->authorizedIds = [1, 14];
        $logic->routes = [];
        $logic->channels = [
            1 => [
                'id' => 1,
                'code' => 'mock_test',
                'adapter' => 'mock',
                'status' => 1,
                'pay_type' => 3,
                'channel_biz' => Channel::BIZ_PAY_ONLY,
                'sort' => 100,
                'money_rule' => '',
                'rate' => '0.0000',
                'rate_self' => '0.0000',
                'gateway_url' => '',
                'upstream_mch_id' => '',
                'upstream_key' => '',
            ],
            14 => [
                'id' => 14,
                'code' => 'lqpay',
                'adapter' => 'lqpay',
                'status' => 1,
                'pay_type' => 3,
                'channel_biz' => Channel::BIZ_PAY_ONLY,
                'sort' => 1,
                'money_rule' => '',
                'rate' => '1.5000',
                'rate_self' => '2.6000',
                'gateway_url' => 'https://api.example.com/prod/pay',
                'upstream_mch_id' => 'TEST_M001',
                'upstream_key' => 'secret_upstream_key_32chars_xx',
            ],
        ];
        $logic->testRateResolver = new TestableRateResolver();
        $logic->testRateResolver->channel = $logic->channels[14];

        $result = $logic->submitOrder($this->merchant(), $this->params([
            '_force_channel_id' => 14,
        ]));

        $this->assertArrayHasKey('order_no', $result);
        $this->assertSame(14, $logic->persisted['channel_id']);
        $this->assertSame('1.2.3.4', $logic->persisted['client_ip']);
        $this->assertSame('ext_1', $logic->persisted['extra']);
    }

    /**
     * 强制通道须在商户授权白名单内
     */
    public function testForcedChannelUnauthorizedRejected(): void
    {
        $logic = $this->makeAuthLogic('ok');
        $logic->authorizedIds = [1];
        $logic->channels = [
            14 => [
                'id' => 14,
                'code' => 'lqpay',
                'adapter' => 'lqpay',
                'status' => 1,
                'pay_type' => 3,
                'channel_biz' => Channel::BIZ_PAY_ONLY,
                'rate' => '1.5000',
                'rate_self' => '2.6000',
            ],
        ];

        try {
            $logic->submitOrder($this->merchant(), $this->params(['_force_channel_id' => 14]));
            $this->fail('未授权通道应拒绝');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('未授权', $e->getMessage());
        }
    }
}
