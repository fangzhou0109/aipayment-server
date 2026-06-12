<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户充值模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 商户充值模型
 *
 * sa_pay_recharge 商户充值表
 *
 * @property int    $id
 * @property string $recharge_no 平台充值单号
 * @property int    $merchant_id 商户ID
 * @property string $amount 充值金额（元）
 * @property int    $status 状态
 */
class Recharge extends BaseModel
{
    /** 状态：待审核 */
    public const STATUS_PENDING = 0;
    /** 状态：通过 */
    public const STATUS_APPROVED = 1;
    /** 状态：驳回 */
    public const STATUS_REJECTED = -1;

    /** 充值方式：余额充值 */
    public const TYPE_BALANCE = 1;
    /** 充值方式：转卡充值 */
    public const TYPE_CARD = 2;
    /** 充值方式：在线充值 */
    public const TYPE_ONLINE = 3;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_recharge';

    /**
     * 关键字搜索器：按充值单号模糊匹配
     * @param mixed $query
     * @param mixed $value
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->where('recharge_no', 'like', '%' . $value . '%');
        }
    }

    /**
     * 充值单号搜索器（模糊）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchRechargeNoAttr($query, $value): void
    {
        if ($value) {
            $query->where('recharge_no', 'like', '%' . $value . '%');
        }
    }

    /**
     * 充值方式搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchRechargeTypeAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('recharge_type', $value);
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
     * 商户搜索器：按商户ID精确匹配
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
     * 关联：所属商户
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }
}
