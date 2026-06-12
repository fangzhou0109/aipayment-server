<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游交互日志模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 上游交互日志模型
 *
 * sa_pay_channel_log 上游交互日志表（记录与上游通道的请求/响应，便于排障）
 *
 * @property int    $id
 * @property int    $channel_id 通道ID
 * @property string $biz_no 关联业务单号
 * @property int    $type 交互类型 (1下单 2回调 3查单 4代付)
 */
class ChannelLog extends BaseModel
{
    /** 交互类型：下单 */
    public const TYPE_CREATE = 1;
    /** 交互类型：回调 */
    public const TYPE_NOTIFY = 2;
    /** 交互类型：查单 */
    public const TYPE_QUERY = 3;
    /** 交互类型：代付 */
    public const TYPE_TRANSFER = 4;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_channel_log';

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
}
