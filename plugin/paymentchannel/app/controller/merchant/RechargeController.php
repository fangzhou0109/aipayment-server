<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户充值控制器（/mapi/recharge）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\RechargeLogic;
use plugin\paymentchannel\app\model\Recharge;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户门户充值控制器（/mapi/recharge）
 *
 * 商户自助：发起充值申请（线下转账等）+ 查自己的充值列表/详情。申请复用
 * {@see RechargeLogic::apply}（建待审核单，不动余额），平台审核通过后入账（Phase 4.3）。
 * **强制按 token 商户ID 过滤**。
 */
class RechargeController extends BaseMerchantController
{
    /**
     * 充值列表（仅本商户）
     * 路由：GET /mapi/recharge/index
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $where = $request->more([
            ['keyword', ''],
            ['recharge_no', ''],
            ['recharge_type', ''],
            ['status', ''],
            ['create_time', []],
        ]);
        $where['merchant_id'] = $merchantId;

        $logic = new RechargeLogic();
        $query = $logic->search($where);
        return $this->success($logic->getList($query));
    }

    /**
     * 发起充值申请
     * 路由：POST /mapi/recharge/apply
     *
     * @param Request $request { amount(元), recharge_type(1余额/2转卡/3在线), remark }
     * @return Response
     */
    public function apply(Request $request): Response
    {
        $merchant = $this->loadMerchant($request);
        $params = [
            'amount'        => $request->post('amount', '0'),
            'recharge_type' => $request->post('recharge_type', 1),
            'remark'        => $request->post('remark', ''),
        ];
        try {
            $result = (new RechargeLogic())->apply($merchant, $params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success($result, '充值申请已提交，等待审核');
    }

    /**
     * 充值详情（仅本商户）
     * 路由：GET /mapi/recharge/read
     *
     * @param Request $request
     * @return Response
     */
    public function read(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $id = $request->input('id', '');
        $row = Recharge::where('id', $id)->where('merchant_id', $merchantId)->find();
        if (!$row) {
            return $this->fail('充值单不存在');
        }
        return $this->success($row->toArray());
    }
}
