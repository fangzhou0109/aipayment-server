<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付状态结果 DTO（回调解析 / 查单共用）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer\dto;

/**
 * 代付状态结果
 *
 * 适配器 parseTransferNotify（解析上游代付回调）与 queryTransfer（主动查单）的统一返回——
 * 二者都在回答「这笔代付现在是什么状态、上游单号、金额多少」，复用同一 DTO。
 *
 * 金额统一为「元」（decimal 字符串），由适配器从上游单位换算而来。
 * $transferNo 为平台代付单号（回调里从上游回传的商户订单号还原）。
 */
final class TransferStatusResult
{
    /**
     * @param TransferOutcome $outcome    代付结果（处理中/成功/失败）
     * @param string          $transferNo 平台代付单号
     * @param string          $amount     金额（元）
     * @param string          $upstreamNo 上游代付订单号
     * @param string          $message    备注/原因（失败时尤其有用）
     * @param string          $raw        上游原始报文
     */
    private function __construct(
        public readonly TransferOutcome $outcome,
        public readonly string $transferNo,
        public readonly string $amount,
        public readonly string $upstreamNo,
        public readonly string $message,
        public readonly string $raw,
    ) {
    }

    /**
     * 构造「出款成功」结果
     *
     * @param string $transferNo 平台代付单号
     * @param string $amount     金额（元）
     * @param string $upstreamNo 上游代付订单号
     * @param string $raw        上游原始报文
     * @return self
     */
    public static function success(string $transferNo, string $amount = '0.0000', string $upstreamNo = '', string $raw = ''): self
    {
        return new self(TransferOutcome::Success, $transferNo, $amount, $upstreamNo, '', $raw);
    }

    /**
     * 构造「处理中」结果
     *
     * @param string $transferNo 平台代付单号
     * @param string $amount     金额（元）
     * @param string $upstreamNo 上游代付订单号
     * @param string $raw        上游原始报文
     * @return self
     */
    public static function processing(string $transferNo = '', string $amount = '0.0000', string $upstreamNo = '', string $raw = ''): self
    {
        return new self(TransferOutcome::Processing, $transferNo, $amount, $upstreamNo, '', $raw);
    }

    /**
     * 构造「出款失败」结果
     *
     * @param string $transferNo 平台代付单号
     * @param string $message    失败原因
     * @param string $amount     金额（元）
     * @param string $upstreamNo 上游代付订单号
     * @param string $raw        上游原始报文
     * @return self
     */
    public static function failed(string $transferNo = '', string $message = '', string $amount = '0.0000', string $upstreamNo = '', string $raw = ''): self
    {
        return new self(TransferOutcome::Failed, $transferNo, $amount, $upstreamNo, $message, $raw);
    }

    /**
     * 是否出款成功
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->outcome->isSuccess();
    }

    /**
     * 是否出款失败
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->outcome->isFailed();
    }
}
