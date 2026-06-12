<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：综合路由控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\RouteLogic;
use plugin\paymentchannel\app\validate\RouteValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 综合路由控制器（/core/pay/route）
 *
 * 维护路由及其调试试算。权限码 pay:route:*。
 */
class RouteController extends BaseController
{
    /**
     * 构造：注入逻辑层与验证器
     */
    public function __construct()
    {
        $this->logic = new RouteLogic();
        $this->validate = new RouteValidate();
        parent::__construct();
    }

    /**
     * 列表
     * @param Request $request
     * @return Response
     */
    #[Permission('路由数据列表', 'pay:route:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['keyword', ''],
            ['pay_type', ''],
            ['status', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 读取
     * @param Request $request
     * @return Response
     */
    #[Permission('路由数据读取', 'pay:route:read')]
    public function read(Request $request): Response
    {
        $model = $this->logic->read($request->input('id', ''));
        if ($model) {
            return $this->success(is_array($model) ? $model : $model->toArray());
        }
        return $this->fail('未查找到信息');
    }

    /**
     * 新增
     * @param Request $request
     * @return Response
     */
    #[Permission('路由数据添加', 'pay:route:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        return $this->logic->add($data) ? $this->success('添加成功') : $this->fail('添加失败');
    }

    /**
     * 修改
     * @param Request $request
     * @return Response
     */
    #[Permission('路由数据修改', 'pay:route:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        return $this->logic->edit($data['id'], $data) ? $this->success('修改成功') : $this->fail('修改失败');
    }

    /**
     * 删除
     * @param Request $request
     * @return Response
     */
    #[Permission('路由数据删除', 'pay:route:destroy')]
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids', '');
        if (empty($ids)) {
            return $this->fail('请选择要删除的数据');
        }
        return $this->logic->destroy($ids) ? $this->success('删除成功') : $this->fail('删除失败');
    }

    /**
     * 路由试算（调试）：给定路由ID、金额，可选 merchant_id 模拟商户上下文
     *
     * @param Request $request route_id、amount、merchant_id（可选）
     * @return Response 含 channel_id/channel_title/resolved_rate/fee_preview 等
     */
    #[Permission('路由试算', 'pay:route:index')]
    public function preview(Request $request): Response
    {
        $routeId = (int) $request->input('route_id', 0);
        $amount = $request->input('amount', '0');
        $merchantId = (int) $request->input('merchant_id', 0);

        if ($routeId <= 0) {
            return $this->fail('路由ID无效');
        }

        return $this->success($this->logic->previewRoute($routeId, $amount, $merchantId));
    }
}
