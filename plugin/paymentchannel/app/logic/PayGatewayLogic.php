<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户下单网关逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use Closure;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\model\MerchantRoute;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\app\model\Route;
use plugin\paymentchannel\app\model\RouteChannel;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\DayLimitService;
use plugin\paymentchannel\service\RateResolver;
use plugin\paymentchannel\service\channel\ChannelAdapterFactory;
use plugin\paymentchannel\service\channel\ChannelAdapterInterface;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\OrderNoGenerator;
use plugin\paymentchannel\service\RiskService;
use plugin\paymentchannel\service\RouteService;
use plugin\saiadmin\basic\think\BaseLogic;
use Throwable;

/**
 * 商户下单网关逻辑层
 *
 * 编排「下单」全流程：参数校验 → 风控 → 路由选通道 → 事务建单 → 调上游适配器下单 →
 * 回写支付链接。资金/金额全程 {@see AmountHelper}（禁浮点），订单与上游调用同一事务，
 * 上游失败即回滚，杜绝「有订单无支付」的脏单。
 *
 * 可测试性：把「DB 访问」与「适配器构造」收敛为可重写的保护方法 / 可注入闭包，
 * 单测用子类重写 DB 接缝 + 注入假适配器，即可覆盖重复订单、路由命中、适配器异常回滚等分支，
 * 不依赖真实数据库与网络。
 */
class PayGatewayLogic extends BaseLogic
{
    /**
     * 适配器工厂闭包：fn(array $channel): ChannelAdapterInterface
     * @var Closure
     */
    private Closure $adapterFactory;

    /**
     * 代收费率解析器（可注入，单测用 TestableRateResolver）
     */
    private ?RateResolver $rateResolver = null;

    /**
     * 日累计限额服务（可注入，单测/E2E 用内存实现或关闭）
     */
    private ?DayLimitService $dayLimitService = null;

    /**
     * 订单过期时长（秒），到期由定时任务关单（Phase 6.1）
     */
    private const ORDER_TTL = 1800;

    /**
     * @param Closure|null $adapterFactory 适配器工厂（测试可注入假适配器）；null=用真实工厂按通道构造
     * @param RateResolver|null $rateResolver 费率解析器（测试可注入）
     * @param DayLimitService|null $dayLimitService 日限额服务（测试可注入）
     */
    public function __construct(
        ?Closure $adapterFactory = null,
        ?RateResolver $rateResolver = null,
        ?DayLimitService $dayLimitService = null,
    ) {
        $this->model = new Order();
        // 默认用真实工厂：依据通道 adapter 字段 + 上游凭证创建适配器
        $this->adapterFactory = $adapterFactory ?? (fn (array $channel): ChannelAdapterInterface
            => ChannelAdapterFactory::makeFromChannel($channel));
        $this->rateResolver = $rateResolver;
        $this->dayLimitService = $dayLimitService;
    }

