<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：端到端集成测试（Mock 上游串联主干闭环，脱离 DB/网络）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\e2e;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\NotifyGatewayLogic;
use plugin\paymentchannel\app\logic\PayGatewayLogic;
use plugin\paymentchannel\app\logic\TransferNotifyLogic;
use plugin\paymentchannel\app\logic\WithdrawLogic;
use plugin\paymentchannel\app\model\CapitalFlow;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\model\Withdraw;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\DayLimitService;
use plugin\paymentchannel\service\LedgerService;
use plugin\paymentchannel\service\RateResolver;
use plugin\paymentchannel\tests\support\TestableRateResolver;
use plugin\paymentchannel\service\MerchantNotifyService;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\transfer\TransferAdapterFactory;
use plugin\paymentchannel\service\transfer\TransferAdapterInterface;

/**
 * 端到端共享内存「世界」（替代真实库）
 *
 * 一个进程内对象，被所有 Logic/Service 的接缝共享读写（PHP 对象按引用传递），
 * 串起「下单→回调→入账→通知→提现→代付回调」全链路而无需真实 DB / Redis / 网络。
 */
class E2EWorld
{
    /** 商户表：id => row（含 balance/balance_freeze/secret_key 等） */
    public array $merchants = [];
    /** 订单表：id => row */
    public array $orders = [];
    /** 提现表：id => row */
    public array $withdraws = [];
    /** 银行卡表：id => row */
    public array $bankCards = [];
    /** 通道表：code => row（含上游密钥） */
    public array $channels = [];
    /** 资金流水：list（每条含 idempotent_key/change_type/change_amount/before/after） */
    public array $flows = [];
    /** 通知日志：list */
    public array $notifyLogs = [];
    /** 幂等锁集合（模拟 Redis SETNX） */
    public array $locks = [];
    /** 商户×通道授权：merchant_id => [channel_id => row] */
    public array $merchantChannels = [];

    /** 订单自增ID */
    private int $orderSeq = 1000;
    /** 提现自增ID */
    private int $withdrawSeq = 2000;

    /** 新增订单，返回ID */
    public function addOrder(array $row): int
    {
        $id = ++$this->orderSeq;
        $row['id'] = $id;
        $this->orders[$id] = $row;
        return $id;
    }

    /** 按订单号查订单 */
    public function findOrderByNo(string $orderNo): ?array
    {
        foreach ($this->orders as $row) {
            if ((string) ($row['order_no'] ?? '') === $orderNo) {
                return $row;
            }
        }
        return null;
    }

    /** 按ID补丁订单 */
    public function patchOrder(int $id, array $patch): void
    {
        if (isset($this->orders[$id])) {
            $this->orders[$id] = array_merge($this->orders[$id], $patch);
        }
    }

    /** 是否存在 (商户,商户单号) 订单 */
    public function orderExists(int $merchantId, string $outTradeNo): bool
    {
        foreach ($this->orders as $row) {
            if ((int) $row['merchant_id'] === $merchantId && (string) $row['out_trade_no'] === $outTradeNo) {
                return true;
            }
        }
        return false;
    }

    /** 新增提现单，返回ID */
    public function addWithdraw(array $row): int
    {
        $id = ++$this->withdrawSeq;
        $row['id'] = $id;
        $this->withdraws[$id] = $row;
        return $id;
    }

    /** 按提现单号查 */
    public function findWithdrawByNo(string $withdrawNo): ?array
    {
        foreach ($this->withdraws as $row) {
            if ((string) ($row['withdraw_no'] ?? '') === $withdrawNo) {
                return $row;
            }
        }
        return null;
    }

    /** 按ID补丁提现单 */
    public function patchWithdraw(int $id, array $patch): void
    {
        if (isset($this->withdraws[$id])) {
            $this->withdraws[$id] = array_merge($this->withdraws[$id], $patch);
        }
    }

    /** 幂等键是否已有流水 */
    public function flowExists(string $key): bool
    {
        foreach ($this->flows as $f) {
            if ((string) ($f['idempotent_key'] ?? '') === $key) {
                return true;
            }
        }
        return false;
    }
}

