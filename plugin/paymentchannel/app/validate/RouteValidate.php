<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：综合路由验证器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\validate;

use plugin\saiadmin\basic\BaseValidate;

/**
 * 综合路由验证器
 */
class RouteValidate extends BaseValidate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        'title'    => 'require|max:100',
        'pay_type' => 'require|in:1,2,3,4,5,6,7',
        'status'   => 'require|in:1,2',
    ];

    /**
     * 错误信息
     * @var array
     */
    protected $message = [
        'title.require'    => '路由名称必须填写',
        'pay_type.require' => '支付类型必须选择',
        'pay_type.in'      => '支付类型值非法',
        'status.in'        => '状态值非法',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        'save'   => ['title', 'pay_type', 'status'],
        'update' => ['title', 'pay_type', 'status'],
    ];
}
