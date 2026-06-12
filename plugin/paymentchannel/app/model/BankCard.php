<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户银行卡模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 商户银行卡模型
 *
 * sa_pay_bank_card 商户银行卡表
 *
 * @property int    $id
 * @property int    $merchant_id 商户ID
 * @property string $holder_name 持卡人姓名
 * @property string $card_no 银行卡号
 * @property int    $status 状态 (1正常 2停用)
 */
class BankCard extends BaseModel
{
    /** 正常（可用于提现） */
    public const STATUS_NORMAL = 1;
    /** 停用（不可用于提现） */
    public const STATUS_DISABLED = 2;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_bank_card';

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
     * 关键字搜索器：按持卡人姓名 / 卡号模糊匹配
     * @param mixed $query
     * @param mixed $value
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->where('holder_name|card_no', 'like', '%' . $value . '%');
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
     * 关联：所属商户
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }
}
