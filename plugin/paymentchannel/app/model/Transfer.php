<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付出款模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 代付出款模型
 *
 * sa_pay_transfer 代付出款表
 *
 * @property int    $id
 * @property string $transfer_no 平台代付单号
 * @property int    $merchant_id 商户ID
 * @property string $amount 代付金额（元）
 * @property int    $status 状态
 */
class Transfer extends BaseModel
{
    /** 状态：待处理 */
    public const STATUS_PENDING = 0;
    /** 状态：处理中 */
    public const STATUS_PROCESSING = 1;
    /** 状态：成功 */
    public const STATUS_SUCCESS = 2;
    /** 状态：失败 */
    public const STATUS_FAILED = 3;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_transfer';

    /**
     * 关键字搜索器：按平台单号 / 商户单号模糊匹配
     * @param mixed $query
     * @param mixed $value
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->where('transfer_no|out_trade_no|upstream_no', 'like', '%' . $value . '%');
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
