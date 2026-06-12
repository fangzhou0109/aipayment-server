<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游回调网关逻辑测试（DB/Redis 接缝重写 + 注入假适配器）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\gateway;

use Closure;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\NotifyGatewayLogic;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\service\channel\ChannelAdapterInterface;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\CreateOrderResult;
use plugin\paymentchannel\service\channel\dto\PaymentStatusResult;
use RuntimeException;

/**
 * 可测试的回调逻辑：重写 DB/Redis 接缝与事务，脱离真实设施可纯单测。
 */
class TestableNotifyGatewayLogic extends NotifyGatewayLogic
{
    /** 模拟通道（null=通道不存在） */
    public ?array $channel = ['id' => 1, 'code' => 'mock_test_001', 'adapter' => 'mock'];
    /** 模拟订单（null=订单不存在） */
    public ?array $order = null;
    /** 模拟锁是否可获取 */
    public bool $lockAcquirable = true;

    /** markPaid 调用次数与入参 */
    public int $markPaidCalls = 0;
    public array $markPaidPatch = [];
    /** markFailed 调用次数 */
    public int $markFailedCalls = 0;
    /** 入账接缝调用次数 */
    public int $ledgerCalls = 0;
    /** 商户通知接缝调用次数 */
    public int $notifyCalls = 0;
    /** 锁获取/释放次数 */
    public int $lockAcquired = 0;
    public int $lockReleased = 0;
    /** 是否让入账接缝抛异常（验证回滚 + 释放锁） */
    public bool $ledgerThrows = false;

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function loadChannel(string $code): ?array
    {
        return $this->channel;
    }

    protected function loadOrder(string $orderNo): ?array
    {
        return $this->order;
    }

    protected function markPaid(int $orderId, array $patch): void
    {
        $this->markPaidCalls++;
        $this->markPaidPatch = $patch;
    }

    protected function markFailed(int $orderId): void
    {
        $this->markFailedCalls++;
    }

    protected function acquireLock(string $orderNo): bool
    {
        if ($this->lockAcquirable) {
            $this->lockAcquired++;
            return true;
        }
        return false;
    }

    protected function releaseLock(string $orderNo): void
    {
        $this->lockReleased++;
    }

    protected function applyLedger(array $order): void
    {
        $this->ledgerCalls++;
        if ($this->ledgerThrows) {
            throw new RuntimeException('ledger boom');
        }
    }

    protected function triggerMerchantNotify(array $order): void
    {
        $this->notifyCalls++;
    }
}

/**
 * 上游回调网关逻辑测试
 *
 * 覆盖：未知通道、验签失败、订单不存在、已支付幂等、金额篡改、并发锁、
 * 成功改单+入账+通知、上游失败置失败、入账异常回滚释放锁。全部脱离 DB/Redis/网络。
 */
class NotifyGatewayLogicTest extends TestCase
{
    /**
     * 构造假适配器
     *
     * @param bool $verify verifyNotify 返回值
     * @param PaymentStatusResult $status parseNotify 返回值
     * @return ChannelAdapterInterface
     */
    private function adapter(bool $verify, PaymentStatusResult $status): ChannelAdapterInterface
    {
        return new class($verify, $status) implements ChannelAdapterInterface {
            public function __construct(private bool $verify, private PaymentStatusResult $status)
            {
            }

            public function createOrder(CreateOrderRequest $request): CreateOrderResult
            {
                return CreateOrderResult::ok('x');
            }

            public function parseNotify(array $payload): PaymentStatusResult
            {
                return $this->status;
            }

            public function verifyNotify(array $payload): bool
            {
                return $this->verify;
            }

            public function queryOrder(string $orderNo, string $upstreamNo = ''): PaymentStatusResult
            {
                return $this->status;
            }

            public function successResponse(): string
            {
                return 'success';
            }
        };
    }

    /**
     * 构造逻辑实例，注入指定假适配器
     */
    private function makeLogic(bool $verify, PaymentStatusResult $status): TestableNotifyGatewayLogic
    {
        $adapter = $this->adapter($verify, $status);
        return new TestableNotifyGatewayLogic(fn (array $channel) => $adapter);
    }

    /** 一笔待支付订单 */
    private function pendingOrder(): array
    {
        return ['id' => 1001, 'order_no' => 'P20260608120000001', 'amount' => '100.0000', 'status' => Order::STATUS_PENDING];
    }

    /**
     * 未知通道 → fail
     */
    public function testUnknownChannel(): void
    {
        $logic = $this->makeLogic(true, PaymentStatusResult::paid('P1', '100.0000'));
        $logic->channel = null;
        $this->assertSame('fail', $logic->handleNotify('nope', []));
    }

    /**
     * 验签失败 → fail，不查单不改单
     */
    public function testVerifyFail(): void
    {
        $logic = $this->makeLogic(false, PaymentStatusResult::paid('P1', '100.0000'));
        $logic->order = $this->pendingOrder();
        $this->assertSame('fail', $logic->handleNotify('mock_test_001', []));
        $this->assertSame(0, $logic->markPaidCalls);
    }