    /**
     * 受理商户下单
     *
     * @param array $merchant 商户上下文（id/mch_id/rate/single_min/single_max/status）
     * @param array $params 商户提交的下单参数（order_id/money(分)/pay_type/notify_url/...）
     * @return array { order_no, pay_url, upstream_no, amount }
     * @throws PaymentException 参数缺失/风控拦截/无通道/重复订单/上游失败
     */
    public function submitOrder(array $merchant, array $params): array
    {
        // 1) 解析与校验必填参数（字段名与商户 Demo 对齐：order_id=商户单号、money=分）
        $outTradeNo = trim((string) ($params['order_id'] ?? $params['out_trade_no'] ?? ''));
        $moneyCents = trim((string) ($params['money'] ?? ''));
        $payType = (int) ($params['pay_type'] ?? 0);
        $notifyUrl = trim((string) ($params['notify_url'] ?? ''));

        if ($outTradeNo === '') {
            throw new PaymentException('缺少商户订单号 order_id');
        }
        if ($moneyCents === '' || !ctype_digit($moneyCents) || $moneyCents === '0') {
            throw new PaymentException('订单金额 money 非法（应为正整数，单位分）');
        }
        if ($payType <= 0) {
            throw new PaymentException('缺少支付类型 pay_type');
        }
        if ($notifyUrl === '') {
            throw new PaymentException('缺少异步通知地址 notify_url');
        }

        // 金额：分 → 元（decimal 字符串，禁浮点）
        $amount = AmountHelper::div($moneyCents, '100');

        // 2) 风控准入（商户状态 + 金额为正 + 单笔限额）
        RiskService::checkSubmitOrder($merchant, $amount);

        // 3) 防重复下单：同商户 + 同商户单号唯一（DB 唯一索引兜底，这里先行拒绝给出友好提示）
        $merchantId = (int) ($merchant['id'] ?? 0);
        if ($this->findDuplicate($merchantId, $outTradeNo)) {
            throw new PaymentException('商户订单号已存在');
        }

        // 4) 严格模式：商户须有已授权的代收通道，再在授权集合内路由选路
        $authorizedIds = $this->loadAuthorizedChannelIds($merchantId);
        if ($authorizedIds === []) {
            throw new PaymentException('商户未配置可用支付通道');
        }
        // 后台测试下单可传 _force_channel_id，跳过综合路由/权重直指定通道（与 Demo 走同一适配器）
        $forceChannelId = (int) ($params['_force_channel_id'] ?? 0);
        if ($forceChannelId > 0) {
            $routed = $this->resolveForcedChannel($merchantId, $payType, $amount, $authorizedIds, $forceChannelId);
        } else {
            $routed = $this->resolveChannel($merchantId, $payType, $amount, $authorizedIds);
            if ($routed === null) {
                throw new PaymentException('无可用支付通道');
            }
        }
        $channel = $routed['channel'];
        $routeId = (int) $routed['route_id'];
        $channelId = (int) $channel['id'];

        // 4.5) 商户×通道单笔限额 + 日累计预检（Phase 9.2.2/9.2.3）
        $merchantChannel = $this->loadMerchantChannelBinding($merchantId, $channelId);
        $dayLimit = '0.0000';
        if ($merchantChannel !== null) {
            RiskService::checkChannelLimit($amount, $merchantChannel);
            $dayLimit = AmountHelper::format((string) ($merchantChannel['day_limit'] ?? '0'));
            if (AmountHelper::gtZero($dayLimit)) {
                $this->getDayLimitService()->checkDayLimit($merchantId, $channelId, $dayLimit, $amount);
            }
        }

        // 5) 费率快照与手续费（Phase 9.1 解析链 + 9.3.4 来源快照）
        $rateResolver = $this->getRateResolver();
        $rateDetail = $rateResolver->resolvePayRateDetail($merchantId, $channelId, $routeId);
        $rate = $rateDetail['rate'];
        $rateResolver->assertProfitable($rate, (string) ($channel['rate'] ?? '0'));
        $fee = AmountHelper::fee($amount, $rate);
        $realAmount = AmountHelper::sub($amount, $fee);

        // 6) 生成平台订单号
        $orderNo = (new OrderNoGenerator())->pay();

        // 7) 组装订单数据（待支付）
        $orderData = [
            'order_no'       => $orderNo,
            'out_trade_no'   => $outTradeNo,
            'merchant_id'    => $merchantId,
            'mch_id'         => (string) ($merchant['mch_id'] ?? ''),
            'channel_id'     => $channelId,
            'route_id'       => $routeId,
            'pay_type'       => $payType,
            'amount'         => $amount,
            'fee'            => $fee,
            'real_amount'    => $realAmount,
            'rate'                => $rate,
            'rate_source'         => (string) ($rateDetail['rate_source'] ?? ''),
            'merchant_channel_id' => (int) ($rateDetail['merchant_channel_id'] ?? 0) ?: null,
            'status'              => Order::STATUS_PENDING,
            'settle_status'  => Order::SETTLE_PENDING,
            'notify_status'  => 0,
            'sign_type'      => (int) ($params['sign_type'] ?? 1),
            'notify_url'     => $notifyUrl,
            'return_url'     => trim((string) ($params['return_url'] ?? '')),
            'commodity_name' => (string) ($params['commodity_name'] ?? ''),
            'client_ip'      => (string) ($params['client_ip'] ?? ''),
            'extra'          => (string) ($params['extra'] ?? ''),
            'expire_time'    => date('Y-m-d H:i:s', time() + self::ORDER_TTL),
        ];

        // 8) 事务：建单 → 调上游下单 → 成功回写支付链接；上游失败抛异常 → 整体回滚
        // 注意：传给上游的回调地址必须指向「本平台」(/pay/notify/{通道编码})，而非商户地址；
        // 商户 notify_url 仅落库，待入账后由平台二次异步通知商户（Phase 3.5）。
        $upstreamNotifyUrl = $this->buildUpstreamNotifyUrl((string) ($channel['code'] ?? ''), $notifyUrl);
        return $this->transaction(function () use (
            $orderData,
            $channel,
            $amount,
            $payType,
            $orderNo,
            $upstreamNotifyUrl,
            $params,
            $merchantId,
            $channelId,
            $dayLimit,
        ) {
            $orderId = $this->persistOrder($orderData);

            // Phase 9.2.3：建单成功即占用日额度；上游失败时 release 补偿
            $dayLimitReserved = false;
            if (AmountHelper::gtZero($dayLimit)) {
                $this->getDayLimitService()->reserveDayAmount($merchantId, $channelId, $dayLimit, $amount);
                $dayLimitReserved = true;
            }

            try {
                $adapter = ($this->adapterFactory)($channel);
                $request = new CreateOrderRequest(
                    orderNo: $orderNo,
                    amount: $amount,
                    payType: $payType,
                    notifyUrl: $upstreamNotifyUrl,
                    returnUrl: trim((string) ($params['return_url'] ?? '')),
                    subject: (string) ($params['commodity_name'] ?? ''),
                    clientIp: (string) ($params['client_ip'] ?? ''),
                    extra: (string) ($params['extra'] ?? ''),
                );
                $result = $adapter->createOrder($request);

                if (!$result->success) {
                    $channelCode = (string) ($channel['code'] ?? '');
                    $suffix = $channelCode !== '' ? '（通道 ' . $channelCode . '）' : '';
                    throw new PaymentException('上游下单失败：' . $result->message . $suffix);
                }

                $this->updateOrderResult($orderId, [
                    'upstream_no' => $result->upstreamNo,
                    'pay_url'     => $result->payUrl,
                ]);

                return [
                    'order_no'    => $orderNo,
                    'pay_url'     => $result->payUrl,
                    'upstream_no' => $result->upstreamNo,
                    'amount'      => $amount,
                ];
            } catch (Throwable $e) {
                if ($dayLimitReserved) {
                    $this->getDayLimitService()->releaseDayAmount($merchantId, $channelId, $amount);
                }
                throw $e;
            }
        });
    }

