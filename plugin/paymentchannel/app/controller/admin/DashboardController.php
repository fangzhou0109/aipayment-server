<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：平台后台工作台统计控制器（/core/pay/dashboard）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\AdminDashboardLogic;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 平台后台工作台统计控制器（/core/pay/dashboard）
 *
 * 返回全平台经营概览、资金池、待办与趋势（只读）。
 */
class DashboardController extends BaseController
{
    /**
     * 工作台统计
     * 路由：GET /core/pay/dashboard/stats
     *
     * 复用工作台菜单权限 core:console:list，登录即可查看经营数据。
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('工作台统计', 'core:console:list')]
    public function stats(Request $request): Response
    {
        $data = (new AdminDashboardLogic())->stats();
        return $this->success($data);
    }
}
