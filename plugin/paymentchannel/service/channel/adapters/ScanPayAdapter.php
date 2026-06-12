<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：通用扫码上游适配器（真实样例）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\adapters;

use plugin\paymentchannel\app\model\ChannelLog;
use plugin\paymentchannel\service\channel\AbstractChannelAdapter;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\CreateOrderResult;
use plugin\paymentchannel\service\channel\dto\PaymentStatusResult;

/**
 * 通用扫码上游适配器（真实样例）
 *
 * 对接一类「主流聚合上游」的典型协议，作为接入真实上游的范本，演示如何在适配器内
 * 完成：字段映射 → 金额换算（元→分）→ 签名 → HTTP 表单提交 → 解析响应。
 *
 * 约定的上游协议（样例）：
 *  - 下单：POST 表单到 gatewayUrl，参数 mch_id/out_trade_no/money(分)/pay_type/notify_url/...，MD5 签名；
 *    响应 JSON：{ code: 0|200, msg, data: { pay_url, trade_no } }（兼容平铺 pay_url/trade_no）。
 *  - 回调：上游 POST 表单 mch_id/out_trade_no/trade_no/money(分)/trade_status/sign；
 *    trade_status=SUCCESS 视为支付成功。
 *  - 查单：POST 表单到 extra['query_url']（缺省用 gatewayUrl），返回同回调的 trade_status。
 *
 * 真实接入新上游时，复制本类改字段映射即可，核心业务零改动。
 */
class ScanPayAdapter extends AbstractChannelAdapter
{
    /** 上游判定为成功的响应码集合（兼容多种写法） */
    private const SUCCESS_CODES = ['0', '200', 'success', 'SUCCESS'];

    /** 上游判定为支付成功的交易状态集合 */
    private const PAID_STATES = ['SUCCESS', 'success', '1'];

    /**
     * 向上游发起下单
     *
     * @param CreateOrderRequest $request 下单入参
     * @return CreateOrderResult
     */
    public function createOrder(CreateOrderRequest $request): CreateOrderResult
    {
        // 1) 组装上游下单参数：平台订单号作为上游侧商户订单号，金额换算为分
        $params = [
            'mch_id' => $this->credential->upstreamMchId,
            'out_trade_no' => $request->orderNo,
            'money' => $this->toCents($request->amount),
            'pay_type' => $request->payType,
            'notify_url' => $request->notifyUrl,
            'return_url' => $request->returnUrl,
            'subject' => $request->subject,
            'client_ip' => $request->clientIp,
            'extra' => $request->extra,
            'time' => (string) time(),
        ];
        // 2) 签名（MD5/RSA 由凭证 signType 决定）
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = $this->credential->signType;

        // 3) 提交并落日志
        $raw = $this->httpPost($this->credential->gatewayUrl, $params, ChannelLog::TYPE_CREATE, $request->orderNo);
        $resp = $this->decodeJson($raw);

        // 4) 解析响应：兼容 data 嵌套与平铺两种结构
        $code = (string) ($resp['code'] ?? '');
        if (!in_array($code, self::SUCCESS_CODES, true)) {
            $msg = (string) ($resp['msg'] ?? $resp['message'] ?? '上游下单失败');
            return CreateOrderResult::fail($msg, $raw);
        }
        $data = is_array($resp['data'] ?? null) ? $resp['data'] : $resp;
        $payUrl = (string) ($data['pay_url'] ?? $data['payUrl'] ?? '');
        $upstreamNo = (string) ($data['trade_no'] ?? $data['order_no'] ?? '');

        if ($payUrl === '') {
            return CreateOrderResult::fail('上游未返回支付链接', $raw);
        }
        return CreateOrderResult::ok($payUrl, $upstreamNo, $raw);
    }

    /**
     * 解析上游回调
     *
     * @param array $payload 回调参数
     * @return PaymentStatusResult
     */
    public function parseNotify(array $payload): PaymentStatusResult
    {
        $orderNo = (string) ($payload['out_trade_no'] ?? '');
        $upstreamNo = (string) ($payload['trade_no'] ?? '');
        $amount = $this->toYuan((string) ($payload['money'] ?? '0'));
        $raw = $this->encode($payload);

        $state = (string) ($payload['trade_status'] ?? $payload['status'] ?? '');
        if (in_array($state, self::PAID_STATES, true)) {
            return PaymentStatusResult::paid($orderNo, $amount, $upstreamNo, $raw);
        }
        return PaymentStatusResult::failed($orderNo, '上游回调未支付:' . $state, $amount, $upstreamNo, $raw);
    }

    /**
     * 校验上游回调签名
     *
     * @param array $payload 回调参数（含 sign）
     * @return bool
     */
    public function verifyNotify(array $payload): bool
    {
        return $this->verifySign($payload);
    }

    /**
     * 主动向上游查单
     *
     * @param string $orderNo    平台订单号
     * @param string $upstreamNo 上游订单号
     * @return PaymentStatusResult
     */
    public function queryOrder(string $orderNo, string $upstreamNo = ''): PaymentStatusResult
    {
        $params = [
            'mch_id' => $this->credential->upstreamMchId,
            'out_trade_no' => $orderNo,
            'trade_no' => $upstreamNo,
            'time' => (string) time(),
        ];
        $params['sign'] = $this->sign($params);

        // 查单地址可在 extra.query_url 单独配置，缺省复用网关地址
        $queryUrl = (string) ($this->credential->extra['query_url'] ?? $this->credential->gatewayUrl);
        $raw = $this->httpPost($queryUrl, $params, ChannelLog::TYPE_QUERY, $orderNo);
        $resp = $this->decodeJson($raw);

        $data = is_array($resp['data'] ?? null) ? $resp['data'] : $resp;
        $state = (string) ($data['trade_status'] ?? $data['status'] ?? '');
        $amount = $this->toYuan((string) ($data['money'] ?? '0'));
        $respUpstreamNo = (string) ($data['trade_no'] ?? $upstreamNo);

        if (in_array($state, self::PAID_STATES, true)) {
            return PaymentStatusResult::paid($orderNo, $amount, $respUpstreamNo, $raw);
        }
        // 未支付：上游明确失败给 Failed，否则按待支付处理（避免误判关单）
        if ($state === 'FAIL' || $state === 'fail' || $state === '2') {
            return PaymentStatusResult::failed($orderNo, '上游查单失败:' . $state, $amount, $respUpstreamNo, $raw);
        }
        return PaymentStatusResult::pending($orderNo, $amount, $respUpstreamNo, $raw);
    }
}
