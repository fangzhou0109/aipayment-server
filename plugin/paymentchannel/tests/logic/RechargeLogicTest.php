<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户充值逻辑测试（注入假资金服务，DB 接缝重写，脱离 DB）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\RechargeLogic;
use plugin\paymentchannel\app\model\Recharge;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\LedgerService;

/**
 * 假资金服务：内存模拟商户可用余额，记录充值入账调用，断言审核通过才入账。
 */
class FakeRechargeLedgerService extends LedgerService
{
    /** 可用余额 */
    public string $balance = '100.0000';
    /** 充值入账调用记录：[rechargeNo, amount] */
    public array $credits = [];

    public function creditRecharge(int $merchantId, string $mchId, string $rechargeNo, string $amount): void
    {
        $this->balance = AmountHelper::add($this->balance, $amount);
        $this->credits[] = [$rechargeNo, AmountHelper::format($amount)];
    }
}

/**
 * 可测试的充值逻辑：内存替代充值单表，重写事务与 DB 接缝，脱离数据库。
 */
class TestableRechargeLogic extends RechargeLogic
{
    /** 内存充值单表：id => row */
    public array $recharges = [];
    /** 自增ID */
    private int $autoId = 3000;

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function loadRecharge(int $id): ?array
    {
        return $this->recharges[$id] ?? null;
    }

    protected function createRecharge(array $data): int
    {
        $id = ++$this->autoId;
        $this->recharges[$id] = array_merge(['id' => $id], $data);
        return $id;
    }

    protected function updateRecharge(int $id, array $patch): void
    {
        if (isset($this->recharges[$id])) {
            $this->recharges[$id] = array_merge($this->recharges[$id], $patch);
        }
    }
}

/**
 * 商户充值逻辑测试
 *
 * 覆盖 README 4.3 要求：审核通过（入账一致）、审核驳回（不动余额）；
 * 并补充申请校验、状态机保护。全部脱离 DB（注入假资金服务 + 内存接缝）。
 */
class RechargeLogicTest extends TestCase
{
    /** 标准商户上下文 */
    private function merchant(array $override = []): array
    {
        return array_merge(['id' => 7, 'mch_id' => 'M007'], $override);
    }

    /** 构造测试逻辑 + 假资金服务 */
    private function make(FakeRechargeLedgerService $ledger): TestableRechargeLogic
    {
        return new TestableRechargeLogic($ledger);
    }

    /**
     * 申请充值：建待审核单，**不动余额**（入账发生在审核通过时）
     */
    public function testApplyCreatesPendingNoCredit(): void
    {
        $ledger = new FakeRechargeLedgerService();
        $logic = $this->make($ledger);

        $result = $logic->apply($this->merchant(), ['amount' => '50', 'recharge_type' => Recharge::TYPE_CARD, 'remark' => '银行转账']);

        $this->assertSame(Recharge::STATUS_PENDING, $result['status']);
        $this->assertSame('50.0000', $result['amount']);
        $this->assertSame(Recharge::TYPE_CARD, $result['recharge_type']);
        // 申请阶段不入账，余额不变
        $this->assertSame('100.0000', $ledger->balance);
        $this->assertCount(0, $ledger->credits);
        // 落库一条待审核单
        $this->assertCount(1, $logic->recharges);
        $row = array_values($logic->recharges)[0];
        $this->assertSame(Recharge::STATUS_PENDING, $row['status']);
    }

    /**
     * 申请校验：金额必须 > 0
     */
    public function testApplyRejectsNonPositiveAmount(): void
    {
        $logic = $this->make(new FakeRechargeLedgerService());
        $this->expectException(PaymentException::class);
        $logic->apply($this->merchant(), ['amount' => '0', 'recharge_type' => Recharge::TYPE_BALANCE]);
    }

    /**
     * 申请校验：充值方式必须为合法枚举
     */
    public function testApplyRejectsInvalidType(): void
    {
        $logic = $this->make(new FakeRechargeLedgerService());
        $this->expectException(PaymentException::class);
        $logic->apply($this->merchant(), ['amount' => '50', 'recharge_type' => 99]);
    }