/** 共享 world 的入账服务 */
class E2ELedgerService extends LedgerService
{
    public function __construct(private E2EWorld $world)
    {
    }

    protected function flowExists(string $idempotentKey): bool
    {
        return $this->world->flowExists($idempotentKey);
    }

    protected function lockMerchant(int $merchantId): ?array
    {
        return $this->world->merchants[$merchantId] ?? null;
    }

    protected function updateBalance(int $merchantId, string $newBalance): void
    {
        $this->world->merchants[$merchantId]['balance'] = $newBalance;
    }

    protected function updateMerchantBalances(int $merchantId, ?string $balance, ?string $freeze): void
    {
        if ($balance !== null) {
            $this->world->merchants[$merchantId]['balance'] = $balance;
        }
        if ($freeze !== null) {
            $this->world->merchants[$merchantId]['balance_freeze'] = $freeze;
        }
    }

    protected function insertFlow(array $data): void
    {
        $this->world->flows[] = $data;
    }

    protected function markSettled(int $orderId): void
    {
        $this->world->patchOrder($orderId, ['settle_status' => Order::SETTLE_DONE]);
    }
}

/** 共享 world 的商户通知服务（注入 SUCCESS 传输） */
class E2EMerchantNotifyService extends MerchantNotifyService
{
    public function __construct(private E2EWorld $world)
    {
        // 注入「恒返回 HTTP 200 + SUCCESS」的传输，模拟商户正常应答
        parent::__construct(fn (string $url, array $body): array => ['http_code' => 200, 'body' => 'SUCCESS']);
    }

    protected function loadMerchantSecret(int $merchantId): array
    {
        $m = $this->world->merchants[$merchantId] ?? [];
        return [
            'secret_key'      => (string) ($m['secret_key'] ?? ''),
            'rsa_private_key' => (string) ($m['rsa_private_key'] ?? ''),
        ];
    }

    protected function createLog(array $data): int
    {
        $id = count($this->world->notifyLogs) + 1;
        $data['id'] = $id;
        $this->world->notifyLogs[] = $data;
        return $id;
    }

    protected function updateLog(int $logId, array $patch): void
    {
        foreach ($this->world->notifyLogs as &$log) {
            if ((int) ($log['id'] ?? 0) === $logId) {
                $log = array_merge($log, $patch);
                break;
            }
        }
    }

    protected function markOrderNotified(string $orderNo, int $notifyStatus): void
    {
        $order = $this->world->findOrderByNo($orderNo);
        if ($order !== null) {
            $this->world->patchOrder((int) $order['id'], ['notify_status' => $notifyStatus]);
        }
    }

    protected function now(): int
    {
        return 1781000000; // 固定时间，确定性
    }
}

/** 共享 world 的下单网关（默认真实 MockAdapter 工厂） */
class E2EPayGatewayLogic extends PayGatewayLogic
{
    public function __construct(private E2EWorld $world)
    {
        parent::__construct(); // 用真实适配器工厂（按通道 adapter=mock 构造 MockAdapter）
    }

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function findDuplicate(int $merchantId, string $outTradeNo): bool
    {
        return $this->world->orderExists($merchantId, $outTradeNo);
    }

    protected function loadAuthorizedChannelIds(int $merchantId): array
    {
        return array_keys($this->world->merchantChannels[$merchantId] ?? []);
    }

    protected function loadMerchantChannelBinding(int $merchantId, int $channelId): ?array
    {
        return $this->world->merchantChannels[$merchantId][$channelId] ?? null;
    }

    protected function getDayLimitService(): DayLimitService
    {
        return new DayLimitService(enabled: false);
    }

    protected function getRateResolver(): RateResolver
    {
        $resolver = new TestableRateResolver();
        $resolver->merchantChannel = $this->world->merchantChannels[1][3] ?? null;
        $resolver->route = ['id' => 7, 'rate' => '0.0000'];
        $resolver->channel = [
            'id'        => 3,
            'rate'      => '1.5000',
            'rate_self' => '2.6000',
        ];
        return $resolver;
    }

