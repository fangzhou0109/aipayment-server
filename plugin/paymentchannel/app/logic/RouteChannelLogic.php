<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：路由-通道关联逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\RouteChannel;
/**
 * 路由-通道关联逻辑层
 *
 * 维护「一条路由下绑定哪些通道」及其金额规则与权重。复用 PaymentBaseLogic CRUD。
 */
class RouteChannelLogic extends PaymentBaseLogic
{
    /**
     * 构造函数：注入路由-通道模型
     */
    public function __construct()
    {
        $this->model = new RouteChannel();
    }
}
