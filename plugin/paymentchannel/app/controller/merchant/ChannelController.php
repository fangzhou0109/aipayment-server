<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户通道控制器（/mapi/channel）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\MerchantChannelLogic;
use support\Request;
use support\Response;

/**
 * 商户门户通道控制器（/mapi/channel）
 *
 * 只读展示当前商户已开启的代收/代付通道及费率限额，不含上游密钥与运营成本。
 * 商户 ID 强制取自 token，不信任请求参数。
 */
class ChannelController extends BaseMerchantController
{
    /**
     * 已开启代收通道列表
     * 路由：GET /mapi/channel/payList
     */
    public function payList(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $logic = new MerchantChannelLogic();

        return $this->success($logic->listEnabledPayForMerchantPortal($merchantId));
    }

    /**
     * 已开启代付通道列表
     * 路由：GET /mapi/channel/transferList
     */
    public function transferList(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $logic = new MerchantChannelLogic();

        return $this->success($logic->listEnabledTransferForMerchantPortal($merchantId));
    }
}