    protected function resolveChannel(int $merchantId, int $payType, string $amount, array $authorizedIds): ?array
    {
        // 返回 world 中预置的代收通道（含上游密钥与费率，供 MockAdapter 验签）
        return ['route_id' => 7, 'channel' => $this->world->channels['e2e_pay']];
    }

    protected function persistOrder(array $data): int
    {
        return $this->world->addOrder($data);
    }

    protected function updateOrderResult(int $orderId, array $patch): void
    {
        $this->world->patchOrder($orderId, $patch);
    }
}

/** 共享 world 的回调网关（默认真实 MockAdapter 工厂 + 注入共享入账/通知服务） */
class E2ENotifyGatewayLogic extends NotifyGatewayLogic
{
    public function __construct(private E2EWorld $world, LedgerService $ledger, MerchantNotifyService $notify)
    {
        parent::__construct(null, $ledger, $notify);
    }

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function loadChannel(string $code): ?array
    {
        return $this->world->channels[$code] ?? null;
    }

    protected function loadOrder(string $orderNo): ?array
    {
        return $this->world->findOrderByNo($orderNo);
    }

    protected function markPaid(int $orderId, array $patch): void
    {
        $this->world->patchOrder($orderId, $patch);
    }

    protected function markFailed(int $orderId): void
    {
        $this->world->patchOrder($orderId, ['status' => Order::STATUS_FAILED]);
    }

    protected function acquireLock(string $orderNo): bool
    {
        // 模拟 Redis SETNX：已锁返回 false（并发回调仅一个进入入账）
        if (isset($this->world->locks[$orderNo])) {
            return false;
        }
        $this->world->locks[$orderNo] = 1;
        return true;
    }

    protected function releaseLock(string $orderNo): void
    {
        unset($this->world->locks[$orderNo]);
    }
}

/** 共享 world 的提现逻辑（注入共享入账 + Mock 代付适配器工厂） */
class E2EWithdrawLogic extends WithdrawLogic
{
    public function __construct(private E2EWorld $world, LedgerService $ledger)
    {
        // 注入代付适配器工厂：用 world 的代付通道构造真实 MockTransferAdapter
        parent::__construct($ledger, fn (): TransferAdapterInterface
            => TransferAdapterFactory::makeFromChannel($world->channels['e2e_transfer']));
    }

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function loadBankCard(int $bankCardId): ?array
    {
        return $this->world->bankCards[$bankCardId] ?? null;
    }

    protected function createWithdraw(array $data): int
    {
        return $this->world->addWithdraw($data);
    }

    protected function updateWithdraw(int $id, array $patch): void
    {
        $this->world->patchWithdraw($id, $patch);
    }

    protected function loadWithdraw(int $id): ?array
    {
        return $this->world->withdraws[$id] ?? null;
    }

    protected function loadWithdrawByNo(string $withdrawNo): ?array
    {
        return $this->world->findWithdrawByNo($withdrawNo);
    }

    protected function buildTransferNotifyUrl(string $channelCode = ''): string
    {
        return '';
    }

    protected function loadAuthorizedTransferChannelIds(int $merchantId): array
    {
        $ids = [];
        foreach ($this->world->merchantChannels[$merchantId] ?? [] as $channelId => $row) {
            if ((int) ($row['transfer_enabled'] ?? 0) === MerchantChannel::TRANSFER_ENABLED) {
                $ids[] = (int) $channelId;
            }
        }

        return $ids;
    }

    protected function loadTransferChannelById(int $id): ?array
    {
        foreach ($this->world->channels as $channel) {
            if ((int) ($channel['id'] ?? 0) === $id) {
                return $this->normalizeTransferChannel($channel);
            }
        }

        return null;
    }

    protected function loadEnabledChannelsOrdered(array $ids): array
    {
        $list = [];
        foreach ($ids as $id) {
            $channel = $this->loadTransferChannelById($id);
            if ($channel !== null) {
                $list[] = $channel;
            }
        }
        usort($list, static fn (array $a, array $b): int => ($b['sort'] ?? 0) <=> ($a['sort'] ?? 0));

        return $list;
    }

