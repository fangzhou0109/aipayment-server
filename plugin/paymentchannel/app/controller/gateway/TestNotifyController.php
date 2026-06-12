<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：测试商户异步通知接收控制器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\gateway;

use plugin\paymentchannel\service\TestNotifyService;
use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;

/**
 * 测试商户 notify 接收端（/pay/test/notify）
 *
 * 无登录态；回应纯文本 SUCCESS/FAIL，与生产商户对接约定一致。
 */
class TestNotifyController extends OpenController
{
    protected array $noNeedLogin = ['notify'];

    /**
     * 接收平台发往测试 notify_url 的异步通知
     * 路由：POST|GET /pay/test/notify
     */
    public function notify(Request $request): Response
    {
        $payload = array_merge($request->get(), $request->post());
        $result = (new TestNotifyService())->handle($payload, (string) $request->getRealIp());

        return response($result['response'], (int) ($result['http_code'] ?? 200));
    }
}
