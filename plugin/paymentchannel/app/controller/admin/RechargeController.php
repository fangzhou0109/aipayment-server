<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户充值管理控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\RechargeLogic;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户充值管理控制器（平台后台 /core/pay/recharge）
 *
 * 充值由商户门户发起、平台审核入账驱动，后台**不提供**手工增删改，
 * 仅开放列表(index)、详情(read)、审核(audit)。权限码 pay:recharge:*。
 * 审核通过会把金额计入商户可用余额并写资金流水（事务一致）。
 */
class RechargeController extends BaseController
{
    /**
     * 构造：注入充值逻辑层
     */
    public function __construct()
    {
        $this->logic = new RechargeLogic();
        parent::__construct();
    }

    /**
     * 充值列表
     * 路由：GET /core/pay/recharge/index
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('充值数据列表', 'pay:recharge:index')]
    public function index(Request $request): Response
    {
        // 搜索条件（搜索器在 Recharge 模型中定义：keyword/merchant_id/status）
        $where = $request->more([
            ['keyword', ''],
            ['merchant_id', ''],
            ['status', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 充值详情
     * 路由：GET /core/pay/recharge/read
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('充值数据读取', 'pay:recharge:read')]
    public function read(Request $request): Response
    {
        $model = $this->logic->read($request->input('id', ''));
        if ($model) {
            return $this->success(is_array($model) ? $model : $model->toArray());
        }
        return $this->fail('未查找到信息');
    }

    /**
     * 审核充值（通过 / 驳回）
     * 路由：POST /core/pay/recharge/audit
     *
     * 通过：把金额计入商户可用余额并写流水；驳回：不动余额。
     * 资金操作由逻辑层在事务内完成，控制器只负责取参与错误转译。
     *
     * @param Request $request { id, approve(1通过/0驳回), remark }
     * @return Response
     */
    #[Permission('充值审核', 'pay:recharge:audit')]
    public function audit(Request $request): Response
    {
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请选择要审核的充值单');
        }
        // approve：兼容 1/0、true/false、"true"/"false" 入参
        $approve = filter_var($request->post('approve', false), FILTER_VALIDATE_BOOLEAN);
        $remark = (string) $request->post('remark', '');
        $auditBy = (int) (getCurrentInfo()['id'] ?? 0);

        try {
            $result = $this->logic->audit($id, $approve, $auditBy, $remark);
        } catch (Throwable $e) {
            // 状态/商户等业务异常统一转失败响应（code=400）
            return $this->fail($e->getMessage());
        }
        return $this->success($result, '审核完成');
    }
}
