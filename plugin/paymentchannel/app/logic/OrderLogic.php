<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代收订单逻辑层（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\NotifyLog;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\LedgerService;
use plugin\paymentchannel\service\MerchantNotifyService;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\TestNotifyService;
use plugin\saiadmin\exception\ApiException;

/**
 * 代收订单逻辑层（平台后台 /core/pay/order）
 *
 * 提供订单列表/详情（继承 PaymentBaseLogic 的 search/getList/read），以及**人工补单** `reissue`。
 *
 * 补单语义：上游实际收款成功但平台未收到回调（回调丢失）时，运营核实后手动把订单置为已支付，
 * 触发即时入账与商户通知。**强幂等**：
 *  - 入账复用 {@see LedgerService::credit}（`idempotent_key` 唯一索引，重复补单不会重复加余额）；
 *  - 已支付订单的补单仅「重发商户通知」，不再入账；
 *  - 改单与入账「同一事务」，账实一致。
 */
class OrderLogic extends PaymentBaseLogic
{
    /**
     * 入账服务
     * @var LedgerService
     */
    private LedgerService $ledger;

    /**
     * 商户通知服务
     * @var MerchantNotifyService
     */
    private MerchantNotifyService $merchantNotify;

    /**
     * @param LedgerService|null $ledger 入账服务（测试可注入）
     * @param MerchantNotifyService|null $merchantNotify 通知服务（测试可注入）
     */
    public function __construct(?LedgerService $ledger = null, ?MerchantNotifyService $merchantNotify = null)
    {
        $this->model = new Order();
        $this->ledger = $ledger ?? new LedgerService();
        $this->merchantNotify = $merchantNotify ?? new MerchantNotifyService();
    }

    /**
     * 列表搜索：预加载命中通道（仅取展示所需字段，避免泄露上游密钥）
     *
     * @param array $searchWhere 与 Order 搜索器字段对齐
     * @return mixed ThinkORM 查询构造器
     */
    public function search(array $searchWhere = []): mixed
    {
        return parent::search($searchWhere)->with($this->channelWithFields());
    }

    /**
     * 分页列表：扁平化通道快照字段供前端直接展示
     *
     * @param mixed $query
     * @return mixed
     */
    public function getList($query): mixed
    {
        $result = parent::getList($query);
        if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
            $result['data'] = array_map([$this, 'appendChannelSnapshot'], $result['data']);
        }

