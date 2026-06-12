<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户提现管理控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\WithdrawLogic;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户提现管理控制器（平台后台 /core/pay/withdraw）
 *
 * 提现由商户门户发起、平台风控审核驱动，后台**不提供**手工增删改，
 * 仅开放列表(index)、详情(read)、审核(audit)。权限码 pay:withdraw:*。
 * 审核支持常规通过 / 代付下发 / 拒绝；已审核通过单可单独发起代付下发——全程资金安全由
 * {@see WithdrawLogic} 状态机保证。
 */
class WithdrawController extends BaseController
{
    /**
     * 构造：注入提现逻辑层
     */
    public function __construct()
    {
        $this->logic = new WithdrawLogic();
        parent::__construct();
    }

    /**
     * 提现列表
     * 路由：GET /core/pay/withdraw/index
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('提现数据列表', 'pay:withdraw:index')]
    public function index(Request $request): Response
    {
        // 搜索条件（搜索器在 Withdraw 模型中定义：keyword/merchant_id/status）
        $where = $request->more([
            ['keyword', ''],
            ['merchant_id', ''],
            ['status', ''],
        ]);
        $query = $this->logic->search($where);
        return $this->success($this->logic->getList($query));
    }

    /**
     * 提现详情
     * 路由：GET /core/pay/withdraw/read
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('提现数据读取', 'pay:withdraw:read')]
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
     * 路由：GET /core/pay/withdraw/transferChannels
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('提现审核', 'pay:withdraw:audit')]
    public function transferChannels(Request $request): Response
    {
        $merchantId = (int) $request->get('merchant_id', 0);

        return $this->success($this->logic->transferChannelOptions($merchantId));
    }

    /**
     * 审核提现
     * 路由：POST /core/pay/withdraw/audit
     *
     * action：pass=常规通过（财务线下已打款，扣冻结置成功）| disburse=代付下发 | reject=拒绝解冻退款。
     * disburse 时需传 channel_id（代付通道主键）。
     *
     * @param Request $request { id, action, remark, channel_id? }
     * @return Response
     */
    #[Permission('提现审核', 'pay:withdraw:audit')]
    public function audit(Request $request): Response
    {
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请选择要审核的提现单');
        }

        // action 优先；兼容旧版 approve 布尔入参
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
     * 对已审核通过的提现单发起代付下发
     * 路由：POST /core/pay/withdraw/disburse
     *
     * @param Request $request { id, channel_id }
     * @return Response
     */
    #[Permission('提现审核', 'pay:withdraw:audit')]
    public function disburse(Request $request): Response
    {
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请选择要下发的提现单');
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
