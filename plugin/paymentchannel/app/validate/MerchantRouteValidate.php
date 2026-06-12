<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-路由授权验证器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\validate;

use plugin\saiadmin\basic\BaseValidate;

/**
 * 商户-路由授权验证器
 */
class MerchantRouteValidate extends BaseValidate
{
    protected $rule = [
        'merchant_id' => 'require|integer|gt:0',
        'route_id'    => 'require|integer|gt:0',
        'status'      => 'require|in:1,2',
    ];

    protected $message = [
        'merchant_id.require' => '商户必须选择',
        'route_id.require'    => '路由必须选择',
        'status.in'           => '状态值非法',
    ];

    protected $scene = [
        'save'     => ['merchant_id', 'route_id', 'status'],
        'update'   => ['status'],
        'batchRow' => ['route_id', 'status'],
    ];
}
