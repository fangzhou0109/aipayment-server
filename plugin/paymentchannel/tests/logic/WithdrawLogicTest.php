<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户提现逻辑测试（注入假资金服务/假代付适配器，DB 接缝重写，脱离 DB/网络）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use Closure;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\WithdrawLogic;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\Withdraw;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\LedgerService;
use plugin\paymentchannel\service\RateResolver;
use plugin\paymentchannel\tests\support\TestableRateResolver;
use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\dto\CreateTransferResult;
use plugin\paymentchannel\service\transfer\dto\TransferStatusResult;
use plugin\paymentchannel\service\transfer\TransferAdapterInterface;
use RuntimeException;

/**
 * 假资金服务：用内存模拟商户可用/冻结余额，记录冻结/扣减/退款调用，
 * 断言提现状态机在各分支调用了正确的资金操作（不触 DB）。
 */
class FakeLedgerService extends LedgerService
{
    /** 可用余额 */
    public string $balance = '100.0000';
    /** 冻结余额 */
    public string $freeze = '0.0000';
    /** 冻结时是否模拟余额不足抛异常 */
    public bool $insufficient = false;
    /** 调用记录：[op, withdrawNo, amount] */
    public array $calls = [];

    public function freezeWithdraw(int $merchantId, string $mchId, string $withdrawNo, string $amount): void
    {
        if ($this->insufficient) {
            throw new RuntimeException('提现失败：可用余额不足');
        }
        $this->balance = AmountHelper::sub($this->balance, $amount);
        $this->freeze = AmountHelper::add($this->freeze, $amount);
        $this->calls[] = ['freeze', $withdrawNo, AmountHelper::format($amount)];
    }

    public function deductWithdraw(int $merchantId, string $mchId, string $withdrawNo, string $amount): void
    {
        $this->freeze = AmountHelper::sub($this->freeze, $amount);
        $this->calls[] = ['deduct', $withdrawNo, AmountHelper::format($amount)];
    }

    public function refundWithdraw(int $merchantId, string $mchId, string $withdrawNo, string $amount): void
    {
        $this->freeze = AmountHelper::sub($this->freeze, $amount);
        $this->balance = AmountHelper::add($this->balance, $amount);
        $this->calls[] = ['refund', $withdrawNo, AmountHelper::format($amount)];
    }

    /** 统计某类操作调用次数 */
    public function countOp(string $op): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $op));
    }
}

/**
 * 可测试的提现逻辑：内存替代提现单/银行卡表，重写事务与 DB 接缝，脱离数据库。
 */
class TestableWithdrawLogic extends WithdrawLogic
{
    /** 内存提现单表：id => row */
    public array $withdraws = [];
    /** 内存银行卡表：id => row */
    public array $bankCards = [];
    /** 商户已授权代付 channel_id 列表 */
    public array $authorizedTransferChannelIds = [10];
    /** channel_id => 通道行（含 sort/title/transfer_adapter） */
    public array $transferChannels = [];
    /** 配置兜底代付通道（无授权绑定时） */
    public ?array $configFallbackChannel = null;
    /** 注入代付费率解析器 */
    public ?TestableRateResolver $testRateResolver = null;
    /** 自增ID */
    private int $autoId = 1000;

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        // 单测内直接执行闭包（异常照常向上抛，模拟回滚语义）
        return $closure();
    }

    protected function buildTransferNotifyUrl(string $channelCode = ''): string
    {
        return 'http://platform.test/pay/transferNotify/mock_transfer';
    }

    protected function loadWithdraw(int $id): ?array
    {
        return $this->withdraws[$id] ?? null;
    }

    protected function loadWithdrawByNo(string $withdrawNo): ?array
    {
        foreach ($this->withdraws as $w) {
            if ($w['withdraw_no'] === $withdrawNo) {
                return $w;
            }
        }
        return null;
    }

    protected function loadBankCard(int $bankCardId): ?array
    {
        return $this->bankCards[$bankCardId] ?? null;
    }

    protected function createWithdraw(array $data): int
    {
        $id = ++$this->autoId;
        $this->withdraws[$id] = array_merge(['id' => $id], $data);
        return $id;
    }

    protected function updateWithdraw(int $id, array $patch): void
    {
        if (isset($this->withdraws[$id])) {
            $this->withdraws[$id] = array_merge($this->withdraws[$id], $patch);
        }
    }

    protected function loadAuthorizedTransferChannelIds(int $merchantId): array
    {
        return $this->authorizedTransferChannelIds;
    }

    protected function loadTransferChannelById(int $id): ?array
    {
        return $this->transferChannels[$id] ?? null;
    }

    protected function loadEnabledChannelsOrdered(array $ids): array
    {
        $list = [];
        foreach ($ids as $id) {
            if (isset($this->transferChannels[$id])) {
                $list[] = $this->transferChannels[$id];
            }
        }
        usort($list, static fn (array $a, array $b): int => ($b['sort'] ?? 0) <=> ($a['sort'] ?? 0));

        return $list;
    }

    protected function loadTransferChannelByConfig(): ?array
    {
        return $this->configFallbackChannel;
    }

    protected function getRateResolver(): RateResolver
    {
        return $this->testRateResolver ?? parent::getRateResolver();
    }
}

