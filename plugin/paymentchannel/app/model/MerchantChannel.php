<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-通道授权与代收费率定制模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 商户-通道授权与代收费率定制模型
 *
 * sa_pay_merchant_channel：商户×通道**代收 + 代付**授权与费率定制（同表，开关独立）。
 *
 * Phase 9.1 严格模式：商户须至少有一条 status=STATUS_NORMAL 的绑定方可代收下单。
 * Phase 9.4.1：代付授权由 transfer_enabled 控制（与 status 独立）；运行时接入见 9.4.2。
 *
 * 代收费率语义：
 *  - rate = RATE_INHERIT(0) ：继承 sa_pay_channel.rate_self
 *  - rate > 0             ：商户×通道独立代收平台费率（须 > 通道上游成本 channel.rate）
 *
 * 代付费率语义（Phase 9.4）：
 *  - rate_transfer = 0    ：继承 sa_pay_channel.rate_transfer_self
 *  - rate_transfer > 0    ：商户×通道独立代付平台费率
 *
 * @property int    $id
 * @property int    $merchant_id 商户ID
 * @property int    $channel_id 通道ID
 * @property string $rate 代收平台费率（百分数；0=继承通道默认）
 * @property string $rate_transfer 代付平台费率（百分数；0=继承通道 rate_transfer_self）
 * @property string $day_limit 日限额（元，0=不限，9.2.3 运行时接入）
 * @property string $single_min 单笔最小金额（元，0=不限，9.2.2 运行时接入）
 * @property string $single_max 单笔最大金额（元，0=不限，9.2.2 运行时接入）
 * @property int    $status 代收授权状态
 * @property int    $transfer_enabled 代付授权状态
 */
class MerchantChannel extends BaseModel
{
    /** 费率：0 表示继承通道 rate_self（非「免费」） */
    public const RATE_INHERIT = '0.0000';

    /** 单笔限额：0 表示不限（与 merchant.single_min/max 语义一致） */
    public const LIMIT_UNLIMITED = '0.0000';

    /** 已授权（可用于代收选路白名单） */
    public const STATUS_NORMAL = 1;
    /** 停用（不参与代收授权集合） */
    public const STATUS_DISABLED = 2;

    /** 代付已授权（Phase 9.4.1；运行时选路见 9.4.2） */
    public const TRANSFER_ENABLED = 1;
    /** 代付停用 */
    public const TRANSFER_DISABLED = 2;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_merchant_channel';

    /**
     * 商户ID搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchMerchantIdAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('merchant_id', $value);
        }
    }

    /**
     * 通道ID搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchChannelIdAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('channel_id', $value);
        }
    }

    /**
     * 授权状态搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchStatusAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }

    /**
     * 代付授权状态搜索器
     */
    public function searchTransferEnabledAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('transfer_enabled', $value);
        }
    }

    /**
     * 关联：所属商户
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    /**
     * 关联：所属通道
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }
}
