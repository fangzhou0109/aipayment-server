<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游回调网关逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use Closure;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\channel\ChannelAdapterFactory;
use plugin\paymentchannel\service\channel\ChannelAdapterInterface;
use plugin\paymentchannel\service\channel\dto\PaymentOutcome;
use plugin\paymentchannel\service\LedgerService;
use plugin\paymentchannel\service\MerchantNotifyService;
use plugin\saiadmin\basic\think\BaseLogic;
use support\Redis;
use Throwable;

/**
 * 上游回调网关逻辑层
 *
 * 编排「上游异步回调」全流程：定位通道 → 适配器验签 → 解析状态 → 查单（限待支付）→
 * 金额校验 → Redis 幂等锁 → 事务改单为已支付 + 入账（LedgerService）→ 触发商户通知
 * （MerchantNotifyService）→ 回应上游成功串。
 *
 * 安全与幂等（资金核心）：
 *  - 验签：用「上游密钥」验签，伪造回调直接拒；
 *  - 限待支付：仅 status=待支付 的订单可被改为已支付；已支付的重复回调直接 ack，杜绝重复入账；
 *  - 金额校验：回调金额必须与订单金额一致，防金额篡改；
 *  - Redis SETNX 幂等锁：并发回调仅一个进入入账事务，其余 ack；
 *  - 改单与入账「同一事务」：账实一致，异常整体回滚并释放锁以便上游重试。
 *
 * 可测试性：DB / Redis 访问与「入账、通知」均抽为可重写保护方法 / 可注入闭包，
 * 单测以子类重写接缝 + 注入假适配器，全分支脱离 DB / Redis / 网络。
 */
class NotifyGatewayLogic extends BaseLogic
{
    /** 回应上游：处理失败（各上游对失败串要求不一，统一回 fail，多数上游据「非成功串」重推） */
    public const RESP_FAIL = 'fail';

    /** 幂等锁 TTL（秒）：覆盖单次回调处理时长，过期后由「订单已支付」状态兜底防重 */
    private const LOCK_TTL = 300;

    /**
     * 适配器工厂闭包：fn(array $channel): ChannelAdapterInterface
     * @var Closure
     */
    private Closure $adapterFactory;

    /**
     * 入账服务（即时记账）
     * @var LedgerService
     */
    private LedgerService $ledger;

    /**
     * 商户异步通知服务
     * @var MerchantNotifyService
     */
    private MerchantNotifyService $merchantNotify;

    /**
     * @param Closure|null $adapterFactory 适配器工厂（测试可注入假适配器）；null=按通道真实构造
     * @param LedgerService|null $ledger 入账服务（测试可注入）；null=用默认实现
     * @param MerchantNotifyService|null $merchantNotify 商户通知服务（测试可注入）；null=用默认实现
     */
    public function __construct(
        ?Closure $adapterFactory = null,
        ?LedgerService $ledger = null,
        ?MerchantNotifyService $merchantNotify = null,
    ) {
        $this->model = new Order();
        $this->adapterFactory = $adapterFactory ?? (fn (array $channel): ChannelAdapterInterface
            => ChannelAdapterFactory::makeFromChannel($channel));
        $this->ledger = $ledger ?? new LedgerService();
        $this->merchantNotify = $merchantNotify ?? new MerchantNotifyService();
    }

    /**
     * 处理上游回调
     *
     * @param string $channelCode 通道编码（路由 {channel} 路径参数）
     * @param array $payload 上游回调原始参数
     * @return string 回应上游的纯文本（成功串或 fail）
     */
    public function handleNotify(string $channelCode, array $payload): string
    {
        // 1) 定位通道；未知通道直接拒
        $channel = $this->loadChannel($channelCode);
        if ($channel === null) {
            return self::RESP_FAIL;
        }

        $adapter = ($this->adapterFactory)($channel);

        // 2) 验签：伪造/篡改回调拒之门外
        if (!$adapter->verifyNotify($payload)) {
            return self::RESP_FAIL;
        }

        // 3) 解析为统一状态，取平台订单号
        $status = $adapter->parseNotify($payload);
        $orderNo = $status->orderNo;
        if ($orderNo === '') {
            return self::RESP_FAIL;
        }

        // 4) 查单
        $order = $this->loadOrder($orderNo);
        if ($order === null) {
            return self::RESP_FAIL;
        }
        $currentStatus = (int) $order['status'];

        // 5) 幂等：已支付订单的重复回调，直接回应成功（不再入账）
        if ($currentStatus === Order::STATUS_PAID) {
            return $adapter->successResponse();
        }
        // 仅「待支付」订单允许状态流转；已关闭/已失败不再处理
        if ($currentStatus !== Order::STATUS_PENDING) {
            return self::RESP_FAIL;
        }

        // 6) 上游明确失败 → 置订单失败并 ack（无资金变动）
        if ($status->outcome === PaymentOutcome::Failed) {
            $this->markFailed((int) $order['id']);
            return $adapter->successResponse();
        }
        // 上游未支付（待支付态回调）→ 不改单，直接 ack
        if (!$status->isPaid()) {
            return $adapter->successResponse();
        }

        // 7) 金额校验：回调金额须与订单金额一致，防篡改（bcmath 比较，禁浮点）
        if (AmountHelper::compare($status->amount, (string) $order['amount']) !== 0) {
            return self::RESP_FAIL;
        }

        // 8) Redis SETNX 幂等锁：并发回调仅一个进入入账，其余直接 ack
        if (!$this->acquireLock($orderNo)) {
            return $adapter->successResponse();
        }

        // 9) 事务：改单为已支付 + 入账（同一事务保账实一致）；异常释放锁以便上游重试
        try {
            $this->transaction(function () use ($order, $status) {
                $this->markPaid((int) $order['id'], [
                    'status'      => Order::STATUS_PAID,
                    'upstream_no' => $status->upstreamNo,
                    'pay_time'    => date('Y-m-d H:i:s'),
                ]);
                // 入账接缝（Phase 3.4 接入 LedgerService）；当前为占位空实现
                $this->applyLedger($order);
            });
        } catch (Throwable $e) {
            $this->releaseLock($orderNo);
            throw $e;
        }

        // 10) 触发商户异步通知接缝（Phase 3.5 接入）；放事务外，避免通知 I/O 拖长事务
        $this->triggerMerchantNotify($order);

        return $adapter->successResponse();
    }

