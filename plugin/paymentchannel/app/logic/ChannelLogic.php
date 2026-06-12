<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游通道管理逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\service\channel\ChannelAdapterRegistry;
use plugin\paymentchannel\service\ChannelBizResolver;
/**
 * 上游通道管理逻辑层
 *
 * 直接复用 PaymentBaseLogic 的 search/getList/read/add/edit/destroy；
 * 保存时经 {@see ChannelBizResolver} 自动维护 channel_biz（Phase 9.5.1）。
 */
class ChannelLogic extends PaymentBaseLogic
{
    /**
     * @param Channel|null $model 通道模型（测试可注入）
     * @param ChannelBizResolver|null $bizResolver 业务能力解析器（测试可注入）
     */
    public function __construct(
        ?Channel $model = null,
        private ?ChannelBizResolver $bizResolver = null,
    ) {
        $this->model = $model ?? new Channel();
    }

    /**
     * 新增通道：写入前重算 channel_biz
     */
    public function add(array $data): mixed
    {
        $data = $this->getBizResolver()->applyToSaveData($data);

        return parent::add($data);
    }

    /**
     * 修改通道：合并既有行后重算 channel_biz（保留双能力另一侧）
     */
    public function edit($id, array $data): mixed
    {
        $existing = $this->read($id);
        if ($existing !== null && $existing !== false) {
            $row = is_array($existing) ? $existing : $existing->toArray();
            $data = $this->getBizResolver()->applyToSaveData(
                $this->getBizResolver()->mergeForUpdate($data, $row)
            );
        } else {
            $data = $this->getBizResolver()->applyToSaveData($data);
        }

        return parent::edit($id, $data);
    }

    /**
     * 代收通道列表搜索（强制 channel_biz 代收作用域，Phase 9.5.2）
     *
     * @param array $where keyword / pay_type / status 等
     * @return mixed ThinkORM 查询构造
     */
    public function searchPay(array $where): mixed
    {
        $where['channel_biz'] = Channel::BIZ_SCOPE_PAY;

        return $this->search($where);
    }

    /**
     * 获取可选适配器下拉选项
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function adapterOptions(): array
    {
        return ChannelAdapterRegistry::options();
    }

    /**
     * 业务能力解析器（可注入，单测用）
     */
    protected function getBizResolver(): ChannelBizResolver
    {
        return $this->bizResolver ??= new ChannelBizResolver();
    }
}
