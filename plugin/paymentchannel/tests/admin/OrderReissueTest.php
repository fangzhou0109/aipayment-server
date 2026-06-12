<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：订单补单逻辑测试（DB/服务接缝重写，脱离数据库）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\admin;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\OrderLogic;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\service\LedgerService;
use plugin\paymentchannel\service\MerchantNotifyService;

/**
 * 假入账服务：记录 credit 调用，模拟幂等。
 */
class FakeLedgerService extends LedgerService
{
    public int $creditCalls = 0;
    public array $creditedOrders = [];

    public function credit(array $order): bool
    {
        $this->creditCalls++;
        $this->creditedOrders[] = $order['order_no'] ?? '';
        return true;
    }
}

/**
 * 假通知服务：记录 dispatch 调用。
 */
class FakeMerchantNotifyService extends MerchantNotifyService
{
    public int $dispatchCalls = 0;

    public function dispatch(array $order): bool
    {
        $this->dispatchCalls++;
        return true;
    }
}

/**
 * 可测试的订单逻辑：重写 DB 接缝与事务。
 */
class TestableOrderLogic extends OrderLogic
{
    public ?array $order = null;
    public int $markPaidCalls = 0;

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function loadOrder(int $orderId): ?array
    {
        return $this->order;
    }

    protected function markPaid(int $orderId): void
    {
        $this->markPaidCalls++;
        if ($this->order !== null) {
            $this->order['status'] = Order::STATUS_PAID;
        }
    }
}

/**
 * 订单补单测试
 *
 * 覆盖：待支付补单（置已支付+入账+通知）、已支付补单（仅重发通知不重复入账）、
 * 失败订单补单、订单不存在异常。
 */
class OrderReissueTest extends TestCase
{
    private function makeLogic(array $order, FakeLedgerService $ledger, FakeMerchantNotifyService $notify): TestableOrderLogic
    {
        $logic = new TestableOrderLogic($ledger, $notify);
        $logic->order = $order;
        return $logic;
    }

    private function pendingOrder(): array
    {
        return [
            'id' => 1001, 'order_no' => 'P20260608120000001', 'merchant_id' => 1,
            'mch_id' => 'M001', 'amount' => '100.0000', 'real_amount' => '97.4000',
            'status' => Order::STATUS_PENDING, 'notify_url' => 'https://m.example.com/notify',
        ];
    }

    /**
     * 待支付补单：标记已支付 + 入账 + 通知，返回 reissued
     */
    public function testReissuePending(): void
    {
        $ledger = new FakeLedgerService();
        $notify = new FakeMerchantNotifyService();
        $logic = $this->makeLogic($this->pendingOrder(), $ledger, $notify);

        $result = $logic->reissue(1001);

        $this->assertSame('reissued', $result['result']);
        $this->assertSame(1, $logic->markPaidCalls);
        $this->assertSame(1, $ledger->creditCalls);   // 入账一次
        $this->assertSame(1, $notify->dispatchCalls);  // 通知一次
    }

    /**
     * 已支付补单：仅重发通知，不再入账（幂等）
     */
    public function testReissueAlreadyPaid(): void
    {
        $ledger = new FakeLedgerService();
        $notify = new FakeMerchantNotifyService();
        $order = $this->pendingOrder();
        $order['status'] = Order::STATUS_PAID;
        $logic = $this->makeLogic($order, $ledger, $notify);

        $result = $logic->reissue(1001);

        $this->assertSame('already_paid', $result['result']);
        $this->assertSame(0, $logic->markPaidCalls);  // 不改单
        $this->assertSame(0, $ledger->creditCalls);   // 不重复入账
        $this->assertSame(1, $notify->dispatchCalls);  // 仅重发通知
    }

    /**
     * 失败订单补单：运营核实后强制置已支付 + 入账
     */
    public function testReissueFailedOrder(): void
    {
        $ledger = new FakeLedgerService();
        $notify = new FakeMerchantNotifyService();
        $order = $this->pendingOrder();
        $order['status'] = Order::STATUS_FAILED;
        $logic = $this->makeLogic($order, $ledger, $notify);

        $result = $logic->reissue(1001);

        $this->assertSame('reissued', $result['result']);
        $this->assertSame(1, $ledger->creditCalls);
    }

    /**
     * 订单不存在 → 异常
     */
    public function testReissueOrderNotFound(): void
    {
        $ledger = new FakeLedgerService();
        $notify = new FakeMerchantNotifyService();
        $logic = new TestableOrderLogic($ledger, $notify);
        $logic->order = null;

        $this->expectException(PaymentException::class);
        $logic->reissue(9999);
    }
}
