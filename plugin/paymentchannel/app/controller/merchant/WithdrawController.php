<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户提现控制器（/mapi/withdraw）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\WithdrawLogic;
use plugin\paymentchannel\app\model\Withdraw;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户门户提现控制器（/mapi/withdraw）
 *
 * 商户自助：发起提现申请 + 查自己的提现列表/详情。申请复用 {@see WithdrawLogic::apply}
 * （事务冻结余额、建待审核单），后续审核/下发/回调由平台与代付回调网关驱动（Phase 4.2）。
 * **强制按 token 商户ID 过滤**，仅能操作自己的数据。
 */
class WithdrawController extends BaseMerchantController
{
    /**
     * 提现列表（仅本商户）
     * 路由：GET /mapi/withdraw/index
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
            ['status', ''],
            ['create_time', []],
        ]);
        $where['merchant_id'] = $merchantId;

        $logic = new WithdrawLogic();
        $query = $logic->search($where);
        return $this->success($logic->getList($query));
    }

    /**
     * 发起提现申请
     * 路由：POST /mapi/withdraw/apply
     *
     * @param Request $request { amount(元), bank_card_id }
     * @return Response
     */
    public function apply(Request $request): Response
    {
        // 商户上下文取自 token（算费优先 merchant_channel 链，无通道时回落 merchant.rate_transfer）
        $merchant = $this->loadMerchant($request);
        $params = [
            'amount'       => $request->post('amount', '0'),
            'bank_card_id' => $request->post('bank_card_id', 0),
        ];
        try {
            $result = (new WithdrawLogic())->apply($merchant, $params);
        } catch (Throwable $e) {
            // 余额不足/银行卡非法等业务异常统一转 {code:400}
            return $this->fail($e->getMessage());
        }
        return $this->success($result, '提现申请已提交，等待审核');
    }

    /**
     * 提现详情（仅本商户）
     * 路由：GET /mapi/withdraw/read
     *
     * @param Request $request
     * @return Response
     */
    public function read(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $id = $request->input('id', '');
        $row = Withdraw::where('id', $id)->where('merchant_id', $merchantId)->find();
        if (!$row) {
            return $this->fail('提现单不存在');
        }
        return $this->success($row->toArray());
    }
}
