<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户资金流水控制器（/mapi/capital）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\CapitalFlowLogic;
use support\Request;
use support\Response;

/**
 * 商户门户资金流水控制器（/mapi/capital）
 *
 * 商户自助查看本商户资金流水（只读 + 导出），merchant_id 强制取自 token。
 */
class CapitalFlowController extends BaseMerchantController
{
    /**
     * 资金流水列表（仅本商户）
     * 路由：GET /mapi/capital/index
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $where = $request->more([
            ['biz_no', ''],
            ['biz_type', ''],
            ['change_type', ''],
        ]);
        $where['merchant_id'] = $merchantId;

        $logic = new CapitalFlowLogic();
        $query = $logic->search($where);
        return $this->success($logic->getList($query));
    }

    /**
     * 导出本商户资金流水
     * 路由：POST /mapi/capital/export
     *
     * @param Request $request
     * @return Response
     */
    public function export(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $where = $request->more([
            ['biz_no', ''],
            ['biz_type', ''],
            ['change_type', ''],
        ]);
        $where['merchant_id'] = $merchantId;

        return (new CapitalFlowLogic())->export($where);
    }
}
