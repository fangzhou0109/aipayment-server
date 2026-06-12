<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游代付适配器工厂
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer;

use InvalidArgumentException;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;

/**
 * 上游代付适配器工厂
 *
 * 把「代付适配器标识(code) + 通道凭证」解析为可用的代付适配器实例。核心业务（代付/提现下发、
 * 代付回调、查单）只调本工厂拿到 {@see TransferAdapterInterface}，**不出现任何具体适配器类名**，
 * 做到「新增代付上游 = 新增类 + 注册表登记，核心零改动」。
 *
 * 凭证复用 {@see ChannelCredential}（与代收共用，字段含网关地址 + 上游密钥）。
 */
class TransferAdapterFactory
{
    /**
     * 按代付适配器标识与凭证创建适配器实例
     *
     * @param string $code 适配器标识
     * @param ChannelCredential $credential 上游凭证
     * @return TransferAdapterInterface
     * @throws InvalidArgumentException 标识未注册或未实现时
     */
    public static function make(string $code, ChannelCredential $credential): TransferAdapterInterface
    {
        $class = TransferAdapterRegistry::resolveClass($code);
        if ($class === null) {
            throw new InvalidArgumentException('TransferAdapterFactory: 代付适配器未注册或未实现 [' . $code . ']');
        }
        // 防御性校验：注册表登记的类必须实现统一接口，避免误配
        if (!is_subclass_of($class, TransferAdapterInterface::class)) {
            throw new InvalidArgumentException('TransferAdapterFactory: 代付适配器未实现接口 [' . $class . ']');
        }
        /** @var TransferAdapterInterface $instance */
        $instance = new $class($credential);
        return $instance;
    }

    /**
     * 从通道数据直接创建代付适配器（Phase 9.5.4：仅认 transfer_adapter）
     *
     * @param array $channel 通道数据（须含非空 transfer_adapter）
     * @return TransferAdapterInterface
     * @throws InvalidArgumentException 未配置代付适配器时
     */
    public static function makeFromChannel(array $channel): TransferAdapterInterface
    {
        $code = trim((string) ($channel['transfer_adapter'] ?? ''));
        if ($code === '') {
            throw new InvalidArgumentException(
                'TransferAdapterFactory: 通道未配置代付适配器 transfer_adapter [' . ($channel['code'] ?? '') . ']'
            );
        }

        return self::make($code, ChannelCredential::fromArray($channel));
    }
}