/**
 * 假代付适配器：可配置「受理成功/拒单/抛异常」，并捕获下发请求用于断言。
 */
class FakeTransferAdapter implements TransferAdapterInterface
{
    /** true=受理成功，false=上游拒单 */
    public bool $accept = true;
    /** createTransfer 是否抛异常（模拟网络故障） */
    public bool $throwOnCreate = false;
    /** 捕获的下发请求 */
    public ?CreateTransferRequest $lastRequest = null;

    public function createTransfer(CreateTransferRequest $request): CreateTransferResult
    {
        $this->lastRequest = $request;
        if ($this->throwOnCreate) {
            throw new RuntimeException('网络故障');
        }
        return $this->accept
            ? CreateTransferResult::ok('UP_' . $request->transferNo)
            : CreateTransferResult::fail('上游余额不足');
    }

    public function parseTransferNotify(array $payload): TransferStatusResult
    {
        return TransferStatusResult::processing();
    }

    public function verifyNotify(array $payload): bool
    {
        return true;
    }

    public function queryTransfer(string $transferNo, string $upstreamNo = ''): TransferStatusResult
    {
        return TransferStatusResult::processing($transferNo);
    }

    public function successResponse(): string
    {
        return 'success';
    }
}

/**
 * 商户提现逻辑测试
 *
 * 覆盖 README 4.2 要求：余额不足拒绝、冻结正确、审核拒绝退款、代付成功扣账、
 * 失败退款、并发幂等；并补充完整生命周期的资金守恒断言。全部脱离 DB / 网络。
 */
class WithdrawLogicTest extends TestCase
{
    /** 标准商户上下文（有代付通道时通道链优先；无通道时用 rate_transfer 保底） */
    private function merchant(array $override = []): array
    {
        return array_merge([
            'id'            => 7,
            'mch_id'        => 'M007',
            'rate_transfer' => '99', // 有通道授权时通道链优先，不应采用此高费率
        ], $override);
    }

    /** 默认代付通道（mock_transfer 适配器，通道默认费率 1%） */
    private function defaultTransferChannel(array $override = []): array
    {
        return array_merge([
            'id'               => 10,
            'code'             => 'mock_transfer',
            'title'            => 'Mock代付',
            'adapter'          => 'mock_transfer',
            'transfer_adapter' => 'mock_transfer',
            'channel_biz'      => Channel::BIZ_BOTH,
            'sort'             => 100,
            'rate_transfer_self' => '1.0000',
            'gateway_url'          => 'http://mock.test',
            'upstream_mch_id'      => 'M',
            'upstream_key'         => 'k',
            'upstream_public_key'  => '',
            'upstream_private_key' => '',
        ], $override);
    }

