<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户代付订单控制器（/mapi/transferOrder）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\WithdrawLogic;
use plugin\paymentchannel\app\model\Withdraw;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户门户代付订单控制器（/mapi/transferOrder）
 *
 * 商户自助查看「下游通过服务端 API 调代付（/pay/transfer）产生的代付订单」（source=2），
 * 与门户「提现」页（含人工提现 source=1）互补，仅做只读查询 + 自审操作。
 * **强制按 token 商户ID 过滤**，仅能查/操作自己的代付单（防越权）。
 */
class TransferOrderController extends BaseMerchantController
{
    /**
     * 代付订单列表（仅本商户的 API 代付单 source=2）
     * 路由：GET /mapi/transferOrder/index
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $where = $request->more([
            ['keyword', ''],
            ['withdraw_no', ''],
            ['out_biz_no', ''],
            ['status', ''],
            ['create_time', []],
        ]);
        // 强制注入商户ID + 来源（覆盖任何外部传入，杜绝越权与越界）
        $where['merchant_id'] = $merchantId;
        $where['source'] = Withdraw::SOURCE_API_TRANSFER;

        $logic = new WithdrawLogic();
        $query = $logic->search($where);
        return $this->success($logic->getList($query));
    }

    /**
     * 代付订单详情（仅本商户，按 ID + merchant_id + source 三重约束）
     * 路由：GET /mapi/transferOrder/read
     *
     * @param Request $request
     * @return Response
     */
    public function read(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $id = $request->input('id', '');
        $row = Withdraw::where('id', $id)
            ->where('merchant_id', $merchantId)
            ->where('source', Withdraw::SOURCE_API_TRANSFER)
            ->find();
        if (!$row) {
            return $this->fail('代付单不存在');
        }
        return $this->success($row->toArray());
    }

    /**
     * 商户自助审核代付单（审核下发 / 拒绝）
     * 路由：POST /mapi/transferOrder/audit
     *
     * 仅对本商户的「待审核」API 代付单生效，且须平台已开通「代付自审」开关。
     * disburse=审核下发（用平台已授权代付通道出款）｜ reject=拒绝（解冻退款）。
     *
     * @param Request $request { id, action(disburse|reject), remark? }
     * @return Response
     */
    public function audit(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请选择要审核的代付单');
        }
        $action = (string) $request->post('action', '');
        $remark = (string) $request->post('remark', '');

        try {
            $result = (new WithdrawLogic())->auditByMerchant($id, $merchantId, $action, $remark);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $messages = [
            'paying'     => '已提交代付，等待上游回调',
            'pay_failed' => '代付受理失败，已解冻退款',
            'rejected'   => '已拒绝并解冻退款',
        ];
        $msg = $messages[$result['result'] ?? ''] ?? '审核完成';
        return $this->success($result, $msg);
    }

}
