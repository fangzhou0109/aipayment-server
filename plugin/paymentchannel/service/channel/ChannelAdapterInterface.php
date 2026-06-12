<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游渠道适配器接口（SPI）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel;

use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\CreateOrderResult;
use plugin\paymentchannel\service\channel\dto\PaymentStatusResult;

/**
 * 上游渠道适配器接口（SPI）
 *
 * 这是「接入一个上游通道」的统一契约。核心业务（下单网关、回调处理、查单）只依赖本接口，
 * **不感知任何上游差异**；新增一个上游 = 新增一个实现类并在注册表登记，核心代码零改动。
 *
 * 四个核心能力（对应 README 3.1）：
 *  - createOrder：向上游发起下单，返回支付链接 / 上游单号；
 *  - parseNotify：解析上游异步回调报文为统一的支付状态；
 *  - verifyNotify：校验上游回调签名（防伪造）；
 *  - queryOrder：主动向上游查单（回调丢失时补偿）。
 *
 * 附加 successResponse：上游回调成功后平台需回应的「确认串」（各上游不一，如 success/SUCCESS/OK），
 * 供回调处理器在入账成功后原样输出给上游、终止其重推。
 */
interface ChannelAdapterInterface
{
    /**
     * 向上游发起下单
     *
     * @param CreateOrderRequest $request 标准下单入参
     * @return CreateOrderResult 下单结果（成功含支付链接/上游单号，失败含原因）
     */
    public function createOrder(CreateOrderRequest $request): CreateOrderResult;

    /**
     * 解析上游异步回调报文
     *
     * 仅做「报文 → 统一状态」的翻译，不做验签（验签交给 verifyNotify）、不落库、不入账，
     * 保持纯函数特性，便于单测与复用。
     *
     * @param array $payload 上游回调原始参数（已解析为数组）
     * @return PaymentStatusResult 统一支付状态（含平台订单号、金额、上游单号）
     */
    public function parseNotify(array $payload): PaymentStatusResult;

    /**
     * 校验上游回调签名
     *
     * @param array $payload 上游回调原始参数（含 sign）
     * @return bool 通过返回 true
     */
    public function verifyNotify(array $payload): bool;

    /**
     * 主动向上游查单（回调缺失时的补偿手段）
     *
     * @param string $orderNo    平台订单号（作为上游侧商户订单号）
     * @param string $upstreamNo 上游订单号（可空，部分上游支持以上游号查询）
     * @return PaymentStatusResult 统一支付状态
     */
    public function queryOrder(string $orderNo, string $upstreamNo = ''): PaymentStatusResult;

    /**
     * 上游回调处理成功后，平台应回应给上游的「确认串」
     *
     * @return string 确认串（如 success / SUCCESS / OK）
     */
    public function successResponse(): string;
}