    /** 构造代付费率解析器 */
    private function makeTransferRateResolver(
        TestableWithdrawLogic $logic,
        array $merchantChannelTransfer = [],
    ): TestableRateResolver {
        $resolver = new TestableRateResolver();
        $channel = $logic->transferChannels[10] ?? $this->defaultTransferChannel();
        $resolver->channel = $channel;
        $resolver->merchantChannelTransfers = [
            7 => [
                10 => array_merge([
                    'merchant_id'      => 7,
                    'channel_id'       => 10,
                    'rate_transfer'    => '0.0000',
                    'transfer_enabled' => 1,
                ], $merchantChannelTransfer),
            ],
        ];

        return $resolver;
    }

    /** 构造测试逻辑 + 假资金服务 + 假适配器，预置银行卡与代付通道授权 */
    private function make(
        FakeLedgerService $ledger,
        FakeTransferAdapter $adapter,
        array $transferChannelOverride = [],
        array $merchantChannelTransfer = [],
    ): TestableWithdrawLogic {
        $factory = fn (): TransferAdapterInterface => $adapter;
        $logic = new TestableWithdrawLogic($ledger, $factory);
        $logic->bankCards[1] = [
            'id'          => 1,
            'merchant_id' => 7,
            'holder_name' => '张三',
            'card_no'     => '6222000000000000',
            'bank_name'   => '工商银行',
            'bank_code'   => 'ICBC',
            'status'      => 1,
        ];
        $logic->transferChannels[10] = $this->defaultTransferChannel($transferChannelOverride);
        $logic->testRateResolver = $this->makeTransferRateResolver($logic, $merchantChannelTransfer);

        return $logic;
    }

    /**
     * 申请提现：余额充足 → 冻结正确，建单待审核，手续费/到账金额按费率计算
     */
    public function testApplyFreezesAndCreatesPending(): void
    {
        $ledger = new FakeLedgerService();
        $adapter = new FakeTransferAdapter();
        $logic = $this->make($ledger, $adapter);

        $result = $logic->apply($this->merchant(), ['amount' => '100', 'bank_card_id' => 1]);

        // 手续费 1%（100×1%=1），到账 99
        $this->assertSame('1.0000', $result['fee']);
        $this->assertSame('99.0000', $result['real_amount']);
        $this->assertSame(Withdraw::STATUS_PENDING, $result['status']);
        // 冻结：可用 100→0，冻结 0→100（冻结的是毛额）
        $this->assertSame(1, $ledger->countOp('freeze'));
        $this->assertSame('0.0000', $ledger->balance);
        $this->assertSame('100.0000', $ledger->freeze);
        // 落库一条待审核单
        $this->assertCount(1, $logic->withdraws);
        $row = array_values($logic->withdraws)[0];
        $this->assertSame(Withdraw::STATUS_PENDING, $row['status']);
        // 申请时固化银行卡快照
        $this->assertSame('张三', $row['account_name']);
        $this->assertSame('6222000000000000', $row['account_no']);
        $this->assertSame('工商银行', $row['bank_name']);
        $this->assertSame('ICBC', $row['bank_code']);
    }

