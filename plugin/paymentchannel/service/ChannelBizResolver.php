<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：通道业务能力（channel_biz）解析器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\service\channel\ChannelAdapterRegistry;
use plugin\paymentchannel\service\transfer\TransferAdapterRegistry;

/**
 * 通道业务能力解析器（Phase 9.5.1）
 *
 * 根据 adapter / transfer_adapter 是否在对应注册表且已实现，计算 channel_biz。
 * 保存通道时由 Logic 调用，禁止前端手填 channel_biz。
 */
class ChannelBizResolver
{
    /**
     * 根据通道行计算 channel_biz
     *
     * @param array $channel 含 adapter、transfer_adapter 等字段
     * @return int Channel::BIZ_*
     */
    public function resolve(array $channel): int
    {
        $pay = $this->isPayCapable($channel);
        $transfer = $this->isTransferCapable($channel);

        if ($pay && $transfer) {
            return Channel::BIZ_BOTH;
        }
        if ($pay) {
            return Channel::BIZ_PAY_ONLY;
        }
        if ($transfer) {
            return Channel::BIZ_TRANSFER_ONLY;
        }

        return Channel::BIZ_NONE;
    }

    /**
     * 是否具备代收能力（adapter 已注册且实现类可用）
     */
    public function isPayCapable(array $channel): bool
    {
        $code = trim((string) ($channel['adapter'] ?? ''));

        return $code !== ''
            && ChannelAdapterRegistry::exists($code)
            && ChannelAdapterRegistry::resolveClass($code) !== null;
    }

    /**
     * 是否具备代付能力（transfer_adapter 已注册且实现类可用；不回退 adapter）
     */
    public function isTransferCapable(array $channel): bool
    {
        $code = trim((string) ($channel['transfer_adapter'] ?? ''));

        return $code !== ''
            && TransferAdapterRegistry::exists($code)
            && TransferAdapterRegistry::resolveClass($code) !== null;
    }

    /**
     * 保存前归一化：剔除前端传入的 channel_biz，按字段重算
     *
     * @param array $data 待写入数据（可与库中既有行 merge 后传入）
     * @return array 含 channel_biz 的完整写入数据
     */
    public function applyToSaveData(array $data): array
    {
        unset($data['channel_biz']);
        $data['channel_biz'] = $this->resolve($data);

        return $data;
    }

    /**
     * 合并编辑补丁与库中既有行后重算 biz（保留双能力另一侧字段）
     *
     * @param array $patch 本次 update 字段
     * @param array $existing 库中当前行
     * @return array 合并后的通道行（用于 resolve）
     */
    public function mergeForUpdate(array $patch, array $existing): array
    {
        return array_merge($existing, $patch);
    }
}
