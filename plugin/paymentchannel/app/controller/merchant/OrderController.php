<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户订单控制器（/mapi/order）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\OrderLogic;
use plugin\paymentchannel\app\model\Order;
use support\Request;
use support\Response;

/**
 * 商户门户订单控制器（/mapi/order）
 *
 * 商户自助查单：列表 + 详情。**强制按 token 商户ID 过滤**，仅能查自己的订单（防越权）。
 */
class OrderController extends BaseMerchantController
{
    /**
     * 订单列表（仅本商户）
     * 路由：GET /mapi/order/index
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $where = $request->more([
            ['keyword', ''],
            ['order_no', ''],
            ['out_trade_no', ''],
            ['upstream_no', ''],
            ['pay_type', ''],
            ['status', ''],
            ['create_time', []],
            ['pay_time', []],
        ]);
        // 强制注入商户ID（覆盖任何外部传入，杜绝越权）
        $where['merchant_id'] = $merchantId;

        $logic = new OrderLogic();
        $query = $logic->search($where);
        return $this->success($logic->getList($query));
    }

    /**
     * 订单详情（仅本商户，按 ID + merchant_id 双重约束）
     * 路由：GET /mapi/order/read
     *
     * @param Request $request
     * @return Response
     */
    public function read(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $id = $request->input('id', '');
        // ownership：ID 必须属于当前商户，否则查不到
        $order = Order::where('id', $id)->where('merchant_id', $merchantId)->find();
        if (!$order) {
            return $this->fail('订单不存在');
        }
        return $this->success($order->toArray());
    }
}
