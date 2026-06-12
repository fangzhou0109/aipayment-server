<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：路由-通道关联控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\RouteChannelLogic;
use plugin\paymentchannel\app\validate\RouteChannelValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 路由-通道关联控制器（/core/pay/routeChannel）
 *
 * 维护一条路由下绑定的通道及金额规则/权重。权限码复用 pay:route:*。
 */
class RouteChannelController extends BaseController
{
    /**
     * 构造：注入逻辑层与验证器
     */
    public function __construct()
    {
        $this->logic = new RouteChannelLogic();
        $this->validate = new RouteChannelValidate();
        parent::__construct();
    }

    /**
     * 列表（通常按 route_id 过滤）
     * @param Request $request
     * @return Response
     */
    #[Permission('路由通道列表', 'pay:route:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['route_id', ''],
            ['channel_id', ''],
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
    #[Permission('路由通道读取', 'pay:route:read')]
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
    #[Permission('路由通道添加', 'pay:route:save')]
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
    #[Permission('路由通道修改', 'pay:route:update')]
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
    #[Permission('路由通道删除', 'pay:route:destroy')]
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids', '');
        if (empty($ids)) {
            return $this->fail('请选择要删除的数据');
        }
        return $this->logic->destroy($ids) ? $this->success('删除成功') : $this->fail('删除失败');
    }
}
