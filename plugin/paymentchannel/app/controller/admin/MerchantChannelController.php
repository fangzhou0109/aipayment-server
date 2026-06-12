<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-通道授权控制器（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\admin;

use plugin\paymentchannel\app\logic\MerchantChannelLogic;
use plugin\paymentchannel\app\validate\MerchantChannelValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\exception\ApiException;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * 商户-通道授权控制器（/core/pay/merchantChannel）
 *
 * 维护商户代收通道白名单与定制费率。权限复用 pay:merchant:* 体系。
 */
class MerchantChannelController extends BaseController
{
    /**
     * 构造：注入逻辑层与验证器
     */
    public function __construct()
    {
        $this->logic = new MerchantChannelLogic();
        $this->validate = new MerchantChannelValidate();
        parent::__construct();
    }

    /**
     * 列表
     * @param Request $request
     * @return Response
     */
    #[Permission('商户通道列表', 'pay:merchant:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['merchant_id', ''],
            ['channel_id', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 按商户列出通道授权（含通道参考字段，供「通道配置」弹窗表格）
     *
     * @param Request $request 须传 merchant_id
     * @return Response
     */
    #[Permission('商户通道配置列表', 'pay:merchant:channel')]
    public function listByMerchant(Request $request): Response
    {
        $merchantId = (int) $request->input('merchant_id', 0);
        if ($merchantId <= 0) {
            return $this->fail('商户ID无效');
        }

        return $this->success($this->logic->listByMerchant($merchantId));
    }

    /**
     * 按商户列出代付通道授权（仅 channel_biz IN 2,3，供「代付通道配置」）
     *
     * @param Request $request 须传 merchant_id
     * @return Response
     */
    #[Permission('商户代付通道配置列表', 'pay:merchant:channel')]
    public function listTransferByMerchant(Request $request): Response
    {
        $merchantId = (int) $request->input('merchant_id', 0);
        if ($merchantId <= 0) {
            return $this->fail('商户ID无效');
        }

        return $this->success($this->logic->listTransferByMerchant($merchantId));
    }

    /**
     * 读取
     * @param Request $request
     * @return Response
     */
    #[Permission('商户通道读取', 'pay:merchant:read')]
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
    #[Permission('商户通道添加', 'pay:merchant:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        return $this->logic->add($data) ? $this->success('添加成功') : $this->fail('添加失败');
    }

    /**
     * 按通道列出商户授权（供通道页「授权商户」抽屉）
     *
     * @param Request $request 须传 channel_id
     * @return Response
     */
    #[Permission('通道授权商户列表', 'pay:channel:auth')]
    public function listByChannel(Request $request): Response
    {
        $channelId = (int) $request->input('channel_id', 0);
        if ($channelId <= 0) {
            return $this->fail('通道ID无效');
        }

        try {
            return $this->success($this->logic->listByChannel($channelId));
        } catch (ApiException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 按通道批量开通/关闭商户授权
     *
     * @param Request $request body: { channel_id, merchant_ids: int[], status: 1|2 }
     * @return Response
     */
    #[Permission('通道批量授权商户', 'pay:channel:auth')]
    public function batchAuthorizeByChannel(Request $request): Response
    {
        $channelId = (int) $request->post('channel_id', 0);
        $merchantIds = $request->post('merchant_ids', []);
        $status = (int) $request->post('status', 0);

        if ($channelId <= 0) {
            return $this->fail('通道ID无效');
        }
        if (!is_array($merchantIds)) {
            return $this->fail('merchant_ids 格式非法');
        }
        if (!in_array($status, [1, 2], true)) {
            return $this->fail('status 须为 1（开通）或 2（关闭）');
        }

        try {
            $result = $this->logic->batchAuthorizeByChannel($channelId, $merchantIds, $status);
            return $this->success($result, '操作成功');
        } catch (ApiException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 批量保存商户×通道绑定（merchant_id 以请求体为准，行内 merchant_id 忽略）
     *
     * @param Request $request body: { merchant_id, rows: [{channel_id, rate, day_limit, single_min, single_max, status}, ...] }
     * @return Response
     */
    #[Permission('商户通道批量保存', 'pay:merchant:channel')]
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

        $result = $this->logic->batchBind($merchantId, $rows);
        return $this->success($result, '保存成功');
    }

    /**
     * 批量保存商户×代付通道绑定（仅 rate_transfer / transfer_enabled）
     *
     * @param Request $request body: { merchant_id, rows: [{ channel_id, rate_transfer, transfer_enabled }] }
     * @return Response
     */
    #[Permission('商户代付通道批量保存', 'pay:merchant:channel')]
    public function batchSaveTransfer(Request $request): Response
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
            $result = $this->logic->batchBindTransfer($merchantId, $rows);
            return $this->success($result, '保存成功');
        } catch (ApiException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 按通道批量开通/关闭商户代付授权
     *
     * @param Request $request body: { channel_id, merchant_ids: int[], transfer_enabled: 1|2 }
     * @return Response
     */
    #[Permission('通道批量授权商户代付', 'pay:transferChannel:auth')]
    public function batchAuthorizeTransferByChannel(Request $request): Response
    {
        $channelId = (int) $request->post('channel_id', 0);
        $merchantIds = $request->post('merchant_ids', []);
        $transferEnabled = (int) $request->post('transfer_enabled', 0);

        if ($channelId <= 0) {
            return $this->fail('通道ID无效');
        }
        if (!is_array($merchantIds)) {
            return $this->fail('merchant_ids 格式非法');
        }
        if (!in_array($transferEnabled, [1, 2], true)) {
            return $this->fail('transfer_enabled 须为 1（开通）或 2（关闭）');
        }

        try {
            $result = $this->logic->batchAuthorizeTransferByChannel($channelId, $merchantIds, $transferEnabled);
            return $this->success($result, '操作成功');
        } catch (ApiException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 修改
     * @param Request $request
     * @return Response
     */
    #[Permission('商户通道修改', 'pay:merchant:update')]
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
    #[Permission('商户通道删除', 'pay:merchant:destroy')]
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids', '');
        if (empty($ids)) {
            return $this->fail('请选择要删除的数据');
        }
        return $this->logic->destroy($ids) ? $this->success('删除成功') : $this->fail('删除失败');
    }
}