        return $result;
    }

    /**
     * 订单详情：附带通道名称/编码快照
     *
     * @param mixed $id
     * @return array
     * @throws ApiException
     */
    public function read($id): mixed
    {
        $model = Order::with($this->channelWithFields())->findOrEmpty($id);
        if ($model->isEmpty()) {
            throw new ApiException('数据不存在');
        }

        return $this->appendChannelSnapshot($model->toArray());
    }

    /**
     * 关联加载通道时仅取列表/详情展示字段
     *
     * @return array<string, callable>
     */
    protected function channelWithFields(): array
    {
        return [
            'channel' => function ($query) {
                $query->field('id,title,code,pay_type');
            },
        ];
    }

    /**
     * 将关联 channel 扁平为 channel_title / channel_code（channel_id=0 时为空串）
     *
     * @param array $row 订单行（含可选 channel 关联）
     * @return array
     */
    protected function appendChannelSnapshot(array $row): array
    {
        $channel = $row['channel'] ?? null;
        if (is_array($channel)) {
            $row['channel_title'] = (string) ($channel['title'] ?? '');
            $row['channel_code'] = (string) ($channel['code'] ?? '');
        } else {
            $row['channel_title'] = '';
            $row['channel_code'] = '';
        }
        unset($row['channel']);

        return $row;
    }

    /**
     * 人工补单：把订单置为已支付并入账 + 通知（强幂等）
     *
     * @param int|string $orderId 订单ID
     * @return array { order_no, result, message }
     * @throws PaymentException 订单不存在
     */
    public function reissue(int|string $orderId): array
    {
        $order = $this->loadOrder((int) $orderId);
        if ($order === null) {
            throw new PaymentException('订单不存在');
        }

        $orderNo = (string) ($order['order_no'] ?? '');
        $status = (int) ($order['status'] ?? 0);

        // 已支付：不再入账（幂等），仅重发商户通知，便于补救「已入账但商户没收到通知」的场景
        if ($status === Order::STATUS_PAID) {
            $notifyOk = $this->merchantNotify->dispatch($order);

            return [
                'order_no'    => $orderNo,
                'result'      => 'already_paid',
                'message'     => $notifyOk ? '订单已支付，商户通知已成功' : '订单已支付，商户通知投递失败',
                'notify_ok'   => $notifyOk,
                'notify_url'  => (string) ($order['notify_url'] ?? ''),
                'is_test_notify' => TestNotifyService::isTestNotifyUrl((string) ($order['notify_url'] ?? '')),
            ];
        }

        // 待支付/失败/已关闭 → 运营核实后强制置已支付 + 即时入账（入账层幂等键兜底）
        $this->transaction(function () use ($order) {
            $this->markPaid((int) $order['id']);
            // 入账：可用余额 += real_amount，写流水；idempotent_key 防重复
            $this->ledger->credit($order);
        });

        // 通知放事务外（避免 I/O 拖长事务）；订单已置已支付
        $order['status'] = Order::STATUS_PAID;
        $notifyOk = $this->merchantNotify->dispatch($order);

        return [
            'order_no'       => $orderNo,
            'result'         => 'reissued',
            'message'        => $notifyOk ? '补单成功，商户通知已收妥' : '补单成功，但商户通知投递失败',
            'notify_ok'      => $notifyOk,
            'notify_url'     => (string) ($order['notify_url'] ?? ''),
            'is_test_notify' => TestNotifyService::isTestNotifyUrl((string) ($order['notify_url'] ?? '')),
        ];
    }

    /**
     * 商户门户手动重推「代收」结果通知到下游（仅本商户、仅已支付）
     *
     * 适用场景：下游因网络等原因漏收平台代收成功回调，商户在门户「订单」页手动重推。
     * 仅已支付订单可重推；优先「原样重放」已存在通知日志（含原签名体），
     * 无历史日志则按当前已支付状态重新首发，全部委托 {@see MerchantNotifyService}。
     *
     * @param int|string $id 订单ID
     * @param int $merchantId 当前登录商户ID（取自 token，防越权）
     * @return array{success:bool, message:string}
     * @throws PaymentException 订单不存在 / 非已支付 / 无通知地址
     */
    public function renotifyByMerchant(int|string $id, int $merchantId): array
    {
        $order = Order::where('id', $id)
            ->where('merchant_id', $merchantId)
            ->find();
        if (!$order) {
            throw new PaymentException('订单不存在');
        }
        $data = $order->toArray();

        if (trim((string) ($data['notify_url'] ?? '')) === '') {
            throw new PaymentException('该订单未设置下游通知地址，无需通知');
        }

        if ((int) ($data['status'] ?? 0) !== Order::STATUS_PAID) {
            throw new PaymentException('订单未支付成功，暂无可推送的结果通知');
        }

        $service = new MerchantNotifyService();

        // 优先原样重放最近一条代收通知日志（保持与首发一致的签名体）
        $log = NotifyLog::where('order_no', (string) ($data['order_no'] ?? ''))
            ->where('biz_type', NotifyLog::BIZ_PAY)
            ->order('id', 'desc')
            ->find();
        if ($log) {
            return $service->resendManual((int) $log->id);
        }

        // 无历史通知日志：按当前已支付状态重新首发
        $ok = $service->dispatch($data);
        return [
            'success' => $ok,
            'message' => $ok ? '通知已重新投递，商户已回应 SUCCESS' : '通知投递失败，将按退避策略继续自动重试',
        ];
    }

    /**
     * 平台后台测试下单：走生产 PayGatewayLogic，免商户签名校验
     *
     * @param int $merchantId 商户 ID
     * @param array $params amount(元)、pay_type、out_trade_no、notify_url、channel_id、client_ip、extra 等
     * @return array 网关结果 + 订单快照（含 route_id/channel_id/fee）
     * @throws PaymentException
     */
    public function testSubmit(int $merchantId, array $params): array
    {
        $merchant = $this->loadMerchantForTestSubmit($merchantId);
        if ($merchant === null) {
            throw new PaymentException('商户不存在');
        }

        $amountYuan = AmountHelper::format((string) ($params['amount'] ?? '0'));
        if (!AmountHelper::gtZero($amountYuan)) {
            throw new PaymentException('订单金额必须大于 0');
        }

        $payType = (int) ($params['pay_type'] ?? 0);
        if ($payType <= 0) {
            throw new PaymentException('请选择支付类型');
        }

        $outTradeNo = trim((string) ($params['out_trade_no'] ?? ''));
        if ($outTradeNo === '') {
            $outTradeNo = 'T' . date('YmdHis') . substr((string) random_int(1000, 9999), -4);
        }

        $notifyUrl = trim((string) ($params['notify_url'] ?? ''));
        if ($notifyUrl === '') {
            $notifyUrl = TestNotifyService::resolveDefaultNotifyUrl();
        }

        // 与商户 Demo（/pay/submitOrder）对齐：money 为分、extra/client_ip 参与上游签名拼串
        $gatewayParams = [
            'order_id'       => $outTradeNo,
            'money'          => AmountHelper::format(AmountHelper::mul($amountYuan, '100'), 0),
            'pay_type'       => $payType,
            'notify_url'     => $notifyUrl,
            'return_url'     => trim((string) ($params['return_url'] ?? '')),
            'commodity_name' => (string) ($params['commodity_name'] ?? '后台测试下单'),
            'client_ip'      => trim((string) ($params['client_ip'] ?? '127.0.0.1')),
            'extra'          => trim((string) ($params['extra'] ?? 'admin_test')),
            'sign_type'      => SignService::SIGN_TYPE_MD5,
        ];

        $forceChannelId = (int) ($params['channel_id'] ?? 0);
        if ($forceChannelId > 0) {
            $gatewayParams['_force_channel_id'] = $forceChannelId;
        }

        $result = (new PayGatewayLogic())->submitOrder($merchant, $gatewayParams);
        $order = Order::where('order_no', $result['order_no'] ?? '')->find();

        $pickMode = 'direct';
        if ($forceChannelId > 0) {
            $pickMode = 'forced';
        } elseif ($order && (int) ($order->route_id ?? 0) > 0) {
            $pickMode = 'route';
        }

        return array_merge($result, [
            'out_trade_no'   => $outTradeNo,
            'order_id'       => $order ? (int) $order->id : 0,
            'route_id'       => $order ? (int) ($order->route_id ?? 0) : 0,
            'channel_id'     => $order ? (int) ($order->channel_id ?? 0) : 0,
            'fee'            => $order ? (string) $order->fee : '',
            'real_amount'    => $order ? (string) $order->real_amount : '',
            'pick_mode'      => $pickMode,
            'notify_url'     => $notifyUrl,
            'is_test_notify' => TestNotifyService::isTestNotifyUrl($notifyUrl),
        ]);
    }

    /**
     * 商户门户 / 平台沙箱查单：走生产 queryOrder，免网关签名校验
     *
     * @param int $merchantId 商户 ID
     * @param array $params order_id（商户订单号）
     * @return array
     * @throws PaymentException
     */
    public function testQuery(int $merchantId, array $params): array
    {
        $merchant = $this->loadMerchantForTestSubmit($merchantId);
        if ($merchant === null) {
            throw new PaymentException('商户不存在');
        }

        $outTradeNo = trim((string) ($params['order_id'] ?? $params['out_trade_no'] ?? ''));
        if ($outTradeNo === '') {
            throw new PaymentException('请填写商户订单号');
        }

        return (new PayGatewayLogic())->queryOrder($merchant, ['order_id' => $outTradeNo]);
    }

    /**
     * 查询测试 notify 接收器最近记录（后台闭环排障）
     *
     * @return array{items:array, default_notify_url:string}
     */
    public function testNotifyRecent(int $limit = 20, ?string $orderNo = null, ?string $outTradeNo = null): array
    {
        return [
            'items'              => (new TestNotifyService())->listRecent($limit, $orderNo, $outTradeNo),
            'default_notify_url' => TestNotifyService::resolveDefaultNotifyUrl(),
        ];
    }

    /**
     * 加载测试下单所需商户上下文
     */
    protected function loadMerchantForTestSubmit(int $merchantId): ?array
    {
        $m = Merchant::where('id', $merchantId)->find();
        if (!$m) {
            return null;
        }

        return [
            'id'         => (int) $m->id,
            'mch_id'     => (string) $m->mch_id,
            'name'       => (string) $m->name,
            'status'     => (int) $m->status,
            'rate'       => (string) $m->rate,
            'single_min' => (string) $m->single_min,
            'single_max' => (string) $m->single_max,
        ];
    }

    // ===== DB 访问接缝：默认走 ThinkORM，单测可在子类重写以脱离数据库 =====

    /**
     * 按ID加载订单数组；不存在返回 null
     *
     * @param int $orderId 订单ID
     * @return array|null
     */
    protected function loadOrder(int $orderId): ?array
    {
        $order = Order::where('id', $orderId)->find();
        return $order ? $order->toArray() : null;
    }

    /**
     * 标记订单为已支付（补单时上游单号可能缺失，仅置状态与支付时间）
     *
     * @param int $orderId 订单ID
     */
    protected function markPaid(int $orderId): void
    {
        Order::where('id', $orderId)->update([
            'status'   => Order::STATUS_PAID,
            'pay_time' => date('Y-m-d H:i:s'),
        ]);
    }
}
