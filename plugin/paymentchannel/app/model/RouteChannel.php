<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：路由-通道关联模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 路由-通道关联模型
 *
 * sa_pay_route_channel 路由-通道关联表（含金额规则与权重）
 *
 * @property int    $id
 * @property int    $route_id 路由ID
 * @property int    $channel_id 通道ID
 * @property string $money_rule 金额规则（范围或固定池）
 * @property int    $weight 权重
 */
class RouteChannel extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sa_pay_route_channel';

    /**
     * 关联：所属路由
     */
    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'id');
    }

    /**
     * 关联：所属通道
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }
}
