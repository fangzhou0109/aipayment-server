<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游渠道适配器工厂
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel;

use InvalidArgumentException;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;

/**
 * 上游渠道适配器工厂
 *
 * 把「适配器标识(code) + 通道凭证」解析为可用的适配器实例。核心业务（下单/回调/查单）
 * 只调本工厂拿到 {@see ChannelAdapterInterface}，**不出现任何具体适配器类名**，
 * 真正做到「新增上游 = 新增类 + 注册表登记，核心零改动」。
 */
class ChannelAdapterFactory
{
    /**
     * 按适配器标识与凭证创建适配器实例
     *
     * @param string $code 适配器标识（sa_pay_channel.adapter）
     * @param ChannelCredential $credential 上游凭证
     * @return ChannelAdapterInterface
     * @throws InvalidArgumentException 标识未注册或未实现时
     */
    public static function make(string $code, ChannelCredential $credential): ChannelAdapterInterface
    {
        $class = ChannelAdapterRegistry::resolveClass($code);
        if ($class === null) {
            throw new InvalidArgumentException('ChannelAdapterFactory: 适配器未注册或未实现 [' . $code . ']');
        }
        // 防御性校验：注册表登记的类必须实现统一接口，避免误配
        if (!is_subclass_of($class, ChannelAdapterInterface::class)) {
            throw new InvalidArgumentException('ChannelAdapterFactory: 适配器未实现接口 [' . $class . ']');
        }
        /** @var ChannelAdapterInterface $instance */
        $instance = new $class($credential);
        return $instance;
    }

    /**
     * 从通道数据（sa_pay_channel 一行）直接创建适配器
     *
     * @param array $channel 通道数据（须含 adapter 字段）
     * @return ChannelAdapterInterface
     * @throws InvalidArgumentException
     */
    public static function makeFromChannel(array $channel): ChannelAdapterInterface
    {
        $code = (string) ($channel['adapter'] ?? '');
        return self::make($code, ChannelCredential::fromArray($channel));
    }
}
