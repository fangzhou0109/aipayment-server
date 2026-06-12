<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：Mock 模拟通道适配器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\adapters;

use plugin\paymentchannel\app\model\ChannelLog;
use plugin\paymentchannel\service\channel\AbstractChannelAdapter;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\CreateOrderResult;
use plugin\paymentchannel\service\channel\dto\PaymentStatusResult;

/**
 * Mock 模拟通道适配器
 *
 * 不连真实上游，用于本地 / 灰度联调与自动化测试，跑通「下单 → 回调解析」全链路：
 *  - createOrder：不发 HTTP，直接构造一个可访问的模拟收银台链接与模拟上游单号；
 *  - parseNotify：按约定字段（out_trade_no/trade_no/money 分/status）翻译为统一状态；
 *  - verifyNotify：用 MD5（upstreamKey）真实验签，便于验证签名链路；
 *  - queryOrder：恒定返回「已支付」，方便联调补单 / 查单逻辑。
 *
 * 回调约定字段（与平台网关回放保持一致）：
 *   out_trade_no=平台订单号, trade_no=上游单号, money=金额(分), status=success|fail, sign=MD5签名
 */
class MockAdapter extends AbstractChannelAdapter
{
    /**
     * 模拟下单：直接返回收银台链接与模拟上游单号
     *
     * @param CreateOrderRequest $request 下单入参
     * @return CreateOrderResult
     */
    public function createOrder(CreateOrderRequest $request): CreateOrderResult
    {
        $upstreamNo = 'MOCK' . $request->orderNo;
        // 模拟收银台地址，金额以分透传，便于联调页面展示
        $payUrl = 'https://mock-pay.local/cashier/' . $request->orderNo . '?money=' . $this->toCents($request->amount);

        // 落一条下单日志（注入 logger 时进内存，否则进 channel_log）
        $this->channelLog(
            ChannelLog::TYPE_CREATE,
            $request->orderNo,
            $this->encode($request),
            $this->encode(['pay_url' => $payUrl, 'upstream_no' => $upstreamNo]),
        );

        return CreateOrderResult::ok($payUrl, $upstreamNo, 'mock');
    }

    /**
     * 解析模拟回调报文为统一状态
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

        // status=success 视为已支付，其余视为失败
        $paid = (string) ($payload['status'] ?? '') === 'success';

        return $paid
            ? PaymentStatusResult::paid($orderNo, $amount, $upstreamNo, $raw)
            : PaymentStatusResult::failed($orderNo, 'mock not paid', $amount, $upstreamNo, $raw);
    }

    /**
     * 校验模拟回调签名（MD5）
     *
     * @param array $payload 回调参数（含 sign）
     * @return bool
     */
    public function verifyNotify(array $payload): bool
    {
        return $this->verifySign($payload);
    }

    /**
     * 模拟查单：恒返回已支付，便于联调补单流程
     *
     * @param string $orderNo    平台订单号
     * @param string $upstreamNo 上游订单号
     * @return PaymentStatusResult
     */
    public function queryOrder(string $orderNo, string $upstreamNo = ''): PaymentStatusResult
    {
        return PaymentStatusResult::paid($orderNo, '0.0000', $upstreamNo !== '' ? $upstreamNo : 'MOCK' . $orderNo, 'mock-query');
    }
}
