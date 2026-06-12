<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代收订单管理控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\OrderLogic;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 代收订单管理控制器（平台后台 /core/pay/order）
 *
 * 只读 + 补单：订单由网关与回调驱动产生/流转，后台**不提供**手工增删改，
 * 仅开放列表(index)、详情(read)、人工补单(reissue)。权限码 pay:order:*。
 */
class OrderController extends BaseController
{
    /**
     * 构造：注入订单逻辑层
     */
    public function __construct()
    {
        $this->logic = new OrderLogic();
        parent::__construct();
    }

    /**
     * 订单列表
     * @param Request $request
     * @return Response
     */
    #[Permission('订单数据列表', 'pay:order:index')]
    public function index(Request $request): Response
    {
        // 搜索条件（与商户门户 /mapi/order/index 对齐，另增 mch_id 供平台筛商户）
        $where = $request->more([
            ['keyword', ''],
            ['order_no', ''],
            ['out_trade_no', ''],
            ['upstream_no', ''],
            ['mch_id', ''],
            ['merchant_id', ''],
            ['pay_type', ''],
            ['channel_id', ''],
            ['status', ''],
            ['create_time', []],
            ['pay_time', []],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 订单详情
     * @param Request $request
     * @return Response
     */
    #[Permission('订单数据读取', 'pay:order:read')]
    public function read(Request $request): Response
    {
        $model = $this->logic->read($request->input('id', ''));
        if ($model) {
            return $this->success(is_array($model) ? $model : $model->toArray());
        }
        return $this->fail('未查找到信息');
    }

    /**
     * 人工补单（强幂等）
     * 路由：POST /core/pay/order/reissue
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('订单人工补单', 'pay:order:reissue')]
    public function reissue(Request $request): Response
    {
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请选择要补单的订单');
        }
        $result = $this->logic->reissue($id);
        return $this->success($result, $result['message'] ?? '补单成功');
    }

    /**
     * 测试下单（平台后台专用，走生产网关逻辑，免商户签名）
     * 路由：POST /core/pay/order/testSubmit
     */
    #[Permission('测试代收下单', 'pay:order:testSubmit')]
    public function testSubmit(Request $request): Response
    {
        $merchantId = (int) $request->post('merchant_id', 0);
        if ($merchantId <= 0) {
            return $this->fail('请选择商户');
        }

        try {
            $result = $this->logic->testSubmit($merchantId, [
                'amount'         => $request->post('amount', '0'),
                'pay_type'       => $request->post('pay_type', 0),
                'out_trade_no'   => $request->post('out_trade_no', ''),
                'notify_url'     => $request->post('notify_url', ''),
                'return_url'     => $request->post('return_url', ''),
                'commodity_name' => $request->post('commodity_name', ''),
            ]);
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success($result, '测试下单成功');
    }

    /**
     * 测试商户 notify 接收记录（闭环排障）
     * 路由：GET /core/pay/order/testNotifyRecent
     */
    #[Permission('测试回调记录', 'pay:order:read')]
    public function testNotifyRecent(Request $request): Response
    {
        $data = $this->logic->testNotifyRecent(
            (int) $request->input('limit', 20),
            $request->input('order_no', '') ?: null,
            $request->input('out_trade_no', '') ?: null,
        );

        return $this->success($data);
    }
}
