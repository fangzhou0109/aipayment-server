<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：综合路由模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 综合路由模型
 *
 * sa_pay_route 综合路由表（一个支付类型绑定多个上游通道）
 *
 * @property int    $id
 * @property string $title 路由名称
 * @property int    $pay_type 支付类型 (1-7)
 * @property string $rate 历史字段（代收不再使用，固定 0）
 * @property int    $status 状态 (1正常 2停用)
 */
class Route extends BaseModel
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
    protected $table = 'sa_pay_route';

    /**
     * 关键字搜索器：按路由名称模糊匹配
     * @param mixed $query
     * @param mixed $value
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->where('title', 'like', '%' . $value . '%');
        }
    }

    /**
     * 关联：路由下绑定的通道明细
     */
    public function routeChannels()
    {
        return $this->hasMany(RouteChannel::class, 'route_id', 'id');
    }
}