    // ===== 以下为 DB 访问接缝：默认走 ThinkORM，单测可在子类重写以脱离数据库 =====

    /**
     * 拼接上游应回调的平台地址：{notify_domain}/pay/notify/{通道编码}
     *
     * notify_domain 未配置时回退为商户 notify_url（仅便于 Mock 联调，Mock 不真正回调）。
     * 真实上游接入务必在 config/app.php 配置 notify_domain。
     *
     * @param string $channelCode 通道编码
     * @param string $fallback 回退地址（商户 notify_url）
     * @return string 上游回调地址
     */
    protected function buildUpstreamNotifyUrl(string $channelCode, string $fallback): string
    {
        $domain = (string) config('plugin.paymentchannel.app.notify_domain', '');
        if ($domain === '' || $channelCode === '') {
            return $fallback;
        }
        return rtrim($domain, '/') . '/pay/notify/' . $channelCode;
    }

    /**
     * 是否已存在相同 (商户, 商户单号) 的订单
     *
     * @param int $merchantId 商户ID
     * @param string $outTradeNo 商户订单号
     * @return bool
     */
    protected function findDuplicate(int $merchantId, string $outTradeNo): bool
    {
        return Order::where('merchant_id', $merchantId)
            ->where('out_trade_no', $outTradeNo)
            ->count() > 0;
    }

