<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户通知日志模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 商户通知日志模型
 *
 * sa_pay_notify_log 商户通知日志表（记录每次异步通知与重试）
 *
 * @property int    $id
 * @property string $order_no 关联平台单号
 * @property int    $merchant_id 商户ID
 * @property int    $retry_num 已重试次数
 * @property int    $status 状态 (0待通知 1成功 2失败)
 */
class NotifyLog extends BaseModel
{
    /** 通知类型：代收 */
    public const BIZ_PAY = 1;
    /** 通知类型：代付 */
    public const BIZ_TRANSFER = 2;

    /** 状态：待通知 */
    public const STATUS_PENDING = 0;
    /** 状态：成功 */
    public const STATUS_SUCCESS = 1;
    /** 状态：失败 */
    public const STATUS_FAILED = 2;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_notify_log';

    /**
     * 平台单号搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchOrderNoAttr($query, $value): void
    {
        if ($value) {
            $query->where('order_no', 'like', '%' . $value . '%');
        }
    }

    /**
     * 商户ID搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchMerchantIdAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('merchant_id', (int) $value);
        }
    }

    /**
     * 通知类型搜索器（1代收 2代付）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchBizTypeAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('biz_type', (int) $value);
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
