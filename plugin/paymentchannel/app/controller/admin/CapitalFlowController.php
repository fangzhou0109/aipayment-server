<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：资金流水管理控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\CapitalFlowLogic;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 资金流水管理控制器（平台后台 /core/pay/capital）
 *
 * 资金流水为不可变流水账，**只读 + 导出**，不提供增删改。权限码 pay:capital:*。
 */
class CapitalFlowController extends BaseController
{
    /**
     * 构造：注入资金流水逻辑层
     */
    public function __construct()
    {
        $this->logic = new CapitalFlowLogic();
        parent::__construct();
    }

    /**
     * 资金流水列表
     * 路由：GET /core/pay/capital/index
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('资金流水列表', 'pay:capital:index')]
    public function index(Request $request): Response
    {
        // 搜索条件（搜索器在 CapitalFlow 模型中定义：merchant_id/biz_no/biz_type/change_type）
        $where = $request->more([
            ['merchant_id', ''],
            ['biz_no', ''],
            ['biz_type', ''],
            ['change_type', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 导出资金流水为 Excel
     * 路由：POST /core/pay/capital/export
     *
     * @param Request $request
     * @return Response 文件下载
     */
    #[Permission('资金流水导出', 'pay:capital:export')]
    public function export(Request $request): Response
    {
        $where = $request->more([
            ['merchant_id', ''],
            ['biz_no', ''],
            ['biz_type', ''],
            ['change_type', ''],
        ]);
        return $this->logic->export($where);
    }
}