    // ===== DB / Redis / 下游接缝：默认实现走真实设施，单测可在子类重写以脱离依赖 =====

    /**
     * 按通道编码加载通道（含上游密钥），返回适配器所需数组；未找到/停用返回 null
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
        return [
            'id'                   => (int) $channel->id,
            'code'                 => (string) $channel->code,
            'adapter'              => (string) $channel->adapter,
            'gateway_url'          => (string) $channel->gateway_url,
            'upstream_mch_id'      => (string) $channel->upstream_mch_id,
            'upstream_key'         => (string) $channel->upstream_key,
            'upstream_public_key'  => (string) $channel->upstream_public_key,
            'upstream_private_key' => (string) $channel->upstream_private_key,
            'pay_type'             => (int) $channel->pay_type,
        ];
    }

    /**
     * 按平台订单号查单，返回数组；未找到返回 null
     *
     * @param string $orderNo 平台订单号
     * @return array|null
     */
    protected function loadOrder(string $orderNo): ?array
    {
        $order = Order::where('order_no', $orderNo)->find();
        return $order ? $order->toArray() : null;
    }

    /**
     * 改单为已支付（回写上游单号与支付时间）
     *
     * @param int $orderId 订单ID
     * @param array $patch 待更新字段
     */
    protected function markPaid(int $orderId, array $patch): void
    {
        Order::where('id', $orderId)->update($patch);
    }

    /**
     * 改单为支付失败
     *
     * @param int $orderId 订单ID
     */
    protected function markFailed(int $orderId): void
    {
        Order::where('id', $orderId)->update(['status' => Order::STATUS_FAILED]);
    }

    /**
     * 获取幂等锁（Redis SETNX + 过期）：成功返回 true
     *
     * @param string $orderNo 平台订单号
     * @return bool
     */
    protected function acquireLock(string $orderNo): bool
    {
        try {
            // phpredis：set(key, val, ['NX','EX'=>ttl]) 原子「不存在才设置并带过期」
            return (bool) Redis::set($this->lockKey($orderNo), '1', ['NX', 'EX' => self::LOCK_TTL]);
        } catch (Throwable) {
            // Redis 不可用时降级放行（由「订单已支付」状态兜底防重），不阻断入账主流程
            return true;
        }
    }

    /**
     * 释放幂等锁
     *
     * @param string $orderNo 平台订单号
     */
    protected function releaseLock(string $orderNo): void
    {
        try {
            Redis::del($this->lockKey($orderNo));
        } catch (Throwable) {
            // 释放失败仅影响锁自然过期前的重试，忽略
        }
    }

    /**
     * 幂等锁键
     *
     * @param string $orderNo 平台订单号
     * @return string
     */
    private function lockKey(string $orderNo): string
    {
        return 'pay:notify:lock:' . $orderNo;
    }

    /**
     * 入账接缝：在「改单事务」内对商户记账（调用 LedgerService 即时入账）
     *
     * 可用余额 += real_amount，写资金流水（幂等键 + 前后余额快照），标记订单已入账。
     * 与「改单」同一事务，账实一致；幂等由 LedgerService 内部（流水唯一索引）保证。
     *
     * @param array $order 订单数据
     */
    protected function applyLedger(array $order): void
    {
        $this->ledger->credit($order);
    }

    /**
     * 商户通知接缝：入账成功后触发对商户的异步通知（调 MerchantNotifyService）
     *
     * 放事务外调用（避免通知 I/O 拖长入账事务）；首发失败会落库并由重试进程接管。
     *
     * @param array $order 订单数据
     */
    protected function triggerMerchantNotify(array $order): void
    {
        $this->merchantNotify->dispatch($order);
    }
}
