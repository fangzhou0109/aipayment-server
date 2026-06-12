<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：资金流水模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 资金流水模型
 *
 * sa_pay_capital_flow 资金流水表（不可变流水账，idempotent_key 唯一防重复记账）
 *
 * @property int    $id
 * @property string $flow_no 流水号
 * @property int    $merchant_id 商户ID
 * @property int    $biz_type 业务类型
 * @property string $change_amount 变动金额（元，正增负减）
 * @property string $before_balance 变动前余额（元）
 * @property string $after_balance 变动后余额（元）
 * @property string $idempotent_key 幂等键
 */
class CapitalFlow extends BaseModel
{
    /** 业务类型：代收入账 */
    public const BIZ_PAY_IN = 1;
    /** 业务类型：提现冻结 */
    public const BIZ_WITHDRAW_FREEZE = 2;
    /** 业务类型：提现扣款 */
    public const BIZ_WITHDRAW_DEDUCT = 3;
    /** 业务类型：提现退款 */
    public const BIZ_WITHDRAW_REFUND = 4;
    /** 业务类型：充值 */
    public const BIZ_RECHARGE = 5;
    /** 业务类型：手续费 */
    public const BIZ_FEE = 6;
    /** 业务类型：人工调整 */
    public const BIZ_ADJUST = 7;

    /** 账户类型：可用余额 */
    public const ACCOUNT_BALANCE = 1;
    /** 账户类型：冻结余额 */
    public const ACCOUNT_FREEZE = 2;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_capital_flow';

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
     * 业务单号搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchBizNoAttr($query, $value): void
    {
        if ($value) {
            $query->where('biz_no', $value);
        }
    }

    /**
     * 业务类型搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchBizTypeAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('biz_type', $value);
        }
    }

    /**
     * 账户类型搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchChangeTypeAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('change_type', $value);
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