    protected function getRateResolver(): RateResolver
    {
        $transfer = $this->world->channels['e2e_transfer'];
        $resolver = new TestableRateResolver();
        $resolver->channel = array_merge($transfer, [
            'rate_transfer_self' => (string) ($transfer['rate_transfer_self'] ?? '1.0000'),
        ]);
        $resolver->merchantChannelTransfers = [
            1 => [
                5 => $this->world->merchantChannels[1][5] ?? null,
            ],
        ];

        return $resolver;
    }

    /** 补齐选路/下拉所需字段（Phase 9.5.4：不回退 adapter） */
    private function normalizeTransferChannel(array $channel): array
    {
        return array_merge($channel, [
            'title'            => (string) ($channel['title'] ?? $channel['code'] ?? ''),
            'sort'             => (int) ($channel['sort'] ?? 0),
            'transfer_adapter' => (string) ($channel['transfer_adapter'] ?? ''),
            'channel_biz'      => (int) ($channel['channel_biz'] ?? Channel::BIZ_TRANSFER_ONLY),
        ]);
    }
}

/** 共享 world 的代付回调网关（默认真实 MockTransferAdapter 工厂 + 注入共享提现逻辑） */
class E2ETransferNotifyLogic extends TransferNotifyLogic
{
    public function __construct(private E2EWorld $world, WithdrawLogic $withdrawLogic)
    {
        parent::__construct(null, $withdrawLogic);
    }

    protected function loadChannel(string $code): ?array
    {
        return $this->world->channels[$code] ?? null;
    }
}

/**
 * 端到端集成测试（Mock 上游）
 *
 * 覆盖 README 7.1：用 MockAdapter / MockTransferAdapter 串联「下单→回调→入账→通知→提现→代付回调」
 * 全链路主干闭环，最终断言**账实平**（资金流水汇总 == 商户余额/冻结）。
 * 所有 Logic/Service 经接缝共享同一内存 world，脱离 DB / Redis / 网络。
 */
class E2EFlowTest extends TestCase
{
    /** 代收上游 MD5 密钥（构造回调签名） */
    private const PAY_KEY = 'e2e_up_pay_key';
    /** 代付上游 MD5 密钥 */
    private const TRANSFER_KEY = 'e2e_up_transfer_key';

    private E2EWorld $world;

    protected function setUp(): void
    {
        $this->world = new E2EWorld();

        // 商户：初始余额 0、冻结 0；secret_key 供商户通知签名
        $this->world->merchants[1] = [
            'id'              => 1,
            'mch_id'          => 'E2E001',
            'balance'         => '0.0000',
            'balance_freeze'  => '0.0000',
            'secret_key'      => 'e2e_merchant_secret',
            'rsa_private_key' => '',
        ];

        // 代收通道（adapter=mock，含上游密钥与费率）
        $this->world->channels['e2e_pay'] = [
            'id'                   => 3,
            'code'                 => 'e2e_pay',
            'adapter'              => 'mock',
            'gateway_url'          => '',
            'upstream_mch_id'      => '',
            'upstream_key'         => self::PAY_KEY,
            'upstream_public_key'  => '',
            'upstream_private_key' => '',
            'pay_type'             => 3,
            'rate'                 => '1.5000',
            'rate_self'            => '2.6000',
            'channel_biz'          => Channel::BIZ_PAY_ONLY,
        ];

        // Phase 9.1 严格模式：商户须有通道授权方可代收下单
        $this->world->merchantChannels[1] = [
            3 => ['merchant_id' => 1, 'channel_id' => 3, 'rate' => '0.0000', 'status' => 1],
            // Phase 9.4.2：代付授权与费率（1% 通道默认）
            5 => [
                'merchant_id'      => 1,
                'channel_id'       => 5,
                'rate_transfer'    => '0.0000',
                'transfer_enabled' => MerchantChannel::TRANSFER_ENABLED,
            ],
        ];

        // 代付通道（transfer_adapter=mock_transfer，含上游密钥）
        $this->world->channels['e2e_transfer'] = [
            'id'                   => 5,
            'code'                 => 'e2e_transfer',
            'title'                => 'E2E代付',
            'sort'                 => 100,
            'adapter'              => 'mock_transfer',
            'transfer_adapter'     => 'mock_transfer',
            'channel_biz'          => Channel::BIZ_TRANSFER_ONLY,
            'rate_transfer_self'   => '1.0000',
            'gateway_url'          => '',
            'upstream_mch_id'      => '',
            'upstream_key'         => self::TRANSFER_KEY,
            'upstream_public_key'  => '',
            'upstream_private_key' => '',
        ];

        // 商户银行卡（合法 Luhn 卡号）
        $this->world->bankCards[10] = [
            'id'          => 10,
            'merchant_id' => 1,
            'card_no'     => '6222021234567890123',
            'bank_code'   => 'ICBC',
            'bank_name'   => '工商银行',
            'holder_name' => '张三',
        ];
    }

