<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户首页统计控制器（/mapi/dashboard）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\MerchantDashboardLogic;
use support\Request;
use support\Response;

/**
 * 商户门户首页统计控制器（/mapi/dashboard）
 *
 * 返回当前商户的账户余额与今日经营概览（只读，强制按 token 商户ID 过滤）。
 */
class DashboardController extends BaseMerchantController
{
    /**
     * 首页统计
     * 路由：GET /mapi/dashboard/stats
     *
     * @param Request $request
     * @return Response
     */
    public function stats(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $data = (new MerchantDashboardLogic())->stats($merchantId);
        return $this->success($data);
    }
}
