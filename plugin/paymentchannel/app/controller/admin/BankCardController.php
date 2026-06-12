<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户银行卡管理控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\BankCardLogic;
use plugin\paymentchannel\app\validate\BankCardValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 商户银行卡管理控制器（平台后台 /core/pay/bankCard）
 *
 * 提供银行卡 CRUD（绑卡含首现卡风控提示）。卡号经 Luhn 校验防录入错误。
 * 权限码 pay:bankCard:*。
 */
class BankCardController extends BaseController
{
    /**
     * 构造：注入逻辑层与验证器
     */
    public function __construct()
    {
        $this->logic = new BankCardLogic();
        $this->validate = new BankCardValidate();
        parent::__construct();
    }

    /**
     * 银行卡列表
     * 路由：GET /core/pay/bankCard/index
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('银行卡数据列表', 'pay:bankCard:index')]
    public function index(Request $request): Response
    {
        // 搜索条件（搜索器在 BankCard 模型中定义：keyword/merchant_id/status）
        $where = $request->more([
            ['keyword', ''],
            ['merchant_id', ''],
            ['status', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 新增银行卡（绑卡，返回首现卡风控提示）
     * 路由：POST /core/pay/bankCard/save
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('银行卡数据添加', 'pay:bankCard:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        $result = $this->logic->bindCard($data);
        // 首现卡：提示运营该商户首次绑卡，提现需加强复核
        $msg = $result['first_card'] ? '绑卡成功（首现卡，提现请加强复核）' : '绑卡成功';
        return $this->success($result, $msg);
    }

    /**
     * 修改银行卡
     * 路由：PUT /core/pay/bankCard/update
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('银行卡数据修改', 'pay:bankCard:update')]
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
     * 删除银行卡（软删除）
     * 路由：DELETE /core/pay/bankCard/destroy
     *
     * @param Request $request
     * @return Response
     */
    #[Permission('银行卡数据删除', 'pay:bankCard:destroy')]
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
}
