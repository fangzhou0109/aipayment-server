<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：支付商户模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 支付商户模型
 *
 * sa_pay_merchant 支付商户表
 *
 * @property int    $id
 * @property string $mch_id 商户号
 * @property string $name 商户名称
 * @property string $avatar 商户门户头像 URL
 * @property string $secret_key MD5 签名密钥
 * @property string $balance 可用余额（元，decimal 读出为字符串）
 * @property string $balance_freeze 冻结余额
 * @property string $rate 代收费率（历史/展示；Phase 9.1 起不参与代收下单计费，以 merchant_channel 为准）
 * @property string $rate_transfer 商户全局保底代付费率（%）；有代付通道时通道链优先，无通道或链均为 0 时用于提现 apply 算费
 * @property int    $status 状态 (1正常 2停用)
 */
class Merchant extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称（带 sa_ 前缀）
     * @var string
     */
    protected $table = 'sa_pay_merchant';

    /**
     * 隐藏敏感字段：密钥、密码、RSA 私钥不随列表/详情下发，避免泄露。
     * 注意：与基类默认隐藏的 delete_time 合并。
     * @var array
     */
    protected $hidden = ['delete_time', 'secret_key', 'rsa_private_key', 'password'];

    /**
     * 关键字搜索器：按商户号 / 名称 / 登录名模糊匹配
     * @param mixed $query
     * @param mixed $value
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->where('mch_id|name|login_name', 'like', '%' . $value . '%');
        }
    }

    /**
     * 商户号精确搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchMchIdAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('mch_id', $value);
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
     * 关联：商户的银行卡列表
     */
    public function bankCards()
    {
        return $this->hasMany(BankCard::class, 'merchant_id', 'id');
    }

    /**
     * 关联：商户的通道授权与代收费率定制
     */
    public function merchantChannels()
    {
        return $this->hasMany(MerchantChannel::class, 'merchant_id', 'id');
    }
}
