<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户支付网关控制器（下单/回调/查单）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\gateway;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\PayGatewayLogic;
use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户支付网关控制器（/pay/*）
 *
 * 面向外部商户服务器，无后台登录态：身份与防伪由 SignVerify + IpWhitelist 中间件完成，
 * 商户上下文经请求头 pay_merchant 透传至此。继承 OpenController 复用统一响应方法。
 */
class PayGatewayController extends OpenController
{
    /**
     * 无需后台登录的方法白名单（被 saiadmin CheckLogin 读取；本组实际走网关中间件）
     * @var array
     */
    protected array $noNeedLogin = ['submitOrder', 'query'];

    /**
     * 逻辑层
     * @var PayGatewayLogic
     */
    protected PayGatewayLogic $logic;

    /**
     * 构造：注入网关逻辑层
     */
    public function __construct()
    {
        $this->logic = new PayGatewayLogic();
        parent::__construct();
    }

    /**
     * 商户下单
     * 路由：POST /pay/submitOrder
     *
     * 入参（表单，与商户 Demo 对齐）：mch_id/pay_type/money(分)/time/order_id/notify_url/
     *   return_url/commodity_name/extra/client_ip/sign/sign_type。
     * 成功返回 { order_no, pay_url, upstream_no, amount }；失败返回 {code:400,message}。
     *
     * @param Request $request 请求
     * @return Response
     */
    public function submitOrder(Request $request): Response
    {
        // 中间件已校验签名并挂载商户上下文
        $merchant = $request->header('pay_merchant');
        if (!is_array($merchant)) {
            return $this->fail('未通过身份校验');
        }

        try {
            $result = $this->logic->submitOrder($merchant, $request->post());
            return $this->success($result, '下单成功');
        } catch (PaymentException $e) {
            // 业务可预期失败：统一 {code:400, message}
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            // 兜底：不泄露内部细节，仅返回通用错误
            return $this->fail('下单处理异常');
        }    }

    /**
     * 商户查单
     * 路由：POST /pay/query
     *
     * 入参（表单）：mch_id/order_id/time/sign/sign_type（与下单同鉴权）。
     * 成功返回 { order_no, out_trade_no, upstream_no, amount, status, trade_status, pay_time }。
     * 仅能查询本商户名下订单（merchant_id 强约束）。
     *
     * @param Request $request 请求
     * @return Response
     */
    public function query(Request $request): Response
    {
        $merchant = $request->header('pay_merchant');
        if (!is_array($merchant)) {
            return $this->fail('未通过身份校验');
        }

        try {
            $result = $this->logic->queryOrder($merchant, $request->post());
            return $this->success($result, '查询成功');
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            return $this->fail('查询处理异常');
        }    }
}
