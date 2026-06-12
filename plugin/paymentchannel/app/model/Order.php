<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代收订单模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 代收订单模型
 *
 * sa_pay_order 代收订单表
 *
 * @property int    $id
 * @property string $order_no 平台订单号
 * @property string $out_trade_no 商户订单号
 * @property string $upstream_no 上游订单号
 * @property int    $merchant_id 商户ID
 * @property string $amount 订单金额（元）
 * @property string $fee 手续费（元）
 * @property string $real_amount 实际入账金额（元）
 * @property string $rate 费率快照（百分数）
 * @property string $rate_source 费率来源（merchant_channel/route/channel）
 * @property int|null $merchant_channel_id 商户×通道绑定ID快照（来源为 merchant_channel 时）
 * @property int    $status 订单状态
 * @property int    $settle_status 入账状态
 */
class Order extends BaseModel
{
    /** 订单状态：待支付 */
    public const STATUS_PENDING = 0;
    /** 订单状态：已支付 */
    public const STATUS_PAID = 1;
    /** 订单状态：支付失败 */
    public const STATUS_FAILED = 2;
    /** 订单状态：已关闭（超时等） */
    public const STATUS_CLOSED = 3;

    /** 入账状态：未入账 */
    public const SETTLE_PENDING = 0;
    /** 入账状态：已入账 */
    public const SETTLE_DONE = 1;

    /** 费率来源：商户×通道定制费率（merchant_channel.rate > 0） */
    public const RATE_SOURCE_MERCHANT_CHANNEL = 'merchant_channel';
    /** 费率来源：路由费率（历史订单；新单不再使用 route.rate） */
    public const RATE_SOURCE_ROUTE = 'route';
    /** 费率来源：通道默认平台费率（channel.rate_self） */
    public const RATE_SOURCE_CHANNEL = 'channel';

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_order';

    /**
     * 关键字搜索器：按平台单号 / 商户单号 / 上游单号模糊匹配
     * @param mixed $query
     * @param mixed $value
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->where('order_no|out_trade_no|upstream_no', 'like', '%' . $value . '%');
        }
    }

    /**
     * 平台订单号搜索器（模糊）
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
     * 商户订单号搜索器（模糊）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchOutTradeNoAttr($query, $value): void
    {
        if ($value) {
            $query->where('out_trade_no', 'like', '%' . $value . '%');
        }
    }

    /**
     * 上游订单号搜索器（模糊）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchUpstreamNoAttr($query, $value): void
    {
        if ($value) {
            $query->where('upstream_no', 'like', '%' . $value . '%');
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
     * 代收通道搜索器（精确匹配 channel_id）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchChannelIdAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('channel_id', (int) $value);
        }
    }

    /**
     * 支付成功时间范围搜索器（数组为 [开始, 结束]）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchPayTimeAttr($query, $value): void
    {
        if (is_array($value) && count($value) === 2) {
            $query->whereBetween('pay_time', $value);
        } elseif ($value) {
            $query->where('pay_time', '=', $value);
        }
    }

    /**
     * 商户号搜索器（平台后台按 mch_id 冗余字段模糊查）
     * @param mixed $query
     * @param mixed $value
     */
    public function searchMchIdAttr($query, $value): void
    {
        if ($value) {
            $query->where('mch_id', 'like', '%' . $value . '%');
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
            $query->where('merchant_id', $value);
        }
    }

    /**
     * 订单状态搜索器
     * @param mixed $query
     * @param mixed $value
     */
    public function searchStatusAttr($query, $value): void
    {
        // 注意用 !== '' 判断，避免 status=0（待支付）被当成空值忽略
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

    /**
     * 关联：命中通道
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }
}
