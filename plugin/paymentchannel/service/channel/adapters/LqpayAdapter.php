<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：LQPAY / SaiPayment 同源上游适配器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\adapters;

use plugin\paymentchannel\app\model\ChannelLog;
use plugin\paymentchannel\service\channel\AbstractChannelAdapter;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\CreateOrderResult;
use plugin\paymentchannel\service\channel\dto\PaymentStatusResult;

/**
 * LQPAY（SaiPayment 同源）上游代收适配器
 *
 * 当上游系统与本平台使用同一套 SaiPayment 源码部署时，本平台以「商户」身份调用上游
 * `/pay/submitOrder`、`/pay/query`，异步通知字段与 {@see \plugin\paymentchannel\service\MerchantNotifyService} 一致。
 *
 * 通道表配置约定（示例 id=14）：
 *  - gateway_url：上游网关基址，如 `https://api.fangzhou.uk/prod/pay`（无尾斜杠亦可）；
 *  - upstream_mch_id：上游为本平台开设的商户号（如 TEST_M001）；
 *  - upstream_key：该商户在上游的 MD5 密钥（sign_type 默认 MD5）；
 *  - extra.query_url（可选）：独立查单地址，缺省为 gateway_url + `/query`。
 *
 * 下单时本平台订单号作为上游 order_id；上游回调 order_id 即本平台 order_no。
 */
class LqpayAdapter extends AbstractChannelAdapter
{
    /** SaiPayment 统一成功响应码 */
    private const SUCCESS_CODE = '200';

    /** 上游商户异步通知：已支付 */
    private const NOTIFY_PAID = 'success';

    /** 查单 trade_status：已支付 */
    private const QUERY_PAID = 'SUCCESS';

    /** 查单 trade_status：失败 / 关单 */
    private const QUERY_FAILED = ['FAILED', 'CLOSED'];

    /**
     * 向上游（LQPAY）发起代收下单
     */
    public function createOrder(CreateOrderRequest $request): CreateOrderResult
    {
        $params = [
            'mch_id'         => $this->credential->upstreamMchId,
            'pay_type'       => (string) $request->payType,
            'money'          => $this->toCents($request->amount),
            'time'           => (string) time(),
            'order_id'       => $request->orderNo,
            'notify_url'     => $request->notifyUrl,
            'return_url'     => $request->returnUrl,
            'commodity_name' => $request->subject,
            'extra'          => $request->extra,
            'client_ip'      => $request->clientIp,
        ];
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = $this->credential->signType;

        $url = $this->endpoint('submitOrder');
        $raw = $this->httpPost($url, $params, ChannelLog::TYPE_CREATE, $request->orderNo);
        $resp = $this->decodeJson($raw);

        $code = (string) ($resp['code'] ?? '');
        if ($code !== self::SUCCESS_CODE) {
            $msg = (string) ($resp['message'] ?? $resp['msg'] ?? '上游下单失败');
            return CreateOrderResult::fail($msg, $raw);
        }

        $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $payUrl = (string) ($data['pay_url'] ?? '');
        // 上游平台单号在 order_no；upstream_no 为更上游单号（若有）
        $upstreamNo = (string) ($data['upstream_no'] ?? $data['order_no'] ?? '');

        if ($payUrl === '') {
            return CreateOrderResult::fail('上游未返回支付链接', $raw);
        }

        return CreateOrderResult::ok($payUrl, $upstreamNo, $raw);
    }

    /**
     * 解析上游异步通知（MerchantNotifyService 格式）
     */
    public function parseNotify(array $payload): PaymentStatusResult
    {
        // 下单时 order_id=本平台订单号；兼容极少数上游改写为 out_trade_no
        $orderNo = (string) ($payload['order_id'] ?? $payload['out_trade_no'] ?? '');
        $upstreamNo = (string) ($payload['order_no'] ?? $payload['trade_no'] ?? '');
        $amount = $this->toYuan((string) ($payload['money'] ?? '0'));
        $raw = $this->encode($payload);

        $status = strtolower((string) ($payload['status'] ?? ''));
        if ($status === self::NOTIFY_PAID) {
            return PaymentStatusResult::paid($orderNo, $amount, $upstreamNo, $raw);
        }

        return PaymentStatusResult::failed($orderNo, '上游回调未支付:' . ($payload['status'] ?? ''), $amount, $upstreamNo, $raw);
    }

    /**
     * 校验上游回调签名（使用上游商户密钥）
     */
    public function verifyNotify(array $payload): bool
    {
        return $this->verifySign($payload);
    }

    /**
     * 主动向上游查单
     */
    public function queryOrder(string $orderNo, string $upstreamNo = ''): PaymentStatusResult
    {
        $params = [
            'mch_id'   => $this->credential->upstreamMchId,
            'order_id' => $orderNo,
            'time'     => (string) time(),
        ];
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = $this->credential->signType;

        $queryUrl = (string) ($this->credential->extra['query_url'] ?? $this->endpoint('query'));
        $raw = $this->httpPost($queryUrl, $params, ChannelLog::TYPE_QUERY, $orderNo);
        $resp = $this->decodeJson($raw);

        $code = (string) ($resp['code'] ?? '');
        if ($code !== self::SUCCESS_CODE) {
            $msg = (string) ($resp['message'] ?? $resp['msg'] ?? '上游查单失败');
            return PaymentStatusResult::failed($orderNo, $msg, '0.0000', $upstreamNo, $raw);
        }

        $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $tradeStatus = (string) ($data['trade_status'] ?? '');
        $amount = $this->resolveQueryAmount((string) ($data['amount'] ?? '0'));
        $respUpstreamNo = (string) ($data['upstream_no'] ?? $data['order_no'] ?? $upstreamNo);

        if ($tradeStatus === self::QUERY_PAID) {
            return PaymentStatusResult::paid($orderNo, $amount, $respUpstreamNo, $raw);
        }
        if (in_array($tradeStatus, self::QUERY_FAILED, true)) {
            return PaymentStatusResult::failed($orderNo, '上游查单失败:' . $tradeStatus, $amount, $respUpstreamNo, $raw);
        }

        return PaymentStatusResult::pending($orderNo, $amount, $respUpstreamNo, $raw);
    }

    /**
     * LQPAY 商户通知约定：平台须回应 SUCCESS
     */
    public function successResponse(): string
    {
        return 'SUCCESS';
    }

    /**
     * 拼接网关动作 URL（gateway_url 已为 /pay 基址）
     */
    private function endpoint(string $action): string
    {
        return rtrim($this->credential->gatewayUrl, '/') . '/' . ltrim($action, '/');
    }

    /**
     * 解析查单响应金额：上游 /pay/query 的 amount 为元（decimal 字符串）
     */
    private function resolveQueryAmount(string $amount): string
    {
        $text = trim($amount);
        return $text === '' ? '0.0000' : $text;
    }
}
