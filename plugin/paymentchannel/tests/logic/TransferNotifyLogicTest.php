<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付回调网关逻辑测试（注入假适配器/假提现逻辑，脱离 DB/网络）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\TransferNotifyLogic;
use plugin\paymentchannel\app\logic\WithdrawLogic;
use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\dto\CreateTransferResult;
use plugin\paymentchannel\service\transfer\dto\TransferStatusResult;
use plugin\paymentchannel\service\transfer\TransferAdapterInterface;
use RuntimeException;

/**
 * 假提现逻辑：记录成败确认调用，供网关委托断言（不触 DB）。
 */
class SpyWithdrawLogic extends WithdrawLogic
{
    public array $successCalls = [];
    public array $failedCalls = [];
    /** 确认时是否抛异常（模拟事务回滚 / 状态非法） */
    public bool $throws = false;

    public function __construct()
    {
        // 不调用父构造（避免实例化模型/真实依赖）；本类仅记录调用
    }

    public function confirmSuccess(string $withdrawNo): bool
    {
        if ($this->throws) {
            throw new RuntimeException('确认失败');
        }
        $this->successCalls[] = $withdrawNo;
        return true;
    }

    public function confirmFailed(string $withdrawNo, string $reason = ''): bool
    {
        $this->failedCalls[] = [$withdrawNo, $reason];
        return true;
    }
}

/**
 * 可配置的假代付适配器：控制验签结果与回调解析结果。
 */
class NotifyFakeAdapter implements TransferAdapterInterface
{
    public function __construct(
        private bool $verify,
        private TransferStatusResult $status,
    ) {
    }

    public function createTransfer(CreateTransferRequest $request): CreateTransferResult
    {
        return CreateTransferResult::ok();
    }

    public function parseTransferNotify(array $payload): TransferStatusResult
    {
        return $this->status;
    }

    public function verifyNotify(array $payload): bool
    {
        return $this->verify;
    }

    public function queryTransfer(string $transferNo, string $upstreamNo = ''): TransferStatusResult
    {
        return $this->status;
    }

    public function successResponse(): string
    {
        return 'success';
    }
}

/**
 * 可测试的代付回调逻辑：重写通道加载接缝。
 */
class TestableTransferNotifyLogic extends TransferNotifyLogic
{
    /** 模拟通道（null=通道不存在） */
    public ?array $channel = ['id' => 1, 'code' => 'mock_transfer', 'adapter' => 'mock_transfer'];

    protected function loadChannel(string $code): ?array
    {
        return $this->channel;
    }
}

/**
 * 代付回调网关逻辑测试
 *
 * 覆盖：未知通道、验签失败、成功回调→确认成功、失败回调→确认失败、
 * 处理中→ack不改账、确认异常→回 fail（促上游重推）。全部脱离 DB / 网络。
 */
class TransferNotifyLogicTest extends TestCase
{
    /** 构造被测逻辑：注入假适配器工厂 + 间谍提现逻辑 */
    private function make(bool $verify, TransferStatusResult $status, SpyWithdrawLogic $spy): TestableTransferNotifyLogic
    {
        $factory = fn (array $channel): TransferAdapterInterface => new NotifyFakeAdapter($verify, $status);
        return new TestableTransferNotifyLogic($factory, $spy);
    }

    /**
     * 未知通道 → 回 fail，不委托确认
     */
    public function testUnknownChannelFails(): void
    {
        $spy = new SpyWithdrawLogic();
        $logic = $this->make(true, TransferStatusResult::success('W1'), $spy);
        $logic->channel = null;

        $this->assertSame(TransferNotifyLogic::RESP_FAIL, $logic->handleNotify('x', []));
        $this->assertCount(0, $spy->successCalls);
    }

    /**
     * 验签失败 → 回 fail，不委托确认（防伪造回调改账）
     */
    public function testInvalidSignFails(): void
    {
        $spy = new SpyWithdrawLogic();
        $logic = $this->make(false, TransferStatusResult::success('W1'), $spy);

        $this->assertSame(TransferNotifyLogic::RESP_FAIL, $logic->handleNotify('mock_transfer', ['sign' => 'bad']));
        $this->assertCount(0, $spy->successCalls);
    }

    /**
     * 成功回调 → 委托 confirmSuccess，回 success 串
     */
    public function testSuccessNotifyConfirms(): void
    {
        $spy = new SpyWithdrawLogic();
        $logic = $this->make(true, TransferStatusResult::success('W100', '99.0000', 'UP100'), $spy);

        $resp = $logic->handleNotify('mock_transfer', ['out_trade_no' => 'W100']);

        $this->assertSame('success', $resp);
        $this->assertSame(['W100'], $spy->successCalls);
        $this->assertCount(0, $spy->failedCalls);
    }

    /**
     * 失败回调 → 委托 confirmFailed，回 success 串（已受理失败，停止重推）
     */
    public function testFailedNotifyConfirms(): void
    {
        $spy = new SpyWithdrawLogic();
        $logic = $this->make(true, TransferStatusResult::failed('W101', '余额不足'), $spy);

        $resp = $logic->handleNotify('mock_transfer', ['out_trade_no' => 'W101']);

        $this->assertSame('success', $resp);
        $this->assertCount(0, $spy->successCalls);
        $this->assertSame('W101', $spy->failedCalls[0][0]);
    }

    /**
     * 处理中回调 → ack 不改账（不委托任何确认）
     */
    public function testProcessingNotifyAcks(): void
    {
        $spy = new SpyWithdrawLogic();
        $logic = $this->make(true, TransferStatusResult::processing('W102'), $spy);

        $resp = $logic->handleNotify('mock_transfer', ['out_trade_no' => 'W102']);

        $this->assertSame('success', $resp);
        $this->assertCount(0, $spy->successCalls);
        $this->assertCount(0, $spy->failedCalls);
    }

    /**
     * 空提现单号 → 回 fail
     */
    public function testEmptyTransferNoFails(): void
    {
        $spy = new SpyWithdrawLogic();
        $logic = $this->make(true, TransferStatusResult::success(''), $spy);

        $this->assertSame(TransferNotifyLogic::RESP_FAIL, $logic->handleNotify('mock_transfer', []));
        $this->assertCount(0, $spy->successCalls);
    }

    /**
     * 确认异常（事务回滚 / 状态非法）→ 回 fail，促使上游重推
     */
    public function testConfirmExceptionReturnsFail(): void
    {
        $spy = new SpyWithdrawLogic();
        $spy->throws = true;
        $logic = $this->make(true, TransferStatusResult::success('W103'), $spy);

        $this->assertSame(TransferNotifyLogic::RESP_FAIL, $logic->handleNotify('mock_transfer', ['out_trade_no' => 'W103']));
    }
}
