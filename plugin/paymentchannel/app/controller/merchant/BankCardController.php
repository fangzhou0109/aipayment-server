<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户银行卡控制器（/mapi/bankCard）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\BankCardLogic;
use plugin\paymentchannel\app\model\BankCard;
use plugin\paymentchannel\app\validate\BankCardValidate;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户门户银行卡控制器（/mapi/bankCard）
 *
 * 商户自助：列表 / 绑卡 / 解绑 / 启停。**归属商户ID 强制取自 token**——
 * 绑卡只能绑到自己名下，解绑只能删自己的卡（防越权）。卡号经 Luhn 校验。
 */
class BankCardController extends BaseMerchantController
{
    /**
     * 银行卡列表（仅本商户）
     * 路由：GET /mapi/bankCard/index
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $where = $request->more([
            ['keyword', ''],
            ['status', ''],
            ['create_time', []],
        ]);
        $where['merchant_id'] = $merchantId;

        $logic = new BankCardLogic();
        $query = $logic->search($where);
        return $this->success($logic->getList($query));
    }

    /**
     * 绑定银行卡
     * 路由：POST /mapi/bankCard/save
     *
     * @param Request $request { holder_name, card_no, bank_name, bank_code, branch_name }
     * @return Response
     */
    public function save(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        // 强制归属当前商户（忽略请求里的任何 merchant_id，防越权绑卡）
        $data = [
            'merchant_id' => $merchantId,
            'holder_name' => (string) $request->post('holder_name', ''),
            'card_no'     => (string) $request->post('card_no', ''),
            'bank_name'   => (string) $request->post('bank_name', ''),
            'bank_code'   => (string) $request->post('bank_code', ''),
            'branch_name' => (string) $request->post('branch_name', ''),
            'status'      => BankCard::STATUS_NORMAL,
        ];

        // 卡号 Luhn + 必填校验（复用平台后台同款验证器）
        $validate = new BankCardValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->fail($validate->getError());
        }

        try {
            $result = (new BankCardLogic())->bindCard($data);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        $msg = $result['first_card'] ? '绑卡成功（首张卡，提现将加强复核）' : '绑卡成功';
        return $this->success($result, $msg);
    }

    /**
     * 解绑银行卡（删除，仅本商户）
     * 路由：DELETE /mapi/bankCard/destroy
     *
     * @param Request $request { id }
     * @return Response
     */
    public function destroy(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $id = (int) $request->post('id', 0);
        // ownership：必须是自己的卡才允许删
        $card = BankCard::where('id', $id)->where('merchant_id', $merchantId)->find();
        if (!$card) {
            return $this->fail('银行卡不存在');
        }
        $card->delete();
        return $this->success('解绑成功');
    }

    /**
     * 启用/停用银行卡（仅本商户）
     * 路由：POST /mapi/bankCard/changeStatus
     *
     * @param Request $request { id, status(1正常/2停用) }
     * @return Response
     */
    public function changeStatus(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $id = (int) $request->post('id', 0);
        $status = (int) $request->post('status', 0);

        try {
            (new BankCardLogic())->changeStatus($merchantId, $id, $status);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $msg = $status === BankCard::STATUS_NORMAL ? '已启用' : '已停用';
        return $this->success(['id' => $id, 'status' => $status], $msg);
    }
}
