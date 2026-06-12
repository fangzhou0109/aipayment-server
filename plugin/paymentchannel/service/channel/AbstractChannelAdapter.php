<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游渠道适配器抽象基类
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel;

/**
 * 上游渠道（代收）适配器抽象基类
 *
 * 实现代收适配器统一契约 {@see ChannelAdapterInterface}；HTTP / 签名 / 金额换算 / 交互日志等
 * 公共能力复用 {@see ChannelHttpSignTrait}（与代付适配器共享，避免重复）。具体适配器只需实现
 * createOrder / parseNotify / verifyNotify / queryOrder 四个上游协议相关方法。
 */
abstract class AbstractChannelAdapter implements ChannelAdapterInterface
{
    use ChannelHttpSignTrait;
}
