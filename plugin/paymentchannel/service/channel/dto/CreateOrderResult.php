<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游下单结果 DTO
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\dto;

/**
 * 上游下单结果
 *
 * 适配器 createOrder 的统一返回。成功时携带支付链接（或二维码内容）与上游订单号；
 * 失败时携带可读原因。$raw 保留上游原始响应，便于排障与落库到 channel_log。
 *
 * 用私有构造 + 静态工厂（ok/fail），避免在各处手工拼一致性易错的布尔状态。
 */
final class CreateOrderResult
{
    /**
     * @param bool   $success    是否下单成功
     * @param string $payUrl     支付链接 / 二维码内容（成功时）
     * @param string $upstreamNo 上游订单号（成功时，可空）
     * @param string $message    失败原因（失败时）
     * @param string $raw        上游原始响应报文
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $payUrl,
        public readonly string $upstreamNo,
        public readonly string $message,
        public readonly string $raw,
    ) {
    }

    /**
     * 构造「下单成功」结果
     *
     * @param string $payUrl     支付链接 / 二维码内容
     * @param string $upstreamNo 上游订单号
     * @param string $raw        上游原始响应
     * @return self
     */
    public static function ok(string $payUrl, string $upstreamNo = '', string $raw = ''): self
    {
        return new self(true, $payUrl, $upstreamNo, '', $raw);
    }

    /**
     * 构造「下单失败」结果
     *
     * @param string $message 失败原因
     * @param string $raw     上游原始响应
     * @return self
     */
    public static function fail(string $message, string $raw = ''): self
    {
        return new self(false, '', '', $message, $raw);
    }
}
