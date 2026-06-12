<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付（提现下发）回调网关逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use Closure;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\service\transfer\TransferAdapterFactory;
use plugin\paymentchannel\service\transfer\TransferAdapterInterface;
use plugin\saiadmin\basic\think\BaseLogic;

/**
 * 代付回调网关逻辑层（处理上游「代付/提现下发」异步回调）
 *
 * 编排：定位代付通道 → 适配器验签 → 解析统一代付状态 → 委托 {@see WithdrawLogic}
 * 确认成功(扣减冻结)/失败(解冻退款) → 回应上游确认串。
 *
 * 安全与幂等：
 *  - 验签：用上游密钥验签，伪造回调直接拒；
 *  - 幂等与状态机：成败确认下沉到 WithdrawLogic（行内状态判断 + 事务 + LedgerService 幂等键），
 *    重复/乱序回调天然幂等，杜绝重复扣减或重复退款；
 *  - 处理异常回 fail，促使上游重推，等待重试或人工补单。
 *
 * 可测试性：通道加载、适配器工厂、提现逻辑均可注入 / 重写，单测脱离 DB / 网络。
 */
class TransferNotifyLogic extends BaseLogic
{
    /** 回应上游：处理失败（统一回 fail，多数上游据「非成功串」重推） */
    public const RESP_FAIL = 'fail';

    /**
     * 适配器工厂闭包：fn(array $channel): TransferAdapterInterface
     * @var Closure
     */
    private Closure $adapterFactory;

    /**
     * 提现逻辑（承载成败确认 + 资金扣减/退款）
     * @var WithdrawLogic
     */
    private WithdrawLogic $withdrawLogic;

    /**
     * @param Closure|null $adapterFactory 代付适配器工厂（测试可注入假适配器）；null=按通道真实构造
     * @param WithdrawLogic|null $withdrawLogic 提现逻辑（测试可注入）；null=用默认实现
     */
    public function __construct(?Closure $adapterFactory = null, ?WithdrawLogic $withdrawLogic = null)
    {
        $this->adapterFactory = $adapterFactory ?? (fn (array $channel): TransferAdapterInterface
            => TransferAdapterFactory::makeFromChannel($channel));
        $this->withdrawLogic = $withdrawLogic ?? new WithdrawLogic();
    }

    /**
     * 处理上游代付回调
     *
     * @param string $channelCode 通道编码（路由 {channel} 路径参数）
     * @param array $payload 上游回调原始参数
     * @return string 回应上游的纯文本（成功串或 fail）
     */
    public function handleNotify(string $channelCode, array $payload): string
    {
        // 1) 定位代付通道；未知/停用直接拒
        $channel = $this->loadChannel($channelCode);
        if ($channel === null) {
            return self::RESP_FAIL;
        }

        $adapter = ($this->adapterFactory)($channel);

        // 2) 验签：伪造/篡改回调拒之门外
        if (!$adapter->verifyNotify($payload)) {
            return self::RESP_FAIL;
        }

        // 3) 解析为统一代付状态，取平台提现单号（= 发给上游的商户订单号）
        $status = $adapter->parseTransferNotify($payload);
        $withdrawNo = $status->transferNo;
        if ($withdrawNo === '') {
            return self::RESP_FAIL;
        }

        // 4) 委托提现逻辑确认成败（幂等与资金事务在 WithdrawLogic 内）
        try {
            if ($status->isSuccess()) {
                $this->withdrawLogic->confirmSuccess($withdrawNo);
            } elseif ($status->isFailed()) {
                $this->withdrawLogic->confirmFailed($withdrawNo, '上游代付失败:' . $status->message);
            }
            // 处理中：不改账，直接 ack，等待最终态回调
        } catch (\Throwable $e) {
            // 确认异常（事务回滚 / 状态非法）：回 fail，促使上游重推
            return self::RESP_FAIL;
        }

        return $adapter->successResponse();
    }

    // ===== 接缝：DB 访问，默认走 ThinkORM，单测可在子类重写以脱离数据库 =====

    /**
     * 按通道编码加载代付通道（含上游密钥），返回适配器所需数组；未找到/停用返回 null
     *
     * @param string $code 通道编码
     * @return array|null
     */
    protected function loadChannel(string $code): ?array
    {
        $channel = Channel::where('code', $code)->where('status', 1)->find();
        if (!$channel) {
            return null;
        }
        // transfer_adapter 为可选列（可能未建），用 getData 安全读取，避免直接属性访问抛「property not exists」
        $raw = $channel->getData();
        return [
            'id'                   => (int) $channel->id,
            'code'                 => (string) $channel->code,
            'adapter'              => (string) $channel->adapter,
            'transfer_adapter'     => (string) ($raw['transfer_adapter'] ?? ''),
            'gateway_url'          => (string) $channel->gateway_url,
            'upstream_mch_id'      => (string) $channel->upstream_mch_id,
            'upstream_key'         => (string) $channel->upstream_key,
            'upstream_public_key'  => (string) $channel->upstream_public_key,
            'upstream_private_key' => (string) $channel->upstream_private_key,
        ];
    }
}
