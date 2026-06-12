<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-路由授权控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\MerchantRouteLogic;
use plugin\paymentchannel\app\validate\MerchantRouteValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\exception\ApiException;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 商户-路由授权控制器（/core/pay/merchantRoute）
 */
class MerchantRouteController extends BaseController
{
    public function __construct()
    {
        $this->logic = new MerchantRouteLogic();
        $this->validate = new MerchantRouteValidate();
        parent::__construct();
    }

    #[Permission('商户路由列表', 'pay:merchant:route')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['merchant_id', ''],
            ['route_id', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    #[Permission('商户路由配置列表', 'pay:merchant:route')]
    public function listByMerchant(Request $request): Response
    {
        $merchantId = (int) $request->input('merchant_id', 0);
        if ($merchantId <= 0) {
            return $this->fail('商户ID无效');
        }

        return $this->success($this->logic->listByMerchant($merchantId));
    }

    #[Permission('商户路由读取', 'pay:merchant:route')]
    public function read(Request $request): Response
    {
        $model = $this->logic->read($request->input('id', ''));
        if ($model) {
            return $this->success(is_array($model) ? $model : $model->toArray());
        }
        return $this->fail('未查找到信息');
    }

    #[Permission('商户路由添加', 'pay:merchant:route')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        return $this->logic->add($data) ? $this->success('添加成功') : $this->fail('添加失败');
    }

    #[Permission('商户路由批量保存', 'pay:merchant:route')]
    public function batchSave(Request $request): Response
    {
        $merchantId = (int) $request->post('merchant_id', 0);
        $rows = $request->post('rows', []);

        if ($merchantId <= 0) {
            return $this->fail('商户ID无效');
        }
        if (!is_array($rows)) {
            return $this->fail('rows 格式非法');
        }

        try {
            $result = $this->logic->batchBind($merchantId, $rows);
            return $this->success($result, '保存成功');
        } catch (ApiException $e) {
            return $this->fail($e->getMessage());
        }
    }

    #[Permission('商户路由修改', 'pay:merchant:route')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        return $this->logic->edit($data['id'], $data) ? $this->success('修改成功') : $this->fail('修改失败');
    }

    #[Permission('商户路由删除', 'pay:merchant:route')]
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids', '');
        if (empty($ids)) {
            return $this->fail('请选择要删除的数据');
        }
        return $this->logic->destroy($ids) ? $this->success('删除成功') : $this->fail('删除失败');
    }
}
