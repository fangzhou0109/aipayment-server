<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代收费率解析服务（Phase 9.1）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\app\model\Route;

/**
 * 代收费率解析服务
 *
 * 集中处理「平台侧费率」解析与利润底线校验，供下单网关、后台试算复用。
 *
 * 解析优先级（命中 channel_id 后；代收路由不再配置 route.rate）：
 *  1. merchant_channel.rate > 0（商户×通道独立费率）
 *  2. channel.rate_self（通道默认平台费率）
 *
 * 注意：merchant.rate **不参与**代收计费（Phase 9.1 起），本类不读取商户表 rate 字段。
 *
 * 代付费率（Phase 9.4.2 + 9.5 保底）：
 *  1. merchant_channel.rate_transfer > 0（须 transfer_enabled 已授权）
 *  2. merchant_channel.rate_transfer = 0 → 继承 channel.rate_transfer_self（含 0%=免手续费）
 *  3. 无绑定但有通道 → channel.rate_transfer_self（>0 时）
 *  4. merchant.rate_transfer（商户全局保底；无代付通道或通道链未命中正费率时，含 0%）
 *
 * 代付/提现允许费率为 0，不再因「全链为 0」拒单。
 *
 * 可测试性：DB 加载抽为 protected 接缝，单测以子类注入内存数据脱离数据库。
 */
class RateResolver
{
    /**
     * 解析代收费率（百分数字符串，4 位小数）
     *
     * @param int $merchantId 商户ID
     * @param int $channelId 通道ID
     * @param int $routeId 路由ID
     * @return string 平台费率（如 2.6000 表示 2.6%）
     * @throws PaymentException 三级费率均为 0 或未配置时
     */
    public function resolvePayRate(int $merchantId, int $channelId, int $routeId): string
    {
        return $this->resolvePayRateDetail($merchantId, $channelId, $routeId)['rate'];
    }

    /**
     * 解析代收费率及来源（建单快照用，Phase 9.3.4）
     *
     * @return array{rate:string,rate_source:string,merchant_channel_id:int}
     * @throws PaymentException
     */
    public function resolvePayRateDetail(int $merchantId, int $channelId, int $routeId): array
    {
        $merchantChannel = $this->loadMerchantChannel($merchantId, $channelId);
        $route = $this->loadRoute($routeId);
        $channel = $this->loadChannel($channelId);

        if ($channel === null) {
            throw new PaymentException('支付通道不存在');
        }

        return $this->pickPlatformRateDetail($merchantChannel, $route, $channel);
    }

    /**
     * 解析代付平台费率（百分数字符串，4 位小数）
     *
     * @throws PaymentException 通道不存在时
     */
    public function resolveTransferRate(int $merchantId, int $channelId): string
    {
        $merchantChannel = $this->loadMerchantChannelForTransfer($merchantId, $channelId);
        $channel = $this->loadChannel($channelId);

        if ($channel === null) {
            throw new PaymentException('代付通道不存在');
        }

        return $this->pickTransferRate($merchantChannel, $channel);
    }

    /**
     * 提现申请算费：支持无代付通道时回落商户全局 rate_transfer
     *
     * @param int $merchantId 商户 ID
     * @param int|null $channelId 默认代付通道；null 表示无可用通道，仅用商户全局费率
     * @param string $merchantGlobalRate 商户表 rate_transfer（百分数）
     */
    public function resolveTransferRateForApply(int $merchantId, ?int $channelId, string $merchantGlobalRate = '0'): string
    {
        if ($channelId === null || $channelId <= 0) {
            return AmountHelper::format($merchantGlobalRate);
        }

        $merchantChannel = $this->loadMerchantChannelForTransfer($merchantId, $channelId);
        $channel = $this->loadChannel($channelId);

        if ($channel === null) {
            return AmountHelper::format($merchantGlobalRate);
        }

        return $this->pickTransferRate($merchantChannel, $channel, $merchantGlobalRate);
    }

