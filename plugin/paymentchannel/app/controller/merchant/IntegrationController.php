<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户 API 对接说明与沙箱测试控制器（/mapi/integration）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\IntegrationLogic;
use plugin\paymentchannel\app\logic\OrderLogic;
use plugin\paymentchannel\app\model\Merchant;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户门户 API 对接说明与沙箱测试（/mapi/integration）
 *
 * 文档元数据、沙箱下单/查单、签名示例、测试 notify 记录；商户 ID 强制取自 token。
 */
class IntegrationController extends BaseMerchantController
{
    /**
     * 对接文档上下文
     * 路由：GET /mapi/integration/docs
     */
    public function docs(Request $request): Response
    {
        try {
            $data = (new IntegrationLogic())->docs($request, $this->merchantId($request));
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success($data);
    }

    /**
     * 沙箱测试下单（走生产 PayGatewayLogic，免网关签名校验）
     * 路由：POST /mapi/integration/testSubmit
     */
    public function testSubmit(Request $request): Response
    {
        $merchantId = $this->merchantId($request);

        try {
            $result = (new OrderLogic())->testSubmit($merchantId, [
                'amount'         => $request->input('amount', '0'),
                'pay_type'       => $request->input('pay_type', 0),
                'out_trade_no'   => $request->input('out_trade_no', ''),
                'notify_url'     => $request->input('notify_url', ''),
                'return_url'     => $request->input('return_url', ''),
                'commodity_name' => $request->input('commodity_name', ''),
                'client_ip'      => $request->getRealIp(),
                'extra'          => $request->input('extra', 'merchant_test'),
            ]);
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success($result, '测试下单成功');
    }

    /**
     * 沙箱测试查单（走生产 queryOrder，免网关签名校验）
     * 路由：POST /mapi/integration/testQuery
     */
    public function testQuery(Request $request): Response
    {
        $merchantId = $this->merchantId($request);

        try {
            $result = (new OrderLogic())->testQuery($merchantId, [
                'order_id' => $request->post('order_id', $request->post('out_trade_no', '')),
            ]);
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success($result, '查询成功');
    }

    /**
     * 测试异步通知接收记录（仅本商户）
     * 路由：GET /mapi/integration/testNotifyRecent
     */
    public function testNotifyRecent(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $m = Merchant::where('id', $merchantId)->field('mch_id')->find();
        if (!$m) {
            return $this->fail('商户不存在');
        }

        $data = (new IntegrationLogic())->testNotifyRecent(
            (string) $m->mch_id,
            (int) $request->input('limit', 20),
            $request->input('order_no'),
            $request->input('out_trade_no')
        );

        return $this->success($data);
    }

    /**
     * 生成网关签名请求示例（curl + 待签串，密钥仅在服务端计算）
     * 路由：POST /mapi/integration/buildSignSample
     */
    public function buildSignSample(Request $request): Response
    {
        $action = trim((string) $request->post('action', 'submit'));

        try {
            $result = (new IntegrationLogic())->buildSignSample(
                $this->merchantId($request),
                $action,
                $request->post()
            );
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            return $this->fail('生成签名示例失败');
        }

        return $this->success($result);
    }
}