    /** 商户上下文 */
    private function merchant(): array
    {
        return [
            'id'            => 1,
            'mch_id'        => 'E2E001',
            'rate'          => '2.6',  // 代收费率 2.6%
            'rate_transfer' => '1',    // 代付费率 1%
            'single_min'    => '0',
            'single_max'    => '0',
            'status'        => 1,
        ];
    }

    /**
     * Phase 9.1 严格模式：无 merchant_channel 授权时 submitOrder 被拒
     */
    public function testSubmitOrderRejectedWithoutMerchantChannelAuth(): void
    {
        unset($this->world->merchantChannels[1]);

        $payLogic = new E2EPayGatewayLogic($this->world);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('商户未配置可用支付通道');
        $payLogic->submitOrder($this->merchant(), [
            'order_id'   => 'E2E_STRICT_0001',
            'money'      => '10000',
            'pay_type'   => 3,
            'notify_url' => 'https://m.example.com/n',
            'sign_type'  => 1,
        ]);
    }

    /**
     * 主干闭环：下单→回调入账→商户通知→提现→代付回调，全链路账实平
     */
    public function testMainlineClosedLoopBooksBalance(): void
    {
        $ledger = new E2ELedgerService($this->world);
        $notifySvc = new E2EMerchantNotifyService($this->world);

        // ===== 1) 下单 =====
        $payLogic = new E2EPayGatewayLogic($this->world);
        $submit = $payLogic->submitOrder($this->merchant(), [
            'order_id'   => 'E2E_OUT_0001',
            'money'      => '10000', // 100.00 元
            'pay_type'   => 3,
            'notify_url' => 'https://merchant.example.com/notify',
            'sign_type'  => 1,
        ]);
        $orderNo = $submit['order_no'];

        $this->assertNotEmpty($orderNo);
        $this->assertStringContainsString($orderNo, $submit['pay_url']); // 拿到上游支付链接
        $order = $this->world->findOrderByNo($orderNo);
        $this->assertSame(Order::STATUS_PENDING, (int) $order['status']);
        $this->assertSame('100.0000', AmountHelper::format($order['amount']));
        $this->assertSame('2.6000', AmountHelper::format($order['fee']));
        $this->assertSame('97.4000', AmountHelper::format($order['real_amount']));

        // ===== 2) 上游回调（带签名）→ 入账 + 商户通知 =====
        $notifyLogic = new E2ENotifyGatewayLogic($this->world, $ledger, $notifySvc);
        $callback = $this->signedPayCallback($orderNo, '10000');
        $resp = $notifyLogic->handleNotify('e2e_pay', $callback);

        $this->assertSame('success', $resp); // MockAdapter 成功串
        $order = $this->world->findOrderByNo($orderNo);
        $this->assertSame(Order::STATUS_PAID, (int) $order['status']);
        $this->assertSame(Order::SETTLE_DONE, (int) $order['settle_status']);
        $this->assertSame(1, (int) $order['notify_status']); // 商户通知成功
        // 入账：余额 0 → 97.4
        $this->assertSame('97.4000', AmountHelper::format($this->world->merchants[1]['balance']));
        // 1 条代收入账流水 + 1 条通知日志
        $this->assertCount(1, $this->payInFlows());
        $this->assertCount(1, $this->world->notifyLogs);

        // ===== 3) 重复回调幂等（不重复入账） =====
        $resp2 = $notifyLogic->handleNotify('e2e_pay', $callback);
        $this->assertSame('success', $resp2);
        $this->assertSame('97.4000', AmountHelper::format($this->world->merchants[1]['balance']));
        $this->assertCount(1, $this->payInFlows()); // 流水仍 1 条

        // ===== 4) 商户申请提现 50 元 =====
        $withdrawLogic = new E2EWithdrawLogic($this->world, $ledger);
        $apply = $withdrawLogic->apply($this->merchant(), [
            'amount'       => '50',
            'bank_card_id' => 10,
        ]);
        $withdrawNo = $apply['withdraw_no'];

        // 冻结：可用 97.4 → 47.4，冻结 0 → 50；手续费 1% = 0.5，到卡 49.5
        $this->assertSame('47.4000', AmountHelper::format($this->world->merchants[1]['balance']));
        $this->assertSame('50.0000', AmountHelper::format($this->world->merchants[1]['balance_freeze']));
        $this->assertSame('0.5000', AmountHelper::format($apply['fee']));
        $this->assertSame('49.5000', AmountHelper::format($apply['real_amount']));
        $withdraw = $this->world->findWithdrawByNo($withdrawNo);
        $this->assertSame(Withdraw::STATUS_PENDING, (int) $withdraw['status']);

        // ===== 5) 审核通过 → 下发代付 =====
        $audit = $withdrawLogic->audit((int) $withdraw['id'], 'disburse', 99);
        $this->assertSame('paying', $audit['result']);
        $withdraw = $this->world->findWithdrawByNo($withdrawNo);
        $this->assertSame(Withdraw::STATUS_PAYING, (int) $withdraw['status']);
        $this->assertNotEmpty($withdraw['transfer_no']); // 回写上游代付单号

        // ===== 6) 代付回调成功（带签名）→ 扣减冻结 =====
        $transferNotify = new E2ETransferNotifyLogic($this->world, $withdrawLogic);
        $tCallback = $this->signedTransferCallback($withdrawNo, '4950'); // 到卡金额（分）
        $tResp = $transferNotify->handleNotify('e2e_transfer', $tCallback);

        $this->assertSame('success', $tResp);
        $withdraw = $this->world->findWithdrawByNo($withdrawNo);
        $this->assertSame(Withdraw::STATUS_SUCCESS, (int) $withdraw['status']);
        // 冻结 50 → 0（钱出账）；可用仍 47.4
        $this->assertSame('0.0000', AmountHelper::format($this->world->merchants[1]['balance_freeze']));
        $this->assertSame('47.4000', AmountHelper::format($this->world->merchants[1]['balance']));

        // ===== 7) 账实平（核心）：流水汇总 == 余额/冻结，且每条 before+change==after =====
        $this->assertBooksBalance();
    }

