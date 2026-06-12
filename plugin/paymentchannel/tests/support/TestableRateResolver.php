<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：可测试 RateResolver（内存注入，供网关/E2E 单测复用）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\support;

use plugin\paymentchannel\service\RateResolver;

/**
 * 可测试费率解析器：内存注入 merchant_channel / route / channel，脱离数据库。
 */
class TestableRateResolver extends RateResolver
{
    public ?array $merchantChannel = null;
    /** merchantId => channelId => 代付绑定行 */
    public array $merchantChannelTransfers = [];
    public ?array $route = null;
    public ?array $channel = null;

    protected function loadMerchantChannel(int $merchantId, int $channelId): ?array
    {
        return $this->merchantChannel;
    }

    protected function loadMerchantChannelForTransfer(int $merchantId, int $channelId): ?array
    {
        return $this->merchantChannelTransfers[$merchantId][$channelId] ?? null;
    }

    protected function loadRoute(int $routeId): ?array
    {
        return $this->route;
    }

    protected function loadChannel(int $channelId): ?array
    {
        return $this->channel;
    }
}