    /**
     * 申请默认充值方式为余额充值（未传 recharge_type）
     */
    public function testApplyDefaultsToBalanceType(): void
    {
        $logic = $this->make(new FakeRechargeLedgerService());
        $result = $logic->apply($this->merchant(), ['amount' => '50']);
        $this->assertSame(Recharge::TYPE_BALANCE, $result['recharge_type']);
    }

    /**
     * 审核通过：入账 + 置通过，余额增加且入账一次（README 核心要求：审核通过后余额增加且有流水）
     */
    public function testAuditApproveCredits(): void
    {
        $ledger = new FakeRechargeLedgerService();
        $logic = $this->make($ledger);
        $logic->recharges[3001] = $this->pendingRow();

        $res = $logic->audit(3001, true, 9, '核实到账');

        $this->assertSame('approved', $res['result']);
        $this->assertSame(Recharge::STATUS_APPROVED, $logic->recharges[3001]['status']);
        // 入账：余额 100 → 150
        $this->assertSame('150.0000', $ledger->balance);
        $this->assertCount(1, $ledger->credits);
        $this->assertSame(['R3001', '50.0000'], $ledger->credits[0]);
        // 审核字段回写
        $this->assertSame(9, $logic->recharges[3001]['audit_by']);
        $this->assertSame('核实到账', $logic->recharges[3001]['audit_remark']);
    }

    /**
     * 审核驳回：**不动余额**、不入账，仅置驳回
     */
    public function testAuditRejectNoCredit(): void
    {
        $ledger = new FakeRechargeLedgerService();
        $logic = $this->make($ledger);
        $logic->recharges[3002] = $this->pendingRow(['id' => 3002, 'recharge_no' => 'R3002']);

        $res = $logic->audit(3002, false, 9, '凭证不符');

        $this->assertSame('rejected', $res['result']);
        $this->assertSame(Recharge::STATUS_REJECTED, $logic->recharges[3002]['status']);
        // 驳回不入账，余额不变
        $this->assertSame('100.0000', $ledger->balance);
        $this->assertCount(0, $ledger->credits);
        $this->assertSame('凭证不符', $logic->recharges[3002]['audit_remark']);
    }

    /**
     * 审核状态保护：非待审核单不可再审核（防重复入账）
     */
    public function testAuditRejectsNonPending(): void
    {
        $logic = $this->make(new FakeRechargeLedgerService());
        $logic->recharges[3003] = $this->pendingRow(['id' => 3003, 'recharge_no' => 'R3003', 'status' => Recharge::STATUS_APPROVED]);
        $this->expectException(PaymentException::class);
        $logic->audit(3003, true, 9, 'x');
    }

    /**
     * 审核单不存在 → 抛异常
     */
    public function testAuditRejectsMissing(): void
    {
        $logic = $this->make(new FakeRechargeLedgerService());
        $this->expectException(PaymentException::class);
        $logic->audit(9999, true, 9, 'x');
    }

    /**
     * 完整路径：申请 → 审核通过，余额按申请金额增加，状态终为通过
     */
    public function testFullApproveLifecycle(): void
    {
        $ledger = new FakeRechargeLedgerService(); // 初始 100
        $logic = $this->make($ledger);

        $apply = $logic->apply($this->merchant(), ['amount' => '80', 'recharge_type' => Recharge::TYPE_ONLINE]);
        $id = array_key_first($logic->recharges);
        // 申请后未入账
        $this->assertSame('100.0000', $ledger->balance);

        $logic->audit($id, true, 9, '通过');
        // 入账后余额 180
        $this->assertSame('180.0000', $ledger->balance);
        $this->assertSame(Recharge::STATUS_APPROVED, $logic->recharges[$id]['status']);
        $this->assertSame($apply['recharge_no'], $ledger->credits[0][0]);
    }

    // ===== 测试夹具 =====

    /** 待审核充值单（金额 50） */
    private function pendingRow(array $override = []): array
    {
        return array_merge([
            'id'            => 3001,
            'recharge_no'   => 'R3001',
            'merchant_id'   => 7,
            'mch_id'        => 'M007',
            'amount'        => '50.0000',
            'recharge_type' => Recharge::TYPE_CARD,
            'status'        => Recharge::STATUS_PENDING,
        ], $override);
    }
}
