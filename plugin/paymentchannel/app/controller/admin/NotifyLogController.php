<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户通知日志控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\NotifyLogLogic;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 商户通知日志控制器（平台后台 /core/pay/notify）
 *
 * 只读 + 人工重发；日志由入账/补单流程自动写入。
 */
class NotifyLogController extends BaseController
{
    public function __construct()
    {
        $this->logic = new NotifyLogLogic();
        parent::__construct();
    }

    /**
     * 通知日志列表
     * 路由：GET /core/pay/notify/index
     */
    #[Permission('通知日志列表', 'pay:notify:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['order_no', ''],
            ['mch_id', ''],
            ['merchant_id', ''],
            ['biz_type', ''],
            ['status', ''],
            ['create_time', []],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);

        return $this->success($data);
    }

    /**
     * 通知日志详情
     * 路由：GET /core/pay/notify/read
     */
    #[Permission('通知日志读取', 'pay:notify:read')]
    public function read(Request $request): Response
    {
        $model = $this->logic->read($request->input('id', ''));
        if ($model) {
            return $this->success(is_array($model) ? $model : $model->toArray());
        }

        return $this->fail('未查找到信息');
    }

    /**
     * 人工重发通知
     * 路由：POST /core/pay/notify/resend
     */
    #[Permission('通知重发', 'pay:notify:resend')]
    public function resend(Request $request): Response
    {
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请选择通知日志');
        }

        try {
            $result = $this->logic->resend($id);
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        $message = (string) ($result['message'] ?? '操作完成');
        if (!empty($result['success'])) {
            return $this->success($result, $message);
        }

        return $this->fail($message, $result);
    }
}
