<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付通道管理控制器（平台后台 Phase 9.5.2）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\TransferChannelLogic;
use plugin\paymentchannel\app\validate\TransferChannelValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 代付通道管理控制器（平台后台 /core/pay/transferChannel）
 *
 * 复用 sa_pay_channel 表；与代收 ChannelController 路径分离，权限码独立（9.5.7 菜单种子）。
 */
class TransferChannelController extends BaseController
{
    /**
     * 构造：注入代付逻辑层与验证器
     */
    public function __construct()
    {
        $this->logic = new TransferChannelLogic();
        $this->validate = new TransferChannelValidate();
        parent::__construct();
    }

    /**
     * 代付通道列表（channel_biz IN 2,3）
     */
    #[Permission('代付通道数据列表', 'pay:transferChannel:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['keyword', ''],
            ['status', ''],
        ]);
        $query = $this->logic->searchTransfer($where);
        $data = $this->logic->getList($query);

        return $this->success($data);
    }

    /**
     * 读取单个代付通道
     */
    #[Permission('代付通道数据读取', 'pay:transferChannel:read')]
    public function read(Request $request): Response
    {
        $id = $request->input('id', '');
        $model = $this->logic->readTransfer($id);
        if ($model) {
            $data = is_array($model) ? $model : $model->toArray();

            return $this->success($data);
        }

        return $this->fail('未查找到信息');
    }

    /**
     * 新增代付通道（可不填代收 adapter）
     */
    #[Permission('代付通道数据添加', 'pay:transferChannel:save')]
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
     * 修改代付通道
     */
    #[Permission('代付通道数据修改', 'pay:transferChannel:update')]
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
     * 删除代付通道（软删除，须具备代付能力）
     */
    #[Permission('代付通道数据删除', 'pay:transferChannel:destroy')]
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids', '');
        if (empty($ids)) {
            return $this->fail('请选择要删除的数据');
        }
        $result = $this->logic->destroyTransfer($ids);
        if ($result) {
            return $this->success('删除成功');
        }

        return $this->fail('删除失败');
    }

    /**
     * 代付适配器下拉（与代收 /channel/adapters 对称）
     */
    #[Permission('代付通道适配器选项', 'pay:transferChannel:index')]
    public function transferAdapters(Request $request): Response
    {
        return $this->success($this->logic->transferAdapterOptions());
    }
}
