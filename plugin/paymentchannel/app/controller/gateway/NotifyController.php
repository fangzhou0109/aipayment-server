<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游回调网关控制器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\gateway;

use plugin\paymentchannel\app\logic\NotifyGatewayLogic;
use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;
use Throwable;

/**
 * 上游回调网关控制器（/pay/notify/{channel}）
 *
 * 面向上游渠道服务器，无后台登录态、不走商户 SignVerify（上游用自身密钥签名，
 * 由对应适配器 verifyNotify 校验）。返回上游约定的**纯文本**确认串（如 success/fail），
 * 而非平台统一 JSON 响应——上游据此判断是否停止重推。
 */
class NotifyController extends OpenController
{
    /**
     * 无需后台登录的方法白名单
     * @var array
     */
    protected array $noNeedLogin = ['notify'];

    /**
     * 逻辑层
     * @var NotifyGatewayLogic
     */
    protected NotifyGatewayLogic $logic;

    /**
     * 构造：注入回调逻辑层
     */
    public function __construct()
    {
        $this->logic = new NotifyGatewayLogic();
        parent::__construct();
    }

    /**
     * 接收上游异步回调
     * 路由：POST|GET /pay/notify/{channel}
     *
     * @param Request $request 请求
     * @param string $channel 通道编码（路径参数）
     * @return Response 纯文本确认串
     */
    public function notify(Request $request, string $channel): Response
    {
        // 合并 POST 与 GET 参数（不同上游回调方式不一）；路径参数 channel 不混入业务参数
        $payload = array_merge($request->get(), $request->post());

        try {
            $body = $this->logic->handleNotify($channel, $payload);
        } catch (Throwable $e) {
            // 处理异常（如入账事务回滚）：回 fail，促使上游重推，等待下次重试或人工补单
            $body = NotifyGatewayLogic::RESP_FAIL;
        }

        // 纯文本响应（非统一 JSON），契合上游「读 body 判定成功」的约定
        return response($body);
    }
}
