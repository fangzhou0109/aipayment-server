<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付通道管理逻辑层（Phase 9.5.2）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\service\transfer\TransferAdapterRegistry;

/**
 * 代付通道管理逻辑层
 *
 * 复用 sa_pay_channel 与 {@see ChannelLogic} 的 CRUD / channel_biz 维护；
 * 列表与读写均限定具备代付能力（channel_biz IN 2,3）。
 */
class TransferChannelLogic extends ChannelLogic
{
    /**
     * 代付通道列表搜索（强制 channel_biz 代付作用域）
     *
     * @param array $where 与代收列表相同的 keyword / status 等
     * @return mixed ThinkORM 查询构造
     */
    public function searchTransfer(array $where): mixed
    {
        $where['channel_biz'] = Channel::BIZ_SCOPE_TRANSFER;

        return $this->search($where);
    }

    /**
     * 读取单条代付通道（无代付能力则视为不存在）
     *
     * @param mixed $id 主键
     * @return mixed 模型或数组；不存在返回 null
     */
    public function readTransfer(mixed $id): mixed
    {
        $model = $this->read($id);
        if ($model === null || $model === false) {
            return null;
        }
        $row = is_array($model) ? $model : $model->toArray();
        if (!$this->hasTransferBiz((int) ($row['channel_biz'] ?? 0))) {
            return null;
        }

        return $model;
    }

    /**
     * 新增代付通道：纯代付未传 adapter 时置空字符串（满足 NOT NULL 列）
     */
    public function add(array $data): mixed
    {
        return parent::add($this->normalizeTransferCreate($data));
    }

    /**
     * 修改代付通道：须已具备代付能力
     */
    public function edit($id, array $data): mixed
    {
        if ($this->readTransfer($id) === null) {
            return false;
        }

        return parent::edit($id, $data);
    }

    /**
     * 删除代付通道：仅允许删除具备代付能力的记录
     *
     * @param mixed $ids 逗号分隔或数组
     * @return bool
     */
    public function destroyTransfer(mixed $ids): bool
    {
        $idList = is_array($ids) ? $ids : explode(',', (string) $ids);
        $idList = array_filter(array_map('intval', $idList));
        if ($idList === []) {
            return false;
        }
        foreach ($idList as $id) {
            if ($this->readTransfer($id) === null) {
                return false;
            }
        }

        return $this->destroy($ids);
    }

    /**
     * 代付适配器下拉选项
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function transferAdapterOptions(): array
    {
        return TransferAdapterRegistry::options();
    }

    /**
     * 是否具备代付业务能力位
     */
    public function hasTransferBiz(int $channelBiz): bool
    {
        return in_array($channelBiz, [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH], true);
    }

    /**
     * 纯代付建档：adapter 缺省为 ''，由 ChannelBizResolver 计算 biz=2
     *
     * @param array $data 待写入数据
     * @return array
     */
    protected function normalizeTransferCreate(array $data): array
    {
        if (!isset($data['adapter']) || $data['adapter'] === null) {
            $data['adapter'] = '';
        }

        return $data;
    }
}
