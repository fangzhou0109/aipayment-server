<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户代付网关控制器（代付下单/查单）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\gateway;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\WithdrawLogic;
use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户代付网关控制器（/pay/*）
 *
 * 面向外部商户服务器，无后台登录态：身份与防伪由 SignVerify + IpWhitelist 中间件完成，
 * 商户上下文经请求头 pay_merchant 透传至此。复用提现逻辑层（代付与提现同一资金状态机）。
 */
class TransferGatewayController extends OpenController
{
    /**
     * 无需后台登录的方法白名单（被 saiadmin CheckLogin 读取；本组实际走网关中间件）
     * @var array
     */
    protected array $noNeedLogin = ['transfer', 'transferQuery'];

    /**
     * 提现/代付逻辑层
     * @var WithdrawLogic
     */
    protected WithdrawLogic $logic;

    /**
     * 构造：注入提现逻辑层
     */
    public function __construct()
    {
        $this->logic = new WithdrawLogic();
        parent::__construct();
    }

    /**
     * 商户代付下单
     * 路由：POST /pay/transfer
     *
     * 入参（表单，与代收下单同鉴权）：mch_id/out_biz_no(商户代付单号)/money(分)/
     *   bank_card_id(收款银行卡)/notify_url(可选)/time/sign/sign_type。
     * 成功返回 { withdraw_no, out_biz_no, amount, fee, real_amount, status, status_text, transfer_no }。
     * out_biz_no 为同商户幂等键：重复请求返回既有单据状态，不重复出款。
     *
     * @param Request $request 请求
     * @return Response
     */
    public function transfer(Request $request): Response
    {
        // 中间件已校验签名并挂载商户上下文
        $merchant = $request->header('pay_merchant');
        if (!is_array($merchant)) {
            return $this->fail('未通过身份校验');
        }

        try {
            $result = $this->logic->createByApi($merchant, $request->post());
            return $this->success($result, '代付受理成功');
        } catch (PaymentException $e) {
            // 业务可预期失败：统一 {code:400, message}
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            // 兜底：不泄露内部细节，仅返回通用错误
            return $this->fail('代付处理异常');
        }
    }

    /**
     * 商户代付查单
     * 路由：POST /pay/transferQuery
     *
     * 入参（表单）：mch_id/out_biz_no/time/sign/sign_type（与代付下单同鉴权）。
     * 成功返回 { withdraw_no, out_biz_no, amount, fee, real_amount, status, status_text, transfer_no }。
     * 仅能查询本商户名下代付单（merchant_id 强约束）。
     *
     * @param Request $request 请求
     * @return Response
     */
    public function transferQuery(Request $request): Response
    {
        $merchant = $request->header('pay_merchant');
        if (!is_array($merchant)) {
            return $this->fail('未通过身份校验');
        }

        try {
            $result = $this->logic->queryByApi($merchant, $request->post());
            return $this->success($result, '查询成功');
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            return $this->fail('查询处理异常');
        }
    }
}
