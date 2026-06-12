<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：即时入账服务测试（DB 接缝重写，脱离数据库）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\model\CapitalFlow;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\service\LedgerService;
use RuntimeException;

/**
 * 可测试的入账服务：用内存替代 DB，记录余额/流水/订单标记，便于断言资金一致性。
 */
class TestableLedgerService extends LedgerService
{
    /** 已存在的幂等键集合（模拟流水唯一约束） */
    public array $existingKeys = [];
    /** 模拟商户余额表：merchantId => ['balance' => string] */
    public array $merchants = [];
    /** 商户不存在开关 */
    public bool $merchantMissing = false;

    /** 捕获的流水写入 */
    public array $flows = [];
    /** 余额更新次数 */
    public int $balanceUpdates = 0;
    /** 订单入账标记次数 */
    public int $settledCalls = 0;

    protected function flowExists(string $idempotentKey): bool
    {
        return in_array($idempotentKey, $this->existingKeys, true);
    }

    protected function lockMerchant(int $merchantId): ?array
    {
        if ($this->merchantMissing) {
            return null;
        }
        return $this->merchants[$merchantId] ?? ['id' => $merchantId, 'balance' => '0.0000', 'balance_freeze' => '0.0000'];
    }

    protected function updateBalance(int $merchantId, string $newBalance): void
    {
        $this->merchants[$merchantId]['balance'] = $newBalance;
        $this->balanceUpdates++;
    }

    /**
     * 重写双账户余额更新接缝：同步内存中的可用余额与冻结余额
     */
    protected function updateMerchantBalances(int $merchantId, ?string $balance, ?string $freeze): void
    {
        if ($balance !== null) {
            $this->merchants[$merchantId]['balance'] = $balance;
        }
        if ($freeze !== null) {
            $this->merchants[$merchantId]['balance_freeze'] = $freeze;
        }
        $this->balanceUpdates++;
    }

    protected function insertFlow(array $data): void
    {
        // 模拟唯一约束：重复 idempotent_key 抛异常
        if (in_array($data['idempotent_key'], $this->existingKeys, true)) {
            throw new RuntimeException('duplicate idempotent_key');
        }
        $this->existingKeys[] = $data['idempotent_key'];
        $this->flows[] = $data;
    }

    protected function markSettled(int $orderId): void
    {
        $this->settledCalls++;
    }
}

/**
 * 即时入账服务测试
 *
 * 覆盖：成功入账（余额 += real_amount、流水快照一致）、幂等跳过、商户不存在异常、
 * 小数精度、零金额、流水字段正确性。全部脱离 DB。
 */
class LedgerServiceTest extends TestCase
{
    /** 标准订单 */
    private function order(array $override = []): array
    {
        return array_merge([
            'id'          => 1001,
            'merchant_id' => 7,
            'mch_id'      => 'M007',
            'order_no'    => 'P20260608120000001',
            'amount'      => '100.0000',
            'fee'         => '2.6000',
            'real_amount' => '97.4000',
        ], $override);
    }

    /**
     * 成功入账：余额从 0 增加 97.4，流水快照 before/after/change 一致，订单被标记入账
     */
    public function testCreditSuccess(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '0.0000'];

        $done = $svc->credit($this->order());

        $this->assertTrue($done);
        $this->assertSame('97.4000', $svc->merchants[7]['balance']);
        $this->assertSame(1, $svc->balanceUpdates);
        $this->assertSame(1, $svc->settledCalls);
        $this->assertCount(1, $svc->flows);