    /**
     * 重复代付回调幂等：成功后再回放不重复扣减
     */
    public function testTransferCallbackIdempotent(): void
    {
        $ledger = new E2ELedgerService($this->world);
        $notifySvc = new E2EMerchantNotifyService($this->world);

        // 先把订单跑到已入账（复用主链路前 3 步的精简版）
        $payLogic = new E2EPayGatewayLogic($this->world);
        $submit = $payLogic->submitOrder($this->merchant(), [
            'order_id' => 'E2E_OUT_0002', 'money' => '10000', 'pay_type' => 3,
            'notify_url' => 'https://m.example.com/n', 'sign_type' => 1,
        ]);
        $orderNo = $submit['order_no'];
        $notifyLogic = new E2ENotifyGatewayLogic($this->world, $ledger, $notifySvc);
        $notifyLogic->handleNotify('e2e_pay', $this->signedPayCallback($orderNo, '10000'));

        // 提现 + 审核下发
        $withdrawLogic = new E2EWithdrawLogic($this->world, $ledger);
        $apply = $withdrawLogic->apply($this->merchant(), ['amount' => '50', 'bank_card_id' => 10]);
        $withdrawNo = $apply['withdraw_no'];
        $w = $this->world->findWithdrawByNo($withdrawNo);
        $withdrawLogic->audit((int) $w['id'], 'disburse', 99);

        $transferNotify = new E2ETransferNotifyLogic($this->world, $withdrawLogic);
        $cb = $this->signedTransferCallback($withdrawNo, '4950');

        // 第一次成功
        $this->assertSame('success', $transferNotify->handleNotify('e2e_transfer', $cb));
        $freezeAfterFirst = $this->world->merchants[1]['balance_freeze'];
        $deductCount = count($this->deductFlows());

        // 第二次重复回放：幂等，冻结不再变、扣减流水不增加
        $this->assertSame('success', $transferNotify->handleNotify('e2e_transfer', $cb));
        $this->assertSame(
            AmountHelper::format($freezeAfterFirst),
            AmountHelper::format($this->world->merchants[1]['balance_freeze'])
        );
        $this->assertCount($deductCount, $this->deductFlows());

        $this->assertBooksBalance();
    }

