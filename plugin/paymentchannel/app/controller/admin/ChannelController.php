<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游通道管理控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\ChannelLogic;
use plugin\paymentchannel\app\validate\ChannelValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 上游通道管理控制器（平台后台 /core/pay/channel）
 *
 * 复用 saiadmin BaseController：登录态注入、统一响应、验证器调用。
 */
class ChannelController extends BaseController
{
    /**
     * 构造：注入逻辑层与验证器
     */
    public function __construct()
    {
        $this->logic = new ChannelLogic();
        $this->validate = new ChannelValidate();
        parent::__construct();
    }

    /**
     * 通道数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('通道数据列表', 'pay:channel:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['keyword', ''],
            ['pay_type', ''],
            ['status', ''],
        ]);
        // Phase 9.5.2：代收菜单仅展示具备代收能力的通道（含双能力）
        $query = $this->logic->searchPay($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 读取单个通道
     * @param Request $request
     * @return Response
     */
    #[Permission('通道数据读取', 'pay:channel:read')]
    public function read(Request $request): Response
    {
        $id = $request->input('id', '');
        $model = $this->logic->read($id);
        if ($model) {
            $data = is_array($model) ? $model : $model->toArray();
            return $this->success($data);
        }
        return $this->fail('未查找到信息');
    }

    /**
     * 新增通道
     * @param Request $request
     * @return Response
     */
    #[Permission('通道数据添加', 'pay:channel:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        $result = $this->logic->add($data);
        if ($result) {
            return $this->success('添加成功');
        }
        return $this->fail('添加失败');
    }

    /**
     * 修改通道
     * @param Request $request
     * @return Response
     */
    #[Permission('通道数据修改', 'pay:channel:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $result = $this->logic->edit($data['id'], $data);
        if ($result) {
            return $this->success('修改成功');
        }
        return $this->fail('修改失败');
    }

    /**
     * 删除通道（软删除）
     * @param Request $request
     * @return Response
     */
    #[Permission('通道数据删除', 'pay:channel:destroy')]
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids', '');
        if (empty($ids)) {
            return $this->fail('请选择要删除的数据');
        }
        $result = $this->logic->destroy($ids);
        if ($result) {
            return $this->success('删除成功');
        }
        return $this->fail('删除失败');
    }

    /**
     * 获取可用适配器下拉选项（供前端表单 adapter 选择）
     * 复用列表权限，无需额外权限码。
     * @param Request $request
     * @return Response
     */
    #[Permission('通道适配器选项', 'pay:channel:index')]
    public function adapters(Request $request): Response
    {
        return $this->success($this->logic->adapterOptions());
    }
}