    /**
     * 加载商户×通道绑定行（限额校验用；仅取已授权记录）
     *
     * @param int $merchantId 商户ID
     * @param int $channelId 通道ID
     * @return array|null 含 single_min/single_max；无绑定返回 null
     */
    protected function loadMerchantChannelBinding(int $merchantId, int $channelId): ?array
    {
        $row = MerchantChannel::where('merchant_id', $merchantId)
            ->where('channel_id', $channelId)
            ->where('status', MerchantChannel::STATUS_NORMAL)
            ->field('single_min,single_max,day_limit,status')
            ->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 加载商户已授权的代收通道 ID 集合（status=正常）
     *
     * @param int $merchantId 商户ID
     * @return int[] channel_id 列表，无绑定返回空数组
     */
    protected function loadAuthorizedChannelIds(int $merchantId): array
    {
        $ids = MerchantChannel::where('merchant_id', $merchantId)
            ->where('status', MerchantChannel::STATUS_NORMAL)
            ->column('channel_id');

        return array_map('intval', $ids);
    }

    /**
     * 获取代收费率解析器
     */
    protected function getRateResolver(): RateResolver
    {
        return $this->rateResolver ??= new RateResolver();
    }

    /**
     * 获取日累计限额服务
     */
    protected function getDayLimitService(): DayLimitService
    {
        return $this->dayLimitService ??= new DayLimitService();
    }

    /**
     * 选通道：优先综合路由（多通道金额规则+权重）；未命中时回落直连（channel.sort 作权重）
     *
     * @param int $merchantId 商户ID
     * @param int $payType 支付类型
     * @param string $amount 订单金额（元）
     * @param int[] $authorizedIds 已授权 channel_id 集合
     * @return array{route_id:int,channel:array,pick_mode:string}|null route_id=0 表示直连；pick_mode=route|direct
     */
    protected function resolveChannel(int $merchantId, int $payType, string $amount, array $authorizedIds): ?array
    {
        if ($authorizedIds === []) {
            return null;
        }

        $routed = $this->resolveChannelViaRoutes($merchantId, $payType, $amount, $authorizedIds);
        if ($routed !== null) {
            $routed['pick_mode'] = 'route';

            return $routed;
        }

        return $this->resolveChannelDirect($payType, $amount, $authorizedIds);
    }

    /**
     * 强制指定通道（后台测试下单等场景，须在商户授权白名单内）
     *
     * @param int $merchantId 商户ID
     * @param int $payType 支付类型
     * @param string $amount 订单金额（元）
     * @param int[] $authorizedIds 已授权 channel_id
     * @param int $forceChannelId 指定通道 ID
     * @return array{route_id:int,channel:array,pick_mode:string}
     * @throws PaymentException 未授权 / 通道不可用 / 金额规则不匹配
     */
    protected function resolveForcedChannel(
        int $merchantId,
        int $payType,
        string $amount,
        array $authorizedIds,
        int $forceChannelId,
    ): array {
        if (!in_array($forceChannelId, $authorizedIds, true)) {
            throw new PaymentException('商户未授权该通道，无法测试');
        }

        $channel = $this->loadActiveChannel($forceChannelId);
        if ($channel === null) {
            throw new PaymentException('指定通道不可用（已停用或不存在）');
        }

        $channelPayType = (int) ($channel['pay_type'] ?? 0);
        if ($channelPayType !== $payType) {
            throw new PaymentException('指定通道支付类型与请求不一致');
        }

        $biz = (int) ($channel['channel_biz'] ?? 0);
        if (!in_array($biz, [Channel::BIZ_PAY_ONLY, Channel::BIZ_BOTH], true)) {
            throw new PaymentException('指定通道不具备代收能力');
        }

        $moneyRule = (string) ($channel['money_rule'] ?? '');
        if ($moneyRule !== '' && !RouteService::matchMoneyRule($moneyRule, $amount)) {
            throw new PaymentException('订单金额不满足该通道金额规则');
        }

        return [
            'route_id'  => 0,
            'pick_mode' => 'forced',
            'channel'   => $this->formatChannelForAdapter($channel),
        ];
    }

    /**
     * 综合路由选通道：在授权白名单内按金额规则 + route_channel.weight 选路
     *
     * @return array{route_id:int,channel:array}|null
     */
    protected function resolveChannelViaRoutes(int $merchantId, int $payType, string $amount, array $authorizedIds): ?array
    {
        $authSet = array_fill_keys($authorizedIds, true);

        // Phase 9.3.1：有 merchant_route 但全部停用 → 不走路由，由外层回落直连
        $routeFilter = $this->resolveMerchantRouteFilter($merchantId);
        if ($routeFilter !== null && $routeFilter === []) {
            return null;
        }
        $routeFilterSet = $routeFilter !== null
            ? array_fill_keys($routeFilter, true)
            : null;

        foreach ($this->loadActiveRoutes($payType) as $route) {
            $routeId = (int) $route['id'];
            if ($routeFilterSet !== null && !isset($routeFilterSet[$routeId])) {
                continue;
            }
            $candidates = $this->loadRouteChannelCandidates($routeId);
            $filtered = array_values(array_filter(
                $candidates,
                static fn (array $row): bool => isset($authSet[(int) ($row['channel_id'] ?? 0)])
            ));

            if ($filtered === []) {
                continue;
            }

            $channelId = RouteService::route($filtered, $amount, null, $routeId);
            if ($channelId === null) {
                continue;
            }

            $channel = $this->loadActiveChannel((int) $channelId);
            if ($channel !== null) {
                return [
                    'route_id' => $routeId,
                    'channel'  => $this->formatChannelForAdapter($channel),
                ];
            }
        }

        return null;
    }

    /**
     * 直连选通道：无综合路由命中时，在已授权通道中按 money_rule 过滤后以 sort 加权选取
     *
     * @return array{route_id:int,channel:array,pick_mode:string}|null
     */
    protected function resolveChannelDirect(int $payType, string $amount, array $authorizedIds): ?array
    {
        $channels = $this->loadDirectAuthorizedChannels($payType, $authorizedIds);
        if ($channels === []) {
            return null;
        }

        $candidates = [];
        foreach ($channels as $channel) {
            $candidates[] = [
                'channel_id' => (int) ($channel['id'] ?? 0),
                'money_rule' => (string) ($channel['money_rule'] ?? ''),
                'weight'     => max(1, (int) ($channel['sort'] ?? 1)),
            ];
        }

        $filtered = RouteService::filterByAmount($candidates, $amount);
        if ($filtered === []) {
            return null;
        }

        $channelId = RouteService::pickByWeight($filtered);
        if ($channelId === null) {
            return null;
        }

        $channel = $this->loadActiveChannel((int) $channelId);
        if ($channel === null) {
            return null;
        }

        return [
            'route_id'  => 0,
            'pick_mode' => 'direct',
            'channel'   => $this->formatChannelForAdapter($channel),
        ];
    }

    /**
     * 加载商户已授权、具备代收能力且 pay_type 匹配的启用通道（直连模式候选）
     *
     * @param int $payType 支付类型
     * @param int[] $authorizedIds 已授权 channel_id
     * @return array<int,array>
     */
    protected function loadDirectAuthorizedChannels(int $payType, array $authorizedIds): array
    {
        if ($authorizedIds === []) {
            return [];
        }

        return Channel::whereIn('id', $authorizedIds)
            ->where('status', 1)
            ->where('pay_type', $payType)
            ->whereIn('channel_biz', [Channel::BIZ_PAY_ONLY, Channel::BIZ_BOTH])
            ->order('sort', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 解析商户路由白名单（Phase 9.3.1）
     *
     * @return int[]|null null=不收紧（无任何 merchant_route 行）；非 null=仅遍历这些 route_id
     */
    protected function resolveMerchantRouteFilter(int $merchantId): ?array
    {
        $enabled = MerchantRoute::where('merchant_id', $merchantId)
            ->where('status', MerchantRoute::STATUS_NORMAL)
            ->column('route_id');

        if ($enabled !== []) {
            return array_map('intval', $enabled);
        }

        // 无任何启用记录：若连 merchant_route 行都没有，则保持「全部启用路由」
        $hasAny = MerchantRoute::where('merchant_id', $merchantId)->count() > 0;

        return $hasAny ? [] : null;
    }

    /**
     * 加载某支付类型下启用的路由（按 route.sort 倒序遍历）
     *
     * 同路由内通道由 RouteService + route_channel.weight 决定；未走路由时见 resolveChannelDirect。
     *
     * @param int $payType 支付类型
     * @return array<int,array>
     */
    protected function loadActiveRoutes(int $payType): array
    {
        return Route::where('pay_type', $payType)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 加载路由下启用的通道候选（含金额规则与权重）
     *
     * @param int $routeId 路由ID
     * @return array<int,array>
     */
    protected function loadRouteChannelCandidates(int $routeId): array
    {
        return RouteChannel::where('route_id', $routeId)
            ->where('status', 1)
            ->field('channel_id, money_rule, weight')
            ->select()
            ->toArray();
    }

    /**
     * 加载启用中的通道记录（供路由选路与适配器构造）
     *
     * 须直接读模型属性组装数组，**不可** toArray()：Channel 模型将 upstream_key /
     * upstream_private_key 列入 $hidden，toArray() 会丢失密钥，导致适配器用空密钥签名、
     * 上游返回「签名校验失败」。回调侧 {@see NotifyGatewayLogic::loadChannel} 已用属性读取。
     *
     * @param int $channelId 通道ID
     * @return array|null
     */
    protected function loadActiveChannel(int $channelId): ?array
    {
        $channel = Channel::where('id', $channelId)->where('status', 1)->find();
        if (!$channel) {
            return null;
        }

        return [
            'id'                   => (int) $channel->id,
            'code'                 => (string) $channel->code,
            'adapter'              => (string) $channel->adapter,
            'transfer_adapter'     => (string) ($channel->transfer_adapter ?? ''),
            'channel_biz'          => (int) ($channel->channel_biz ?? 0),
            'gateway_url'          => (string) ($channel->gateway_url ?? ''),
            'upstream_mch_id'      => (string) ($channel->upstream_mch_id ?? ''),
            'upstream_key'         => (string) ($channel->upstream_key ?? ''),
            'upstream_public_key'  => (string) ($channel->upstream_public_key ?? ''),
            'upstream_private_key' => (string) ($channel->upstream_private_key ?? ''),
            'pay_type'             => (int) ($channel->pay_type ?? 0),
            'rate'                 => (string) ($channel->rate ?? '0'),
            'rate_self'            => (string) ($channel->rate_self ?? '0'),
            'money_rule'           => (string) ($channel->money_rule ?? ''),
            'status'               => (int) ($channel->status ?? 0),
        ];
    }

    /**
     * 构造适配器与费率校验所需的通道字段快照
     *
     * @param array $channel 通道行（toArray）
     * @return array
     */
    protected function formatChannelForAdapter(array $channel): array
    {
        return [
            'id'                   => (int) $channel['id'],
            'code'                 => (string) $channel['code'],
            'adapter'              => (string) $channel['adapter'],
            'gateway_url'          => (string) ($channel['gateway_url'] ?? ''),
            'upstream_mch_id'      => (string) ($channel['upstream_mch_id'] ?? ''),
            'upstream_key'         => (string) ($channel['upstream_key'] ?? ''),
            'upstream_public_key'  => (string) ($channel['upstream_public_key'] ?? ''),
            'upstream_private_key' => (string) ($channel['upstream_private_key'] ?? ''),
            'pay_type'             => (int) ($channel['pay_type'] ?? 0),
            'rate'                 => (string) ($channel['rate'] ?? '0'),
            'rate_self'            => (string) ($channel['rate_self'] ?? '0'),
        ];
    }

    /**
     * 持久化订单，返回订单主键ID
     *
     * @param array $data 订单数据
     * @return int 订单ID
     */
    protected function persistOrder(array $data): int
    {
        $order = Order::create($data);
        return (int) $order->id;
    }

    /**
     * 回写订单结果（上游单号 / 支付链接）
     *
     * @param int $orderId 订单ID
     * @param array $patch 待更新字段
     */
    protected function updateOrderResult(int $orderId, array $patch): void
    {
        Order::where('id', $orderId)->update($patch);
    }

    /**
     * 商户查单（网关 /pay/query）
     *
     * 按「商户 + 商户订单号」查订单，返回商户视角的状态。仅能查自己的单（merchant_id 强约束），
     * 杜绝越权查他人订单。
     *
     * @param array $merchant 商户上下文（须含 id）
     * @param array $params 请求参数（order_id=商户订单号）
     * @return array { order_no, out_trade_no, upstream_no, amount, status, trade_status, pay_time }
     * @throws PaymentException 参数缺失或订单不存在
     */
    public function queryOrder(array $merchant, array $params): array
    {
        $outTradeNo = trim((string) ($params['order_id'] ?? $params['out_trade_no'] ?? ''));
        if ($outTradeNo === '') {
            throw new PaymentException('缺少商户订单号 order_id');
        }
        $merchantId = (int) ($merchant['id'] ?? 0);
        $order = $this->findOrder($merchantId, $outTradeNo);
        if ($order === null) {
            throw new PaymentException('订单不存在');
        }

        return [
            'order_no'     => (string) ($order['order_no'] ?? ''),
            'out_trade_no' => (string) ($order['out_trade_no'] ?? ''),
            'upstream_no'  => (string) ($order['upstream_no'] ?? ''),
            'amount'       => AmountHelper::format((string) ($order['amount'] ?? '0')),
            'status'       => (int) ($order['status'] ?? 0),
            'trade_status' => self::tradeStatusText((int) ($order['status'] ?? 0)),
            'pay_time'     => (string) ($order['pay_time'] ?? ''),
        ];
    }

    /**
     * 订单状态码 → 商户视角状态串
     *
     * @param int $status sa_pay_order.status
     * @return string NOTPAY / SUCCESS / FAILED / CLOSED
     */
    public static function tradeStatusText(int $status): string
    {
        return match ($status) {
            Order::STATUS_PAID   => 'SUCCESS',
            Order::STATUS_FAILED => 'FAILED',
            Order::STATUS_CLOSED => 'CLOSED',
            default              => 'NOTPAY',
        };
    }

    /**
     * 按商户 + 商户订单号查订单（接缝，单测可重写）
     *
     * @param int $merchantId 商户ID
     * @param string $outTradeNo 商户订单号
     * @return array|null
     */
    protected function findOrder(int $merchantId, string $outTradeNo): ?array
    {
        $order = Order::where('merchant_id', $merchantId)
            ->where('out_trade_no', $outTradeNo)
            ->find();
        return $order ? $order->toArray() : null;
    }
}
