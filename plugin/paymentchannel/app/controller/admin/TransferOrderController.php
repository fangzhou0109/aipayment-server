<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：API 代付订单管理控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\WithdrawLogic;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\Withdraw;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;
use Throwable;

/**
 * API 代付订单管理控制器（平台后台 /core/pay/transferOrder）
 *
 * 与「提现管理」物理拆分：本菜单只管 source=2 的「商户服务端 API 代付」单
 * （下游调 /pay/transfer 进单），提现管理只管 source=1 的商户门户人工提现。
 * 二者共用底层 sa_pay_withdraw 表与 {@see WithdrawLogic} 状态机，权限码独立 pay:transferOrder:*。
 * 后台不提供手工增删改，仅开放列表(index)、详情(read)、审核(audit)、下发(disburse)。
 */
class TransferOrderController extends BaseController
{
    /**
     * 构造：注入提现（代付）逻辑层
     */
    public function __construct()
    {
        $this->logic = new WithdrawLogic();
        parent::__construct();
    }

    /**
     * 代付订单列表（仅 API 代付，source=2）
     * 路由：GET /core/pay/transferOrder/index
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('代付订单列表', 'pay:transferOrder:index')]
    public function index(Request $request): Response
    {
        // 搜索条件（Withdraw 模型搜索器：keyword/out_biz_no/merchant_id/status）
        $where = $request->more([
            ['keyword', ''],
            ['out_biz_no', ''],
            ['merchant_id', ''],
            ['status', ''],
        ]);
        // 本菜单仅管「API 代付」单
        $where['source'] = Withdraw::SOURCE_API_TRANSFER;
        $query = $this->logic->search($where);
        // 「代付自审」商户的单平台不再管：从平台审核列表中排除（transfer_self_audit=1）
        $selfAuditIds = Merchant::where('transfer_self_audit', 1)->column('id');
        if (!empty($selfAuditIds)) {
            $query->whereNotIn('merchant_id', $selfAuditIds);
        }
        return $this->success($this->logic->getList($query));
    }

    /**
     * 代付订单详情
     * 路由：GET /core/pay/transferOrder/read
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('代付订单读取', 'pay:transferOrder:read')]
    public function read(Request $request): Response
    {
        $model = $this->logic->read($request->input('id', ''));
        if ($model) {
            return $this->success(is_array($model) ? $model : $model->toArray());
        }
        return $this->fail('未查找到信息');
    }

    /**
     * 可选代付通道列表（审核「代付下发」时使用）
     * 路由：GET /core/pay/transferOrder/transferChannels
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('代付订单审核', 'pay:transferOrder:audit')]
    public function transferChannels(Request $request): Response
    {
        $merchantId = (int) $request->get('merchant_id', 0);

        return $this->success($this->logic->transferChannelOptions($merchantId));
    }

    /**
     * 审核代付订单
     * 路由：POST /core/pay/transferOrder/audit
     *
     * action：pass=常规通过（财务线下已打款，扣冻结置成功）| disburse=代付下发 | reject=拒绝解冻退款。
     * disburse 时需传 channel_id（代付通道主键）。出款成功/失败均异步回调下游 notify_url。
     *
     * @param Request $request { id, action, remark, channel_id? }
     * @return Response
     */
    #[Permission('代付订单审核', 'pay:transferOrder:audit')]
    public function audit(Request $request): Response
    {
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请选择要审核的代付单');
        }

        $action = (string) $request->post('action', '');
        if ($action === '') {
            $approve = filter_var($request->post('approve', false), FILTER_VALIDATE_BOOLEAN);
            $action = $approve ? 'disburse' : 'reject';
        }

        $remark = (string) $request->post('remark', '');
        $channelId = (int) $request->post('channel_id', 0);
        $auditBy = (int) (getCurrentInfo()['id'] ?? 0);

        try {
            $result = $this->logic->audit($id, $action, $auditBy, $remark, $channelId);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $messages = [
            'success'    => '常规通过，已确认线下打款完成',
            'paying'     => '已提交代付，等待回调',
            'pay_failed' => '代付受理失败，已解冻退款',
            'rejected'   => '已拒绝并解冻退款',
        ];
        $msg = $messages[$result['result'] ?? ''] ?? '审核完成';
        return $this->success($result, $msg);
    }

    /**
     * 对已审核通过的代付单发起代付下发
     * 路由：POST /core/pay/transferOrder/disburse
     *
     * @param Request $request { id, channel_id }
     * @return Response
     */
    #[Permission('代付订单审核', 'pay:transferOrder:audit')]
    public function disburse(Request $request): Response
    {
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请选择要下发的代付单');
        }
        $channelId = (int) $request->post('channel_id', 0);

        try {
            $result = $this->logic->disburseById($id, $channelId);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $messages = [
            'paying'     => '已提交代付，等待回调',
            'pay_failed' => '代付受理失败，已解冻退款',
        ];
        $msg = $messages[$result['result'] ?? ''] ?? '下发完成';
        return $this->success($result, $msg);
    }
}
