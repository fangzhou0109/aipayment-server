<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-通道授权验证器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\validate;

use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\service\AmountHelper;
use plugin\saiadmin\basic\BaseValidate;

/**
 * 商户-通道授权与费率验证器
 *
 * rate=0 表示继承通道 rate_self；rate>0 时须严格大于通道上游成本 channel.rate。
 */
class MerchantChannelValidate extends BaseValidate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        'merchant_id' => 'require|integer|gt:0',
        'channel_id'  => 'require|integer|gt:0',
        'rate'             => 'float|between:0,100|checkRateProfit',
        'rate_transfer'    => 'float|between:0,100',
        'day_limit'   => 'egt:0',
        'single_min'  => 'egt:0',
        'single_max'  => 'egt:0|checkSingleLimitRange',
        'status'           => 'require|in:1,2',
        'transfer_enabled' => 'in:1,2',
    ];

    /**
     * 错误信息
     * @var array
     */
    protected $message = [
        'merchant_id.require' => '商户必须选择',
        'channel_id.require'  => '通道必须选择',
        'rate.between'             => '代收费率需在 0~100 之间',
        'rate_transfer.between'    => '代付费率需在 0~100 之间',
        'day_limit.egt'       => '日限额不能为负',
        'single_min.egt'      => '单笔最小金额不能为负',
        'single_max.egt'      => '单笔最大金额不能为负',
        'status.in'                => '代收授权状态值非法',
        'transfer_enabled.in'      => '代付授权状态值非法',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        'save'      => ['merchant_id', 'channel_id', 'rate', 'rate_transfer', 'day_limit', 'single_min', 'single_max', 'status', 'transfer_enabled'],
        'update'    => ['rate', 'rate_transfer', 'day_limit', 'single_min', 'single_max', 'status', 'transfer_enabled'],
        'batchRow'         => ['channel_id', 'rate', 'rate_transfer', 'day_limit', 'single_min', 'single_max', 'status', 'transfer_enabled'],
        // Phase 9.5.3：商户代付批量保存仅校验代付字段
        'batchTransferRow' => ['channel_id', 'rate_transfer', 'transfer_enabled'],
    ];

    /**
     * 自定义规则：平台费率须大于上游成本（rate=0 继承通道默认，跳过）
     *
     * @param mixed $value 平台费率（%）
     * @param mixed $rule 规则
     * @param array $data 待校验数据（须含 channel_id）
     * @return bool|string
     */
    protected function checkRateProfit($value, $rule, array $data = []): bool|string
    {
        $rate = AmountHelper::format((string) $value);
        if (!AmountHelper::gtZero($rate)) {
            return true;
        }

        $channelId = $this->resolveChannelIdForRateCheck($data);
        if ($channelId <= 0) {
            return '通道必须选择';
        }

        $channel = $this->loadChannelForRateCheck($channelId);
        if ($channel === null) {
            return '通道不存在';
        }

        $upstream = AmountHelper::format((string) ($channel['rate'] ?? '0'));
        if (AmountHelper::compare($rate, $upstream) <= 0) {
            return '平台费率须大于通道上游成本';
        }

        return true;
    }

    /**
     * 自定义规则：单笔上下限区间合法（均为 0 表示不限；均 >0 时 max 须 ≥ min）
     *
     * @param mixed $value single_max 字段值
     * @param mixed $rule 规则
     * @param array $data 待校验数据（须含 single_min）
     * @return bool|string
     */
    protected function checkSingleLimitRange($value, $rule, array $data = []): bool|string
    {
        $min = AmountHelper::format((string) ($data['single_min'] ?? '0'));
        $max = AmountHelper::format((string) ($value ?? '0'));

        if (AmountHelper::gtZero($min) && AmountHelper::gtZero($max)
            && AmountHelper::compare($max, $min) < 0) {
            return '单笔最大限额不能小于最小限额';
        }

        return true;
    }

    /**
     * 解析待校验的 channel_id（save 直接取；update 可按 id 反查绑定）
     *
     * @param array $data 待校验数据
     * @return int
     */
    protected function resolveChannelIdForRateCheck(array $data): int
    {
        $channelId = (int) ($data['channel_id'] ?? 0);
        if ($channelId > 0) {
            return $channelId;
        }

        $bindingId = (int) ($data['id'] ?? 0);
        if ($bindingId <= 0) {
            return 0;
        }

        return $this->loadBindingChannelId($bindingId);
    }

    /**
     * 按绑定主键查 channel_id（接缝，单测可重写）
     *
     * @param int $bindingId merchant_channel.id
     * @return int
     */
    protected function loadBindingChannelId(int $bindingId): int
    {
        $row = MerchantChannel::where('id', $bindingId)->field('channel_id')->find();
        return (int) ($row['channel_id'] ?? 0);
    }

    /**
     * 加载通道上游成本（接缝，单测可重写）
     *
     * @param int $channelId 通道ID
     * @return array|null 含 rate 字段
     */
    protected function loadChannelForRateCheck(int $channelId): ?array
    {
        $row = Channel::where('id', $channelId)->field('id,rate')->find();
        return $row ? $row->toArray() : null;
    }
}
