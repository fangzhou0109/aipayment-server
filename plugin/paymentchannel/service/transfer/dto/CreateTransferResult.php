<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游代付受理结果 DTO
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer\dto;

/**
 * 上游代付受理结果
 *
 * 适配器 createTransfer 的统一返回。代付为「异步出款」：成功仅表示**上游已受理**
 * （处理中），最终成败以上游回调 / 查单为准（{@see TransferStatusResult}）。
 * $raw 保留上游原始响应，便于排障与落库到 channel_log。
 */
final class CreateTransferResult
{
    /**
     * @param bool   $success    上游是否受理成功（受理 != 出款成功）
     * @param string $upstreamNo 上游代付订单号（受理时返回，可空）
     * @param string $message    失败原因（失败时）
     * @param string $raw        上游原始响应报文
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $upstreamNo,
        public readonly string $message,
        public readonly string $raw,
    ) {
    }

    /**
     * 构造「受理成功」结果（上游已接单，进入处理中）
     *
     * @param string $upstreamNo 上游代付订单号
     * @param string $raw        上游原始响应
     * @return self
     */
    public static function ok(string $upstreamNo = '', string $raw = ''): self
    {
        return new self(true, $upstreamNo, '', $raw);
    }

    /**
     * 构造「受理失败」结果（上游拒单，可直接判失败退款）
     *
     * @param string $message 失败原因
     * @param string $raw     上游原始响应
     * @return self
     */
    public static function fail(string $message, string $raw = ''): self
    {
        return new self(false, '', $message, $raw);
    }
}