    /**
     * 无银行卡快照时禁止代付下发（不可回查 bank_card_id）
     */
    public function testDisburseRejectsMissingBankSnapshot(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->withdraws[2001] = $this->pendingRow([
            'status'       => Withdraw::STATUS_APPROVED,
            'account_name' => '',
            'account_no'   => '',
            'bank_name'    => '',
            'bank_code'    => '',
            'branch_name'  => '',
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('收款银行卡信息缺失');
        $logic->disburse($logic->withdraws[2001], 10);
    }

    /**
     * 代付下发优先使用提现单银行卡快照，不受后续改卡影响
     */
    public function testDisburseUsesWithdrawBankSnapshot(): void
    {
        $ledger = new FakeLedgerService();
        $adapter = new FakeTransferAdapter();
        $logic = $this->make($ledger, $adapter);
        $logic->withdraws[2001] = $this->pendingRow([
            'status'       => Withdraw::STATUS_APPROVED,
            'account_name' => '快照姓名',
            'account_no'   => '6222111111111111',
            'bank_name'    => '快照银行',
            'bank_code'    => 'SNAP',
        ]);
        // 关联卡已变更，不应影响代付收款账户
        $logic->bankCards[1]['holder_name'] = '已改姓名';
        $logic->bankCards[1]['card_no'] = '9999999999999999';

        $result = $logic->disburse($logic->withdraws[2001], 10);

        $this->assertSame('paying', $result['result']);
        $this->assertSame('快照姓名', $adapter->lastRequest?->accountName);
        $this->assertSame('6222111111111111', $adapter->lastRequest?->accountNo);
        $this->assertSame('快照银行', $adapter->lastRequest?->bankName);
    }

    /**
     * 余额不足拒绝：冻结抛异常 → 整体回滚，不建单
     */
    public function testApplyInsufficientBalanceRejected(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->insufficient = true;
        $logic = $this->make($ledger, new FakeTransferAdapter());

        $this->expectException(PaymentException::class);
        try {
            $logic->apply($this->merchant(), ['amount' => '100', 'bank_card_id' => 1]);
        } finally {
            // 未建单（事务回滚语义）
            $this->assertCount(0, $logic->withdraws);
        }
    }

    /**
     * 申请校验：金额必须 > 0
     */
    public function testApplyRejectsNonPositiveAmount(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $this->expectException(PaymentException::class);
        $logic->apply($this->merchant(), ['amount' => '0', 'bank_card_id' => 1]);
    }

    /**
     * 申请校验：银行卡不属于本商户 → 拒绝（防越权提现到他人卡）
     */
    public function testApplyRejectsForeignBankCard(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->bankCards[1]['merchant_id'] = 999; // 改成他人卡
        $this->expectException(PaymentException::class);
        $logic->apply($this->merchant(), ['amount' => '100', 'bank_card_id' => 1]);
    }

    /**
     * 申请校验：已停用银行卡不可用于提现
     */
    public function testApplyRejectsDisabledBankCard(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->bankCards[1]['status'] = 2;
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('收款银行卡已停用');
        $logic->apply($this->merchant(), ['amount' => '100', 'bank_card_id' => 1]);
    }

    /**
     * 常规通过：财务线下已打款 → 扣减冻结 + 置成功终态，不触发代付
     */
    public function testAuditPassCompletesOffline(): void
    {
        $ledger = new FakeLedgerService();
        $adapter = new FakeTransferAdapter();
        $logic = $this->make($ledger, $adapter);
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $logic->withdraws[2011] = $this->pendingRow(['id' => 2011, 'withdraw_no' => 'W2011']);

        $res = $logic->audit(2011, 'pass', 9, '财务已线下打款');

        $this->assertSame('success', $res['result']);
        $this->assertSame(Withdraw::STATUS_SUCCESS, $logic->withdraws[2011]['status']);
        $this->assertSame(0, $ledger->countOp('refund'));
        $this->assertSame(1, $ledger->countOp('deduct'));
        $this->assertSame('0.0000', $ledger->freeze);
        $this->assertSame('0.0000', $ledger->balance);
        $this->assertNull($adapter->lastRequest);
    }

    /**
     * 审核拒绝：待审核单 → 退款解冻 + 置审核拒绝
     */
    public function testAuditRejectRefunds(): void
    {
        $ledger = new FakeLedgerService();
        $logic = $this->make($ledger, new FakeTransferAdapter());
        // 预置一张已冻结的待审核单（模拟 apply 后）
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $logic->withdraws[2001] = $this->pendingRow();

        $res = $logic->audit(2001, 'reject', 9, '资料不符');

        $this->assertSame('rejected', $res['result']);
        $this->assertSame(Withdraw::STATUS_REJECTED, $logic->withdraws[2001]['status']);
        // 退款：冻结 100→0，可用 0→100
        $this->assertSame(1, $ledger->countOp('refund'));
        $this->assertSame('100.0000', $ledger->balance);
        $this->assertSame('0.0000', $ledger->freeze);
        // 审核字段回写
        $this->assertSame(9, $logic->withdraws[2001]['audit_by']);
        $this->assertSame('资料不符', $logic->withdraws[2001]['audit_remark']);
    }

    /**
     * 审核通过 + 受理成功：置代付中、回写上游单号，冻结仍持有（未扣减）
     */
    public function testAuditApproveDisbursesAccepted(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $adapter = new FakeTransferAdapter();
        $adapter->accept = true;
        $logic = $this->make($ledger, $adapter);
        $logic->withdraws[2002] = $this->pendingRow(['id' => 2002, 'withdraw_no' => 'W2002']);

        $res = $logic->audit(2002, 'disburse', 9, '通过');

        $this->assertSame('paying', $res['result']);
        $this->assertSame(Withdraw::STATUS_PAYING, $logic->withdraws[2002]['status']);
        // 回写上游单号
        $this->assertSame('UP_W2002', $logic->withdraws[2002]['transfer_no']);
        // 下发金额为到账金额（real_amount=99），收款卡信息正确透传
        $this->assertSame('99.0000', $adapter->lastRequest->amount);
        $this->assertSame('6222000000000000', $adapter->lastRequest->accountNo);
        // 仍持冻结，未扣减、未退款
        $this->assertSame(0, $ledger->countOp('deduct'));
        $this->assertSame(0, $ledger->countOp('refund'));
        $this->assertSame('100.0000', $ledger->freeze);
    }

    /**
     * 审核通过但上游拒单：下发失败 → 退款解冻 + 置代付失败
     */
    public function testAuditApproveUpstreamRejectRefunds(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $adapter = new FakeTransferAdapter();
        $adapter->accept = false; // 上游拒单
        $logic = $this->make($ledger, $adapter);
        $logic->withdraws[2003] = $this->pendingRow(['id' => 2003, 'withdraw_no' => 'W2003']);

        $res = $logic->audit(2003, 'disburse', 9, '通过');

        $this->assertSame('pay_failed', $res['result']);
        $this->assertSame(Withdraw::STATUS_PAY_FAILED, $logic->withdraws[2003]['status']);
        // 退款回滚资金
        $this->assertSame(1, $ledger->countOp('refund'));
        $this->assertSame('100.0000', $ledger->balance);
        $this->assertSame('0.0000', $ledger->freeze);
    }

    /**
     * 下发受理调用抛异常：按受理失败处理 → 退款 + 代付失败（资金不丢）
     */
    public function testDisburseExceptionRefunds(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $adapter = new FakeTransferAdapter();
        $adapter->throwOnCreate = true;
        $logic = $this->make($ledger, $adapter);
        $logic->withdraws[2004] = $this->pendingRow(['id' => 2004, 'withdraw_no' => 'W2004']);

        $res = $logic->audit(2004, 'disburse', 9, '通过');

        $this->assertSame('pay_failed', $res['result']);
        $this->assertSame(Withdraw::STATUS_PAY_FAILED, $logic->withdraws[2004]['status']);
        $this->assertSame(1, $ledger->countOp('refund'));
        $this->assertSame('100.0000', $ledger->balance);
    }

    /**
     * 代付成功扣账：代付中 → 回调成功 → 冻结扣减 + 置成功（钱真正出账）
     */
    public function testConfirmSuccessDeducts(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $logic = $this->make($ledger, new FakeTransferAdapter());
        $logic->withdraws[2005] = $this->payingRow(['id' => 2005, 'withdraw_no' => 'W2005']);

        $done = $logic->confirmSuccess('W2005');

        $this->assertTrue($done);
        $this->assertSame(Withdraw::STATUS_SUCCESS, $logic->withdraws[2005]['status']);
        // 扣减：冻结 100→0，可用不变（钱离开账户）
        $this->assertSame(1, $ledger->countOp('deduct'));
        $this->assertSame('0.0000', $ledger->balance);
        $this->assertSame('0.0000', $ledger->freeze);
    }

    /**
     * 并发/重复成功回调幂等：第二次回调直接跳过，只扣减一次
     */
    public function testConfirmSuccessIdempotent(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $logic = $this->make($ledger, new FakeTransferAdapter());
        $logic->withdraws[2006] = $this->payingRow(['id' => 2006, 'withdraw_no' => 'W2006']);

        $first = $logic->confirmSuccess('W2006');
        $second = $logic->confirmSuccess('W2006'); // 重复回调

        $this->assertTrue($first);
        $this->assertFalse($second); // 第二次幂等跳过
        $this->assertSame(1, $ledger->countOp('deduct')); // 仅扣减一次
        $this->assertSame('0.0000', $ledger->freeze);
    }

    /**
     * 代付失败退款：代付中 → 回调失败 → 解冻退款 + 置代付失败
     */
    public function testConfirmFailedRefunds(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $logic = $this->make($ledger, new FakeTransferAdapter());
        $logic->withdraws[2007] = $this->payingRow(['id' => 2007, 'withdraw_no' => 'W2007']);

        $done = $logic->confirmFailed('W2007', '上游出款失败');

        $this->assertTrue($done);
        $this->assertSame(Withdraw::STATUS_PAY_FAILED, $logic->withdraws[2007]['status']);
        $this->assertSame(1, $ledger->countOp('refund'));
        $this->assertSame('100.0000', $ledger->balance);
        $this->assertSame('0.0000', $ledger->freeze);
    }

    /**
     * 失败回调幂等：已成功的单收到失败回调 → 跳过，不退款（不破坏已扣账）
     */
    public function testConfirmFailedSkipsTerminal(): void
    {
        $ledger = new FakeLedgerService();
        $logic = $this->make($ledger, new FakeTransferAdapter());
        $logic->withdraws[2008] = $this->payingRow(['id' => 2008, 'withdraw_no' => 'W2008', 'status' => Withdraw::STATUS_SUCCESS]);

        $done = $logic->confirmFailed('W2008', '迟到的失败回调');

        $this->assertFalse($done);
        $this->assertSame(0, $ledger->countOp('refund'));
    }

    /**
     * 成功确认状态保护：非代付中（如待审核）收到成功回调 → 抛异常（拒绝非法流转）
     */
    public function testConfirmSuccessRejectsWrongState(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->withdraws[2009] = $this->pendingRow(['id' => 2009, 'withdraw_no' => 'W2009']);
        $this->expectException(PaymentException::class);
        $logic->confirmSuccess('W2009');
    }

    /**
     * 审核状态保护：非待审核单不可再审核
     */
    public function testAuditRejectsNonPending(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->withdraws[2010] = $this->payingRow(['id' => 2010, 'withdraw_no' => 'W2010']);
        $this->expectException(PaymentException::class);
        $logic->audit(2010, 'disburse', 9, 'x');
    }

    /**
     * 完整生命周期资金守恒（成功路径）：
     * 申请(冻结)→审核通过(代付中)→回调成功(扣减)，可用净减毛额、冻结归零，账实一致
     */
    public function testFullLifecycleSuccessConservesMoney(): void
    {
        $ledger = new FakeLedgerService(); // 初始可用 100
        $adapter = new FakeTransferAdapter();
        $logic = $this->make($ledger, $adapter);

        // 申请 60
        $apply = $logic->apply($this->merchant(), ['amount' => '60', 'bank_card_id' => 1]);
        $no = $apply['withdraw_no'];
        $id = array_key_first($logic->withdraws);
        $this->assertSame('40.0000', $ledger->balance);
        $this->assertSame('60.0000', $ledger->freeze);

        // 审核代付下发 → 代付中
        $logic->audit($id, 'disburse', 9, '通过');
        $this->assertSame(Withdraw::STATUS_PAYING, $logic->withdraws[$id]['status']);

        // 回调成功 → 扣减
        $logic->confirmSuccess($no);
        // 可用 40、冻结 0：净减 60（毛额），账实一致
        $this->assertSame('40.0000', $ledger->balance);
        $this->assertSame('0.0000', $ledger->freeze);
        $this->assertSame(Withdraw::STATUS_SUCCESS, $logic->withdraws[$id]['status']);
    }

    /**
     * 完整生命周期资金守恒（失败回滚路径）：
     * 申请(冻结)→审核通过(代付中)→回调失败(退款)，可用回到初始、冻结归零
     */
    public function testFullLifecycleFailureRefundsMoney(): void
    {
        $ledger = new FakeLedgerService(); // 初始可用 100
        $adapter = new FakeTransferAdapter();
        $logic = $this->make($ledger, $adapter);

        $apply = $logic->apply($this->merchant(), ['amount' => '60', 'bank_card_id' => 1]);
        $no = $apply['withdraw_no'];
        $id = array_key_first($logic->withdraws);

        $logic->audit($id, 'disburse', 9, '通过');
        $logic->confirmFailed($no, '上游出款失败');

        // 退款后资金回到初始：可用 100、冻结 0
        $this->assertSame('100.0000', $ledger->balance);
        $this->assertSame('0.0000', $ledger->freeze);
        $this->assertSame(Withdraw::STATUS_PAY_FAILED, $logic->withdraws[$id]['status']);
    }

    /**
     * Phase 9.4.2：apply 优先采用 merchant_channel.rate_transfer
     */
    public function testApplyUsesMerchantChannelTransferRate(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter(), [], [
            'rate_transfer' => '2.5000',
        ]);

        $result = $logic->apply($this->merchant(), ['amount' => '100', 'bank_card_id' => 1]);

        $this->assertSame('2.5000', $result['fee']);
        $this->assertSame('97.5000', $result['real_amount']);
    }

    /**
     * Phase 9.4.2：merchant_channel.rate_transfer=0 时继承 channel.rate_transfer_self
     */
    public function testApplyUsesChannelTransferRateSelf(): void
    {
        $logic = $this->make(
            new FakeLedgerService(),
            new FakeTransferAdapter(),
            ['rate_transfer_self' => '1.5000'],
        );

        $result = $logic->apply($this->merchant(), ['amount' => '100', 'bank_card_id' => 1]);

        $this->assertSame('1.5000', $result['fee']);
        $this->assertSame('98.5000', $result['real_amount']);
    }

    /**
     * 无代付通道但有商户全局 rate_transfer 时仍可申请提现
     */
    public function testApplyUsesMerchantGlobalRateWithoutChannel(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->authorizedTransferChannelIds = [];
        $logic->configFallbackChannel = null;

        $result = $logic->apply(
            $this->merchant(['rate_transfer' => '2.5000']),
            ['amount' => '100', 'bank_card_id' => 1]
        );

        $this->assertSame('2.5000', $result['fee']);
        $this->assertSame('97.5000', $result['real_amount']);
    }

    /**
     * 无代付通道且全局 rate_transfer 为 0 时仍可申请（免手续费）
     */
    public function testApplyAllowsZeroFeeWithoutChannel(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->authorizedTransferChannelIds = [];
        $logic->configFallbackChannel = null;

        $result = $logic->apply(
            $this->merchant(['rate_transfer' => '0']),
            ['amount' => '100', 'bank_card_id' => 1]
        );

        $this->assertSame('0.0000', $result['fee']);
        $this->assertSame('100.0000', $result['real_amount']);
    }

    /**
     * Phase 9.4.2：transferChannelOptions 仅列商户已授权代付通道
     */
    public function testTransferChannelOptionsFiltersByMerchant(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->transferChannels[20] = $this->defaultTransferChannel([
            'id'    => 20,
            'code'  => 'other_transfer',
            'title' => '其他代付',
            'sort'  => 50,
        ]);
        $logic->authorizedTransferChannelIds = [10, 20];

        $options = $logic->transferChannelOptions(7);

        $this->assertCount(2, $options);
        $this->assertSame(10, $options[0]['id']); // sort 100 优先
        $this->assertSame(20, $options[1]['id']);
        $this->assertSame([], $logic->transferChannelOptions(0));
    }

    /**
     * Phase 9.4.2：下发时拒绝未授权代付通道
     */
    public function testDisburseRejectsUnauthorizedChannel(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $logic = $this->make($ledger, new FakeTransferAdapter());
        $logic->withdraws[2101] = $this->pendingRow([
            'id'          => 2101,
            'withdraw_no' => 'W2101',
            'status'      => Withdraw::STATUS_APPROVED,
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('代付通道未授权或不可用');
        $logic->disburse($logic->withdraws[2101], 99);
    }

    /**
     * Phase 9.4.2：下发时接受已授权代付通道
     */
    public function testDisburseAcceptsAuthorizedChannel(): void
    {
        $ledger = new FakeLedgerService();
        $ledger->balance = '0.0000';
        $ledger->freeze = '100.0000';
        $adapter = new FakeTransferAdapter();
        $logic = $this->make($ledger, $adapter);
        $logic->withdraws[2102] = $this->pendingRow([
            'id'          => 2102,
            'withdraw_no' => 'W2102',
            'status'      => Withdraw::STATUS_APPROVED,
        ]);

        $res = $logic->disburse($logic->withdraws[2102], 10);

        $this->assertSame('paying', $res['result']);
        $this->assertSame(Withdraw::STATUS_PAYING, $res['status']);
        $this->assertSame('UP_W2102', $logic->withdraws[2102]['transfer_no']);
        $this->assertNotNull($adapter->lastRequest);
    }

    /**
     * Phase 9.5.4：仅 adapter=mock 无 transfer_adapter 的代收通道不进代付下拉
     */
    public function testTransferChannelOptionsExcludesPayAdapterFallback(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->transferChannels[40] = [
            'id'               => 40,
            'code'             => 'pay_mock_only',
            'title'            => '代收Mock误入',
            'adapter'          => 'mock',
            'transfer_adapter' => '',
            'channel_biz'      => Channel::BIZ_PAY_ONLY,
            'sort'             => 200,
            'gateway_url'          => 'http://mock.test',
            'upstream_mch_id'      => 'M',
            'upstream_key'         => 'k',
            'upstream_public_key'  => '',
            'upstream_private_key' => '',
        ];
        $logic->authorizedTransferChannelIds = [10, 40];

        $options = $logic->transferChannelOptions(7);

        $this->assertCount(1, $options);
        $this->assertSame(10, $options[0]['id']);
    }

    /**
     * Phase 9.5.4：channel_biz 不含代付能力时排除（即使有 transfer_adapter）
     */
    public function testTransferChannelOptionsExcludesNonTransferBiz(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->transferChannels[41] = $this->defaultTransferChannel([
            'id'          => 41,
            'code'        => 'biz_mismatch',
            'title'       => 'biz不匹配',
            'channel_biz' => Channel::BIZ_PAY_ONLY,
            'sort'        => 200,
        ]);
        $logic->authorizedTransferChannelIds = [10, 41];

        $options = $logic->transferChannelOptions(7);

        $this->assertCount(1, $options);
        $this->assertSame(10, $options[0]['id']);
    }

    /**
     * Phase 9.4.2：无授权绑定时回退配置 transfer_channel_code 对应通道
     */
    public function testTransferChannelOptionsFallsBackToConfigChannel(): void
    {
        $logic = $this->make(new FakeLedgerService(), new FakeTransferAdapter());
        $logic->authorizedTransferChannelIds = [];
        $logic->configFallbackChannel = $this->defaultTransferChannel([
            'id'    => 88,
            'code'  => 'cfg_transfer',
            'title' => '配置兜底',
        ]);

        $options = $logic->transferChannelOptions(7);

        $this->assertCount(1, $options);
        $this->assertSame(88, $options[0]['id']);
        $this->assertSame('cfg_transfer', $options[0]['code']);
    }

    // ===== 测试夹具 =====

    /** 待审核提现单（已冻结 100，到账 99） */
    private function pendingRow(array $override = []): array
    {
        return array_merge([
            'id'           => 2001,
            'withdraw_no'  => 'W2001',
            'merchant_id'  => 7,
            'mch_id'       => 'M007',
            'bank_card_id' => 1,
            'account_name' => '张三',
            'account_no'   => '6222000000000000',
            'bank_name'    => '工商银行',
            'bank_code'    => 'ICBC',
            'branch_name'  => '',
            'amount'       => '100.0000',
            'fee'          => '1.0000',
            'real_amount'  => '99.0000',
            'status'       => Withdraw::STATUS_PENDING,
        ], $override);
    }

    /** 代付中提现单 */
    private function payingRow(array $override = []): array
    {
        return $this->pendingRow(array_merge(['status' => Withdraw::STATUS_PAYING], $override));
    }
}