    // ===== 辅助 =====

    /** 构造带 MD5 签名的代收回调 */
    private function signedPayCallback(string $orderNo, string $moneyCents): array
    {
        $payload = [
            'out_trade_no' => $orderNo,
            'trade_no'     => 'MOCK' . $orderNo,
            'money'        => $moneyCents,
            'status'       => 'success',
        ];
        $payload['sign'] = SignService::makeSign($payload, self::PAY_KEY, SignService::SIGN_TYPE_MD5);
        return $payload;
    }

    /** 构造带 MD5 签名的代付回调 */
    private function signedTransferCallback(string $withdrawNo, string $moneyCents): array
    {
        $payload = [
            'out_trade_no' => $withdrawNo,
            'trade_no'     => 'MOCKT' . $withdrawNo,
            'money'        => $moneyCents,
            'status'       => 'success',
        ];
        $payload['sign'] = SignService::makeSign($payload, self::TRANSFER_KEY, SignService::SIGN_TYPE_MD5);
        return $payload;
    }

    /** 代收入账流水 */
    private function payInFlows(): array
    {
        return array_filter($this->world->flows, fn ($f) => (int) $f['biz_type'] === CapitalFlow::BIZ_PAY_IN);
    }

    /** 提现成功扣减流水 */
    private function deductFlows(): array
    {
        return array_filter($this->world->flows, fn ($f) => (int) $f['biz_type'] === CapitalFlow::BIZ_WITHDRAW_DEDUCT);
    }

    /**
     * 账实平断言（核心资金核对）：
     *  - 每条流水 before + change == after（快照自洽）；
     *  - 可用余额账户(BALANCE)流水变动汇总 == 商户当前可用余额；
     *  - 冻结余额账户(FREEZE)流水变动汇总 == 商户当前冻结余额。
     */
    private function assertBooksBalance(): void
    {
        $balanceSum = '0';
        $freezeSum = '0';
        foreach ($this->world->flows as $f) {
            // 快照自洽
            $this->assertSame(
                AmountHelper::format($f['after_balance']),
                AmountHelper::format(AmountHelper::add($f['before_balance'], $f['change_amount'])),
                '流水快照不自洽：' . ($f['idempotent_key'] ?? '')
            );
            if ((int) $f['change_type'] === CapitalFlow::ACCOUNT_BALANCE) {
                $balanceSum = AmountHelper::add($balanceSum, $f['change_amount']);
            } elseif ((int) $f['change_type'] === CapitalFlow::ACCOUNT_FREEZE) {
                $freezeSum = AmountHelper::add($freezeSum, $f['change_amount']);
            }
        }

        $this->assertSame(
            AmountHelper::format($this->world->merchants[1]['balance']),
            AmountHelper::format($balanceSum),
            '账实不平：可用余额流水汇总 != 商户可用余额'
        );
        $this->assertSame(
            AmountHelper::format($this->world->merchants[1]['balance_freeze']),
            AmountHelper::format($freezeSum),
            '账实不平：冻结余额流水汇总 != 商户冻结余额'
        );
    }
}