    /**
     * 订单不存在 → fail
     */
    public function testOrderNotFound(): void
    {
        $logic = $this->makeLogic(true, PaymentStatusResult::paid('P_UNKNOWN', '100.0000'));
        $logic->order = null;
        $this->assertSame('fail', $logic->handleNotify('mock_test_001', []));
    }

    /**
     * 成功：待支付 → 改单为已支付 + 入账 + 通知，回 success
     */
    public function testSuccessMarksPaidAndCredits(): void
    {
        $order = $this->pendingOrder();
        $logic = $this->makeLogic(true, PaymentStatusResult::paid($order['order_no'], '100.0000', 'UP888'));
        $logic->order = $order;

        $this->assertSame('success', $logic->handleNotify('mock_test_001', []));
        $this->assertSame(1, $logic->markPaidCalls);
        $this->assertSame(Order::STATUS_PAID, $logic->markPaidPatch['status']);
        $this->assertSame('UP888', $logic->markPaidPatch['upstream_no']);
        $this->assertArrayHasKey('pay_time', $logic->markPaidPatch);
        $this->assertSame(1, $logic->ledgerCalls);   // 入账接缝被调用
        $this->assertSame(1, $logic->notifyCalls);   // 通知接缝被调用
        $this->assertSame(1, $logic->lockAcquired);
    }

    /**
     * 幂等：订单已支付时重复回调 → 直接 success，不再入账
     */
    public function testAlreadyPaidIdempotent(): void
    {
        $order = $this->pendingOrder();
        $order['status'] = Order::STATUS_PAID;
        $logic = $this->makeLogic(true, PaymentStatusResult::paid($order['order_no'], '100.0000'));
        $logic->order = $order;

        $this->assertSame('success', $logic->handleNotify('mock_test_001', []));
        $this->assertSame(0, $logic->markPaidCalls);
        $this->assertSame(0, $logic->ledgerCalls);
    }

    /**
     * 金额篡改：回调金额与订单不一致 → fail，不入账
     */
    public function testAmountMismatch(): void
    {
        $order = $this->pendingOrder();
        $logic = $this->makeLogic(true, PaymentStatusResult::paid($order['order_no'], '99999.0000'));
        $logic->order = $order;

        $this->assertSame('fail', $logic->handleNotify('mock_test_001', []));
        $this->assertSame(0, $logic->markPaidCalls);
        $this->assertSame(0, $logic->ledgerCalls);
    }

    /**
     * 并发锁：抢锁失败 → 直接 success（另一个回调在处理），不重复入账
     */
    public function testConcurrentLockAck(): void
    {
        $order = $this->pendingOrder();
        $logic = $this->makeLogic(true, PaymentStatusResult::paid($order['order_no'], '100.0000'));
        $logic->order = $order;
        $logic->lockAcquirable = false;

        $this->assertSame('success', $logic->handleNotify('mock_test_001', []));
        $this->assertSame(0, $logic->markPaidCalls);
        $this->assertSame(0, $logic->ledgerCalls);
    }

    /**
     * 上游明确失败 → 订单置失败并 ack，无资金变动
     */
    public function testUpstreamFailedMarksOrderFailed(): void
    {
        $order = $this->pendingOrder();
        $logic = $this->makeLogic(true, PaymentStatusResult::failed($order['order_no'], '用户取消', '100.0000'));
        $logic->order = $order;

        $this->assertSame('success', $logic->handleNotify('mock_test_001', []));
        $this->assertSame(1, $logic->markFailedCalls);
        $this->assertSame(0, $logic->markPaidCalls);
        $this->assertSame(0, $logic->ledgerCalls);
    }

    /**
     * 入账异常：事务内入账抛异常 → 向上抛 + 释放锁（供上游重试），不通知商户
     */
    public function testLedgerExceptionReleasesLock(): void
    {
        $order = $this->pendingOrder();
        $logic = $this->makeLogic(true, PaymentStatusResult::paid($order['order_no'], '100.0000'));
        $logic->order = $order;
        $logic->ledgerThrows = true;

        try {
            $logic->handleNotify('mock_test_001', []);
            $this->fail('入账异常应向上传播');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ledger', $e->getMessage());
        }
        $this->assertSame(1, $logic->lockReleased);  // 锁已释放
        $this->assertSame(0, $logic->notifyCalls);   // 未通知商户
    }

    /**
     * 非待支付（已关闭）订单回调 → fail
     */
    public function testClosedOrderRejected(): void
    {
        $order = $this->pendingOrder();
        $order['status'] = Order::STATUS_CLOSED;
        $logic = $this->makeLogic(true, PaymentStatusResult::paid($order['order_no'], '100.0000'));
        $logic->order = $order;

        $this->assertSame('fail', $logic->handleNotify('mock_test_001', []));
        $this->assertSame(0, $logic->markPaidCalls);
    }
}