        $flow = $svc->flows[0];
        $this->assertSame(CapitalFlow::BIZ_PAY_IN, $flow['biz_type']);
        $this->assertSame(CapitalFlow::ACCOUNT_BALANCE, $flow['change_type']);
        $this->assertSame('97.4000', $flow['change_amount']);
        $this->assertSame('0.0000', $flow['before_balance']);
        $this->assertSame('97.4000', $flow['after_balance']);
        $this->assertSame('P20260608120000001', $flow['biz_no']);
        $this->assertSame('pay_in:P20260608120000001', $flow['idempotent_key']);
        // 流水快照自洽：before + change == after
        $this->assertSame(
            $flow['after_balance'],
            \plugin\paymentchannel\service\AmountHelper::add($flow['before_balance'], $flow['change_amount'])
        );
    }

    /**
     * 累加入账：已有余额 50，入账 97.4 → 147.4
     */
    public function testCreditAccumulates(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '50.0000'];

        $svc->credit($this->order());

        $this->assertSame('147.4000', $svc->merchants[7]['balance']);
        $this->assertSame('50.0000', $svc->flows[0]['before_balance']);
        $this->assertSame('147.4000', $svc->flows[0]['after_balance']);
    }

    /**
     * 幂等：相同订单已入账 → 跳过，不重复加余额、不写流水
     */
    public function testIdempotentSkip(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '0.0000'];
        // 预置该订单的幂等键，模拟「已入账」
        $svc->existingKeys[] = 'pay_in:P20260608120000001';

        $done = $svc->credit($this->order());

        $this->assertFalse($done);
        $this->assertSame('0.0000', $svc->merchants[7]['balance']);
        $this->assertSame(0, $svc->balanceUpdates);
        $this->assertCount(0, $svc->flows);
        $this->assertSame(0, $svc->settledCalls);
    }

    /**
     * 商户不存在 → 抛异常（触发上层事务回滚）
     */
    public function testMerchantMissingThrows(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchantMissing = true;

        $this->expectException(RuntimeException::class);
        $svc->credit($this->order());
    }

    /**
     * 小数精度：分账金额 0.01 累加无浮点误差
     */
    public function testDecimalPrecision(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '0.1000'];

        $svc->credit($this->order(['real_amount' => '0.2000', 'order_no' => 'P_DEC']));

        // 0.1 + 0.2 = 0.3000（非 0.30000000004）
        $this->assertSame('0.3000', $svc->merchants[7]['balance']);
    }

    /**
     * 两笔不同订单累计入账：余额正确累加，写两条流水
     */
    public function testTwoOrdersAccumulate(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '0.0000'];

        $svc->credit($this->order(['order_no' => 'P_A', 'real_amount' => '10.0000']));
        $svc->credit($this->order(['order_no' => 'P_B', 'real_amount' => '20.5000']));

        $this->assertSame('30.5000', $svc->merchants[7]['balance']);
        $this->assertCount(2, $svc->flows);
        // 第二条流水的 before == 第一条的 after，账链连续
        $this->assertSame($svc->flows[0]['after_balance'], $svc->flows[1]['before_balance']);
    }

    // ===== 提现资金操作：冻结 / 扣减 / 退款（Phase 4.2）=====

    /**
     * 提现冻结：可用 100 → 冻结 30。可用余额 -30、冻结余额 +30，写两条流水（双账户），账实一致
     */
    public function testFreezeWithdraw(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '100.0000', 'balance_freeze' => '0.0000'];

        $svc->freezeWithdraw(7, 'M007', 'W001', '30.0000');

        $this->assertSame('70.0000', $svc->merchants[7]['balance']);
        $this->assertSame('30.0000', $svc->merchants[7]['balance_freeze']);
        $this->assertCount(2, $svc->flows);

        // 流水1：可用余额 -30
        $bal = $svc->flows[0];
        $this->assertSame(CapitalFlow::BIZ_WITHDRAW_FREEZE, $bal['biz_type']);
        $this->assertSame(CapitalFlow::ACCOUNT_BALANCE, $bal['change_type']);
        $this->assertSame('-30.0000', $bal['change_amount']);
        $this->assertSame('100.0000', $bal['before_balance']);
        $this->assertSame('70.0000', $bal['after_balance']);
        $this->assertSame('wd_freeze_bal:W001', $bal['idempotent_key']);

        // 流水2：冻结余额 +30
        $frz = $svc->flows[1];
        $this->assertSame(CapitalFlow::ACCOUNT_FREEZE, $frz['change_type']);
        $this->assertSame('30.0000', $frz['change_amount']);
        $this->assertSame('0.0000', $frz['before_balance']);
        $this->assertSame('30.0000', $frz['after_balance']);
        $this->assertSame('wd_freeze_frz:W001', $frz['idempotent_key']);
    }

    /**
     * 余额不足拒绝：可用 10 < 提现 30 → 抛异常，不动余额、不写流水
     */
    public function testFreezeInsufficientThrows(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '10.0000', 'balance_freeze' => '0.0000'];

        try {
            $svc->freezeWithdraw(7, 'M007', 'W001', '30.0000');
            $this->fail('余额不足应抛异常');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('余额不足', $e->getMessage());
        }
        $this->assertSame('10.0000', $svc->merchants[7]['balance']);
        $this->assertSame('0.0000', $svc->merchants[7]['balance_freeze']);
        $this->assertCount(0, $svc->flows);
    }

    /**
     * 冻结刚好等于余额：边界放行（compare >= 0）
     */
    public function testFreezeExactBalanceAllowed(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '30.0000', 'balance_freeze' => '0.0000'];

        $svc->freezeWithdraw(7, 'M007', 'W001', '30.0000');

        $this->assertSame('0.0000', $svc->merchants[7]['balance']);
        $this->assertSame('30.0000', $svc->merchants[7]['balance_freeze']);
    }

    /**
     * 冻结幂等：已冻结（幂等键存在）→ 跳过，不重复扣减
     */
    public function testFreezeIdempotent(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '100.0000', 'balance_freeze' => '0.0000'];
        $svc->existingKeys[] = 'wd_freeze_bal:W001';

        $svc->freezeWithdraw(7, 'M007', 'W001', '30.0000');

        $this->assertSame('100.0000', $svc->merchants[7]['balance']);
        $this->assertSame('0.0000', $svc->merchants[7]['balance_freeze']);
        $this->assertCount(0, $svc->flows);
    }

    /**
     * 提现成功扣减：冻结 30 → 扣减 30（钱出账）。冻结余额 -30、可用不变，写一条流水
     */
    public function testDeductWithdraw(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '70.0000', 'balance_freeze' => '30.0000'];

        $svc->deductWithdraw(7, 'M007', 'W001', '30.0000');

        $this->assertSame('70.0000', $svc->merchants[7]['balance']);
        $this->assertSame('0.0000', $svc->merchants[7]['balance_freeze']);
        $this->assertCount(1, $svc->flows);

        $flow = $svc->flows[0];
        $this->assertSame(CapitalFlow::BIZ_WITHDRAW_DEDUCT, $flow['biz_type']);
        $this->assertSame(CapitalFlow::ACCOUNT_FREEZE, $flow['change_type']);
        $this->assertSame('-30.0000', $flow['change_amount']);
        $this->assertSame('30.0000', $flow['before_balance']);
        $this->assertSame('0.0000', $flow['after_balance']);
        $this->assertSame('wd_deduct_frz:W001', $flow['idempotent_key']);
    }

    /**
     * 提现退款：冻结 30 → 退款解冻。冻结余额 -30、可用 +30，写两条流水，资金回到可用
     */
    public function testRefundWithdraw(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '70.0000', 'balance_freeze' => '30.0000'];

        $svc->refundWithdraw(7, 'M007', 'W001', '30.0000');

        $this->assertSame('100.0000', $svc->merchants[7]['balance']);
        $this->assertSame('0.0000', $svc->merchants[7]['balance_freeze']);
        $this->assertCount(2, $svc->flows);

        // 流水1：冻结 -30
        $frz = $svc->flows[0];
        $this->assertSame(CapitalFlow::BIZ_WITHDRAW_REFUND, $frz['biz_type']);
        $this->assertSame(CapitalFlow::ACCOUNT_FREEZE, $frz['change_type']);
        $this->assertSame('-30.0000', $frz['change_amount']);
        $this->assertSame('wd_refund_frz:W001', $frz['idempotent_key']);

        // 流水2：可用 +30
        $bal = $svc->flows[1];
        $this->assertSame(CapitalFlow::ACCOUNT_BALANCE, $bal['change_type']);
        $this->assertSame('30.0000', $bal['change_amount']);
        $this->assertSame('70.0000', $bal['before_balance']);
        $this->assertSame('100.0000', $bal['after_balance']);
        $this->assertSame('wd_refund_bal:W001', $bal['idempotent_key']);
    }

    /**
     * 冻结→退款 资金守恒：先冻结再退款，可用余额回到初始，冻结归零，账实一致
     */
    public function testFreezeThenRefundConservesMoney(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '100.0000', 'balance_freeze' => '0.0000'];

        $svc->freezeWithdraw(7, 'M007', 'W001', '40.0000');
        $svc->refundWithdraw(7, 'M007', 'W001', '40.0000');

        // 冻结再退款，钱回到可用，总额守恒
        $this->assertSame('100.0000', $svc->merchants[7]['balance']);
        $this->assertSame('0.0000', $svc->merchants[7]['balance_freeze']);
    }

    /**
     * 冻结→扣减 资金守恒：先冻结再成功扣减，可用减少、冻结归零，钱真正离开账户
     */
    public function testFreezeThenDeductLeavesAccount(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '100.0000', 'balance_freeze' => '0.0000'];

        $svc->freezeWithdraw(7, 'M007', 'W001', '40.0000');
        $svc->deductWithdraw(7, 'M007', 'W001', '40.0000');

        // 可用 60、冻结 0：钱已出账，账实一致
        $this->assertSame('60.0000', $svc->merchants[7]['balance']);
        $this->assertSame('0.0000', $svc->merchants[7]['balance_freeze']);
    }

    // ===== 充值入账（Phase 4.3）=====

    /**
     * 充值入账：可用 100 → 充值 50 → 150。可用余额 +50，写一条充值流水（无冻结、无手续费）
     */
    public function testCreditRecharge(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '100.0000', 'balance_freeze' => '0.0000'];

        $svc->creditRecharge(7, 'M007', 'R001', '50.0000');

        $this->assertSame('150.0000', $svc->merchants[7]['balance']);
        $this->assertCount(1, $svc->flows);

        $flow = $svc->flows[0];
        $this->assertSame(CapitalFlow::BIZ_RECHARGE, $flow['biz_type']);
        $this->assertSame(CapitalFlow::ACCOUNT_BALANCE, $flow['change_type']);
        $this->assertSame('50.0000', $flow['change_amount']);
        $this->assertSame('100.0000', $flow['before_balance']);
        $this->assertSame('150.0000', $flow['after_balance']);
        $this->assertSame('R001', $flow['biz_no']);
        $this->assertSame('recharge:R001', $flow['idempotent_key']);
        // 流水快照自洽：before + change == after
        $this->assertSame(
            $flow['after_balance'],
            \plugin\paymentchannel\service\AmountHelper::add($flow['before_balance'], $flow['change_amount'])
        );
    }

    /**
     * 充值入账幂等：同一充值单已入账（幂等键存在）→ 跳过，不重复加余额、不写流水
     */
    public function testCreditRechargeIdempotent(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '100.0000', 'balance_freeze' => '0.0000'];
        $svc->existingKeys[] = 'recharge:R001';

        $svc->creditRecharge(7, 'M007', 'R001', '50.0000');

        $this->assertSame('100.0000', $svc->merchants[7]['balance']);
        $this->assertCount(0, $svc->flows);
    }

    /**
     * 充值入账：商户不存在 → 抛异常（触发上层事务回滚）
     */
    public function testCreditRechargeMerchantMissingThrows(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchantMissing = true;

        $this->expectException(RuntimeException::class);
        $svc->creditRecharge(7, 'M007', 'R001', '50.0000');
    }

    /**
     * 充值入账：小数金额精度（0.1 + 0.2 = 0.3000 无浮点误差）
     */
    public function testCreditRechargeDecimalPrecision(): void
    {
        $svc = new TestableLedgerService();
        $svc->merchants[7] = ['id' => 7, 'balance' => '0.1000', 'balance_freeze' => '0.0000'];

        $svc->creditRecharge(7, 'M007', 'R_DEC', '0.2000');

        $this->assertSame('0.3000', $svc->merchants[7]['balance']);
    }
}
