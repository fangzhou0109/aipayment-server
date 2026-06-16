<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户提现模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 商户提现模型
 *
 * sa_pay_withdraw 商户提现表
 *
 * @property int    $id
 * @property string $withdraw_no 平台提现单号
 * @property string|null $out_biz_no 商户代付单号（API 幂等键）
 * @property int    $merchant_id 商户ID
 * @property int    $source 单据来源（1商户提现 2API代付）
 * @property int|null $bank_card_id 收款银行卡ID（关联用，展示/代付以快照字段为准）
 * @property string|null $account_name 收款人姓名（申请时快照）
 * @property string|null $account_no 收款银行卡号（申请时快照）
 * @property string|null $bank_name 开户银行（申请时快照）
 * @property string|null $bank_code 银行编码（申请时快照）
 * @property string|null $branch_name 开户支行（申请时快照）
 * @property string|null $notify_url 下游商户异步通知地址（API 代付）
 * @property string $amount 提现金额（元）
 * @property string $fee 提现手续费（元）
 * @property int    $status 状态
 */
class Withdraw extends BaseModel
{
    /** 状态：待审核 */
    public const STATUS_PENDING = 0;
    /** 状态：审核通过 */
    public const STATUS_APPROVED = 1;
    /** 状态：代付中 */
    public const STATUS_PAYING = 2;
    /** 状态：成功 */
    public const STATUS_SUCCESS = 3;
    /** 状态：审核拒绝 */
    public const STATUS_REJECTED = -1;
    /** 状态：代付失败 */
    public const STATUS_PAY_FAILED = -2;

    /** 来源：商户门户人工提现 */
    public const SOURCE_WITHDRAW = 1;
    /** 来源：商户服务端 API 代付下单 */
    public const SOURCE_API_TRANSFER = 2;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_withdraw';

    /**
     * 关键字搜索器：按提现单号模糊匹配
     * @param mixed $query
     * @param mixed $value
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->where('withdraw_no', 'like', '%' . $value . '%');
        }
    }

    /**
     * 提现单号搜索器（模糊）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchWithdrawNoAttr($query, $value): void
    {
        if ($value) {
            $query->where('withdraw_no', 'like', '%' . $value . '%');
        }
    }

    /**
     * 商户代付单号搜索器（精确）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchOutBizNoAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('out_biz_no', $value);
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

    /**
     * 关联：收款银行卡
     */
    public function bankCard()
    {
        return $this->belongsTo(BankCard::class, 'bank_card_id', 'id');
    }
}