    /**
     * 校验平台费率须严格大于上游成本（channel.rate）
     *
     * @param string $resolvedRate 已解析的平台费率（%）
     * @param string $upstreamRate 上游成本费率（%）
     * @throws PaymentException 平台费率 ≤ 上游成本时
     */
    public function assertProfitable(string $resolvedRate, string $upstreamRate): void
    {
        $platform = AmountHelper::format($resolvedRate);
        $upstream = AmountHelper::format($upstreamRate);

        if (AmountHelper::compare($platform, $upstream) <= 0) {
            throw new PaymentException('平台费率须大于上游成本');
        }
    }

    /**
     * 代付平台费率优先级链（纯逻辑，便于单测；0% 为合法免手续费）
     */
    protected function pickTransferRate(?array $merchantChannel, ?array $channel, string $merchantGlobalRate = '0'): string
    {
        if ($merchantChannel !== null) {
            $mcRate = AmountHelper::format((string) ($merchantChannel['rate_transfer'] ?? '0'));
            if (AmountHelper::gtZero($mcRate)) {
                return $mcRate;
            }
            // rate_transfer=0：继承通道代付默认（含 0%）
            if ($channel !== null) {
                return AmountHelper::format((string) ($channel['rate_transfer_self'] ?? '0'));
            }
        }

        if ($channel !== null) {
            $selfRate = AmountHelper::format((string) ($channel['rate_transfer_self'] ?? '0'));
            if (AmountHelper::gtZero($selfRate)) {
                return $selfRate;
            }
        }

        return AmountHelper::format($merchantGlobalRate);
    }

    /**
     * 代收平台费率优先级链（纯逻辑，便于单测）
     *
     * @return array{rate:string,rate_source:string,merchant_channel_id:int}
     */
    protected function pickPlatformRateDetail(?array $merchantChannel, ?array $route, array $channel): array
    {
        // 1) 商户×通道独立费率
        if ($merchantChannel !== null) {
            $mcRate = AmountHelper::format((string) ($merchantChannel['rate'] ?? '0'));
            if (AmountHelper::gtZero($mcRate)) {
                return [
                    'rate'                => $mcRate,
                    'rate_source'         => Order::RATE_SOURCE_MERCHANT_CHANNEL,
                    'merchant_channel_id' => (int) ($merchantChannel['id'] ?? 0),
                ];
            }
        }

        // 2) 通道默认平台费率（route.rate 已废弃，不参与代收计费）
        $selfRate = AmountHelper::format((string) ($channel['rate_self'] ?? '0'));
        if (AmountHelper::gtZero($selfRate)) {
            return [
                'rate'                => $selfRate,
                'rate_source'         => Order::RATE_SOURCE_CHANNEL,
                'merchant_channel_id' => 0,
            ];
        }

        throw new PaymentException('平台费率未配置');
    }

    /**
     * 加载商户×通道授权记录（仅 status=正常）
     *
     * @param int $merchantId 商户ID
     * @param int $channelId 通道ID
     * @return array|null
     */
    protected function loadMerchantChannel(int $merchantId, int $channelId): ?array
    {
        $row = MerchantChannel::where('merchant_id', $merchantId)
            ->where('channel_id', $channelId)
            ->where('status', MerchantChannel::STATUS_NORMAL)
            ->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 加载商户×通道代付授权记录（须 transfer_enabled=已授权）
     */
    protected function loadMerchantChannelForTransfer(int $merchantId, int $channelId): ?array
    {
        $row = MerchantChannel::where('merchant_id', $merchantId)
            ->where('channel_id', $channelId)
            ->where('transfer_enabled', MerchantChannel::TRANSFER_ENABLED)
            ->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 加载路由记录
     *
     * @param int $routeId 路由ID
     * @return array|null
     */
    protected function loadRoute(int $routeId): ?array
    {
        $row = Route::where('id', $routeId)->find();
        return $row ? $row->toArray() : null;
    }

    /**
     * 加载通道记录（含上游成本 rate 与平台默认 rate_self）
     *
     * @param int $channelId 通道ID
     * @return array|null
     */
    protected function loadChannel(int $channelId): ?array
    {
        $row = Channel::where('id', $channelId)->find();
        return $row ? $row->toArray() : null;
    }
}
