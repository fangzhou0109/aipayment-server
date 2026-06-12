<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游代付适配器抽象基类
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer;

use plugin\paymentchannel\service\channel\ChannelHttpSignTrait;

/**
 * 上游代付（出款）适配器抽象基类
 *
 * 实现代付适配器统一契约 {@see TransferAdapterInterface}；HTTP / 签名 / 金额换算 / 交互日志等
 * 公共能力复用 {@see ChannelHttpSignTrait}（与代收适配器共享，避免重复）。具体适配器只需实现
 * createTransfer / parseTransferNotify / verifyNotify / queryTransfer 四个上游协议相关方法。
 *
 * 构造与凭证（{@see \plugin\paymentchannel\service\channel\dto\ChannelCredential}）由 Trait 提供。
 */
abstract class AbstractTransferAdapter implements TransferAdapterInterface
{
    use ChannelHttpSignTrait;
}
