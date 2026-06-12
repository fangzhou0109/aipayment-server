<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：支付状态结果 DTO（回调解析 / 查单共用）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\dto;

/**
 * 支付状态结果
 *
 * 适配器 parseNotify（解析上游回调）与 queryOrder（主动查单）的统一返回——
 * 二者本质都在回答「这笔单现在是什么状态、上游单号、金额多少」，故复用同一 DTO。
 *
 * 金额统一为「元」（decimal 字符串），由适配器从上游单位（通常为分）换算而来。
 * $orderNo 为平台订单号（回调里从上游回传的商户订单号还原）；查单场景由调用方已知，可为空。
 */
final class PaymentStatusResult
{
    /**
     * @param PaymentOutcome $outcome    支付结果（待支付/已支付/失败）
     * @param string         $orderNo    平台订单号
     * @param string         $amount     金额（元）
     * @param string         $upstreamNo 上游订单号
     * @param string         $message    备注/原因（失败时尤其有用）
     * @param string         $raw        上游原始报文
     */
    private function __construct(
        public readonly PaymentOutcome $outcome,
        public readonly string $orderNo,
        public readonly string $amount,
        public readonly string $upstreamNo,
        public readonly string $message,
        public readonly string $raw,
    ) {
    }

    /**
     * 构造「已支付」结果
     *
     * @param string $orderNo    平台订单号
     * @param string $amount     金额（元）
     * @param string $upstreamNo 上游订单号
     * @param string $raw        上游原始报文
     * @return self
     */
    public static function paid(string $orderNo, string $amount, string $upstreamNo = '', string $raw = ''): self
    {
        return new self(PaymentOutcome::Paid, $orderNo, $amount, $upstreamNo, '', $raw);
    }

    /**
     * 构造「待支付」结果
     *
     * @param string $orderNo    平台订单号
     * @param string $amount     金额（元）
     * @param string $upstreamNo 上游订单号
     * @param string $raw        上游原始报文
     * @return self
     */
    public static function pending(string $orderNo = '', string $amount = '0.0000', string $upstreamNo = '', string $raw = ''): self
    {
        return new self(PaymentOutcome::Pending, $orderNo, $amount, $upstreamNo, '', $raw);
    }

    /**
     * 构造「失败」结果
     *
     * @param string $orderNo    平台订单号
     * @param string $message    失败原因
     * @param string $amount     金额（元）
     * @param string $upstreamNo 上游订单号
     * @param string $raw        上游原始报文
     * @return self
     */
    public static function failed(string $orderNo = '', string $message = '', string $amount = '0.0000', string $upstreamNo = '', string $raw = ''): self
    {
        return new self(PaymentOutcome::Failed, $orderNo, $amount, $upstreamNo, $message, $raw);
    }

    /**
     * 是否已支付成功
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->outcome->isPaid();
    }
}
