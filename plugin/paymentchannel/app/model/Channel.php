<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游支付通道模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 上游支付通道模型
 *
 * sa_pay_channel 上游支付通道表
 *
 * @property int    $id
 * @property string $title 通道名称
 * @property string $code 通道编码
 * @property string $adapter 代收适配器标识
 * @property string|null $transfer_adapter 代付适配器标识（Phase 9.5 起代付能力仅认本字段）
 * @property int    $channel_biz 业务能力：0未配置 1仅代收 2仅代付 3双能力
 * @property string $rate_transfer_self 代付平台默认费率（百分数）
 * @property int    $pay_type 支付类型 (1-7)
 * @property string|null $money_rule 直连选路金额规则（空=不限）
 * @property int    $status 状态 (1正常 2停用)
 */
class Channel extends BaseModel
{
    /** 未配置 / 两端适配器均不可用 */
    public const BIZ_NONE = 0;
    /** 仅代收 */
    public const BIZ_PAY_ONLY = 1;
    /** 仅代付 */
    public const BIZ_TRANSFER_ONLY = 2;
    /** 代收 + 代付双能力 */
    public const BIZ_BOTH = 3;

    /** 列表过滤：代收能力（含双能力） */
    public const BIZ_SCOPE_PAY = 'pay';
    /** 列表过滤：代付能力（含双能力） */
    public const BIZ_SCOPE_TRANSFER = 'transfer';
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_channel';

    /**
     * 隐藏上游密钥，避免随接口下发泄露
     * @var array
     */
    protected $hidden = ['delete_time', 'upstream_key', 'upstream_private_key'];

    /**
     * 关键字搜索器：按通道名称 / 编码模糊匹配
     * @param mixed $query
     * @param mixed $value
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->where('title|code', 'like', '%' . $value . '%');
        }
    }

    /**
     * 支付类型搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchPayTypeAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('pay_type', $value);
        }
    }

    /**
     * 状态搜索器
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
     * 业务能力搜索器：支持精确 biz 或 scope（pay / transfer）
     *
     * @param mixed $query
     * @param mixed $value int|string channel_biz 或 pay|transfer
     */
    public function searchChannelBizAttr($query, $value): void
    {
        if ($value === '' || $value === null) {
            return;
        }
        if ($value === self::BIZ_SCOPE_PAY || $value === 'pay') {
            $query->whereIn('channel_biz', [self::BIZ_PAY_ONLY, self::BIZ_BOTH]);

            return;
        }
        if ($value === self::BIZ_SCOPE_TRANSFER || $value === 'transfer') {
            $query->whereIn('channel_biz', [self::BIZ_TRANSFER_ONLY, self::BIZ_BOTH]);

            return;
        }
        $query->where('channel_biz', (int) $value);
    }
}
