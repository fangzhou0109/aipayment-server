<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游代付适配器接口（SPI）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer;

use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\dto\CreateTransferResult;
use plugin\paymentchannel\service\transfer\dto\TransferStatusResult;

/**
 * 上游代付（出款）适配器接口（SPI）
 *
 * 「接入一个代付上游」的统一契约。核心业务（代付/提现下发、代付回调处理、查单）只依赖本接口，
 * **不感知任何上游差异**；新增代付上游 = 新增一个实现类并在注册表登记，核心代码零改动。
 *
 * 与代收适配器（{@see \plugin\paymentchannel\service\channel\ChannelAdapterInterface}）对称：
 *  - createTransfer：向上游发起代付（出款），返回受理结果 / 上游单号；
 *  - parseTransferNotify：解析上游代付异步回调为统一状态；
 *  - verifyNotify：校验上游回调签名（防伪造）；
 *  - queryTransfer：主动向上游查代付结果（回调丢失时补偿）。
 *
 * 附加 successResponse：上游回调成功后平台需回应的确认串（如 success/SUCCESS/OK）。
 */
interface TransferAdapterInterface
{
    /**
     * 向上游发起代付（出款）
     *
     * 注意：代付为异步——返回「受理成功」仅表示上游已接单（处理中），最终成败以回调/查单为准。
     *
     * @param CreateTransferRequest $request 标准代付入参
     * @return CreateTransferResult 受理结果（成功含上游单号，失败含原因）
     */
    public function createTransfer(CreateTransferRequest $request): CreateTransferResult;

    /**
     * 解析上游代付异步回调报文
     *
     * 仅做「报文 → 统一状态」的翻译，不做验签（验签交给 verifyNotify）、不落库、不改账，
     * 保持纯函数特性，便于单测与复用。
     *
     * @param array $payload 上游回调原始参数（已解析为数组）
     * @return TransferStatusResult 统一代付状态（含平台代付单号、金额、上游单号）
     */
    public function parseTransferNotify(array $payload): TransferStatusResult;

    /**
     * 校验上游回调签名
     *
     * @param array $payload 上游回调原始参数（含 sign）
     * @return bool 通过返回 true
     */
    public function verifyNotify(array $payload): bool;

    /**
     * 主动向上游查代付结果（回调缺失时的补偿手段）
     *
     * @param string $transferNo 平台代付单号（作为上游侧商户订单号）
     * @param string $upstreamNo 上游代付订单号（可空）
     * @return TransferStatusResult 统一代付状态
     */
    public function queryTransfer(string $transferNo, string $upstreamNo = ''): TransferStatusResult;

    /**
     * 上游回调处理成功后，平台应回应给上游的「确认串」
     *
     * @return string 确认串（如 success / SUCCESS / OK）
     */
    public function successResponse(): string;
}
