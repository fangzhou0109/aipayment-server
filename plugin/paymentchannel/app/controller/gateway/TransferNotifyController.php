<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付（提现下发）回调网关控制器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\gateway;

use plugin\paymentchannel\app\logic\TransferNotifyLogic;
use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;
use Throwable;

/**
 * 代付回调网关控制器（/pay/transferNotify/{channel}）
 *
 * 面向上游代付通道服务器，无后台登录态、不走商户 SignVerify（上游用自身密钥签名，
 * 由对应代付适配器 verifyNotify 校验）。返回上游约定的**纯文本**确认串（success/fail），
 * 上游据此判断是否停止重推。
 */
class TransferNotifyController extends OpenController
{
    /**
     * 无需后台登录的方法白名单
     * @var array
     */
    protected array $noNeedLogin = ['notify'];

    /**
     * 逻辑层
     * @var TransferNotifyLogic
     */
    protected TransferNotifyLogic $logic;

    /**
     * 构造：注入代付回调逻辑层
     */
    public function __construct()
    {
        $this->logic = new TransferNotifyLogic();
        parent::__construct();
    }

    /**
     * 接收上游代付异步回调
     * 路由：POST|GET /pay/transferNotify/{channel}
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
            // 兜底异常：回 fail，促使上游重推
            $body = TransferNotifyLogic::RESP_FAIL;
        }

        // 纯文本响应（非统一 JSON），契合上游「读 body 判定成功」的约定
        return response($body);
    }
}
