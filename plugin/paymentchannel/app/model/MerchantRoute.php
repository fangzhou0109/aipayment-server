<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-路由授权模型
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\model;

use plugin\saiadmin\basic\think\BaseModel;

/**
 * 商户-路由授权模型
 *
 * sa_pay_merchant_route：代收**路由白名单**（可选）。
 *
 * Phase 9.3.1：商户无任何 merchant_route 记录时，下单仍遍历全部启用 Route；
 * 一旦存在启用记录，则 resolveChannel 仅限授权 route_id 集合。
 *
 * @property int $id
 * @property int $merchant_id 商户ID
 * @property int $route_id 路由ID
 * @property int $status 授权状态
 */
class MerchantRoute extends BaseModel
{
    /** 已授权（可用于路由选路白名单） */
    public const STATUS_NORMAL = 1;
    /** 停用（不参与授权集合） */
    public const STATUS_DISABLED = 2;

    protected $pk = 'id';
    protected $table = 'sa_pay_merchant_route';

    /**
     * 商户ID搜索器
     */
    public function searchMerchantIdAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('merchant_id', $value);
        }
    }

    /**
     * 路由ID搜索器
     */
    public function searchRouteIdAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('route_id', $value);
        }
    }

    /**
     * 授权状态搜索器
     */
    public function searchStatusAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'id');
    }
}
