<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户管理控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\MerchantLogic;
use plugin\paymentchannel\app\validate\MerchantValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 商户管理控制器（平台后台 /core/pay/merchant）
 *
 * 复用 saiadmin BaseController：自动注入登录态、统一响应、验证器调用。
 * 权限通过 #[Permission] 注解声明，由 CheckAuth 中间件校验。
 */
class MerchantController extends BaseController
{
    /**
     * 构造：注入逻辑层与验证器
     */
    public function __construct()
    {
        $this->logic = new MerchantLogic();
        $this->validate = new MerchantValidate();
        parent::__construct();
    }

    /**
     * 商户数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('商户数据列表', 'pay:merchant:index')]
    public function index(Request $request): Response
    {
        // 提取搜索条件（搜索器在 Merchant 模型中定义）
        $where = $request->more([
            ['keyword', ''],
            ['mch_id', ''],
            ['status', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 读取单个商户
     * @param Request $request
     * @return Response
     */
    #[Permission('商户数据读取', 'pay:merchant:read')]
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
     * 新增商户（自动下发密钥）
     * @param Request $request
     * @return Response
     */
    #[Permission('商户数据添加', 'pay:merchant:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        $result = $this->logic->add($data);
        if ($result) {
            // 返回对接凭证包（secret_key + 平台公钥），仅本次响应明文下发
            $credentials = $this->logic->issueCredentials((int) $result);

            return $this->success($credentials, '添加成功');
        }
        return $this->fail('添加失败');
    }

    /**
     * 修改商户（余额字段只读，不会被写入）
     * @param Request $request
     * @return Response
     */
    #[Permission('商户数据修改', 'pay:merchant:update')]
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
     * 删除商户（软删除）
     * @param Request $request
     * @return Response
     */
    #[Permission('商户数据删除', 'pay:merchant:destroy')]
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
     * 重置商户密钥（重新下发 MD5 密钥与平台 RSA 私钥）
     * @param Request $request
     * @return Response
     */
    #[Permission('商户重置密钥', 'pay:merchant:resetKey')]
    public function resetKey(Request $request): Response
    {
        $id = $request->post('id', '');
        if (empty($id)) {
            return $this->fail('请指定商户');
        }
        $result = $this->logic->resetKey($id);
        return $this->success($result, '密钥已重置');
    }

    /**
     * 人工调账：增加/扣减商户可用余额（写资金流水，禁止直改 balance 字段）
     * 路由：POST /core/pay/merchant/adjustBalance
     */
    #[Permission('商户余额调账', 'pay:merchant:adjustBalance')]
    public function adjustBalance(Request $request): Response
    {
        // Webman 的 input() 须传字段名；JSON 请求体已由框架解析到 post()
        $data = $request->post();
        $this->validate('adjustBalance', $data);

        try {
            $result = $this->logic->adjustBalance(
                (int) $data['id'],
                (string) $data['direction'],
                (string) $data['amount'],
                (string) ($data['remark'] ?? ''),
            );
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success($result, '调账成功');
    }

    /**
     * 查看商户 API 对接资料（运营查阅/补发凭证）
     * 路由：GET /core/pay/merchant/credentials
     */
    #[Permission('商户API资料', 'pay:merchant:read')]
    public function credentials(Request $request): Response
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return $this->fail('请指定商户');
        }

        return $this->success($this->logic->viewApiCredentials($id));
    }
}
