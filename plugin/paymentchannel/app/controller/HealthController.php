<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：健康检查控制器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller;

use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;

/**
 * 健康检查控制器
 *
 * 作用：验证 paymentchannel 插件已被 webman 正确识别与加载、PSR-4 自动加载生效、
 *      且能跨插件复用 saiadmin 基类。继承 OpenController 以复用统一响应方法，无需登录态。
 */
class HealthController extends OpenController
{
    /**
     * 无需登录校验的方法白名单（被 saiadmin CheckLogin 中间件读取）
     * @var array
     */
    protected array $noNeedLogin = ['index'];

    /**
     * 健康检查端点
     * 路由：GET /pay/health
     *
     * @param Request $request 请求对象
     * @return Response 统一响应体 {code:200, message, data}
     */
    public function index(Request $request): Response
    {
        return $this->success([
            'plugin'  => 'paymentchannel',
            'version' => config('plugin.paymentchannel.app.version'),
            'time'    => date('Y-m-d H:i:s'),
        ], 'paymentchannel ok');
    }
}
