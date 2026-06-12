<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户查单逻辑测试（DB 接缝重写，脱离数据库）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\gateway;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\PayGatewayLogic;
use plugin\paymentchannel\app\model\Order;

/**
 * 可测试的网关逻辑：重写查单 DB 接缝。
 */
class TestableQueryLogic extends PayGatewayLogic
{
    /** 模拟订单（null=不存在） */
    public ?array $order = null;
    /** 捕获查询入参 */
    public int $queriedMerchantId = 0;
    public string $queriedOutTradeNo = '';

    protected function findOrder(int $merchantId, string $outTradeNo): ?array
    {
        $this->queriedMerchantId = $merchantId;
        $this->queriedOutTradeNo = $outTradeNo;
        return $this->order;
    }
}

/**
 * 商户查单测试
 */
class PayGatewayQueryTest extends TestCase
{
    private function merchant(): array
    {
        return ['id' => 1, 'mch_id' => 'M001'];
    }

    /**
     * 查单成功：返回订单状态，merchant_id 强约束（仅查自己的单）
     */
    public function testQuerySuccess(): void
    {
        $logic = new TestableQueryLogic();
        $logic->order = [
            'order_no'     => 'P20260608120000001',
            'out_trade_no' => 'OUT001',
            'upstream_no'  => 'UP888',
            'amount'       => '100.0000',
            'status'       => Order::STATUS_PAID,
            'pay_time'     => '2026-06-08 12:00:00',
        ];

        $result = $logic->queryOrder($this->merchant(), ['order_id' => 'OUT001']);

        $this->assertSame('P20260608120000001', $result['order_no']);
        $this->assertSame('100.0000', $result['amount']);
        $this->assertSame(Order::STATUS_PAID, $result['status']);
        $this->assertSame('SUCCESS', $result['trade_status']);
        // merchant_id 强约束传入
        $this->assertSame(1, $logic->queriedMerchantId);
        $this->assertSame('OUT001', $logic->queriedOutTradeNo);
    }

    /**
     * 状态码 → 商户视角状态串映射
     */
    public function testTradeStatusText(): void
    {
        $this->assertSame('NOTPAY', PayGatewayLogic::tradeStatusText(Order::STATUS_PENDING));
        $this->assertSame('SUCCESS', PayGatewayLogic::tradeStatusText(Order::STATUS_PAID));
        $this->assertSame('FAILED', PayGatewayLogic::tradeStatusText(Order::STATUS_FAILED));
        $this->assertSame('CLOSED', PayGatewayLogic::tradeStatusText(Order::STATUS_CLOSED));
    }

    /**
     * 缺商户订单号 → 拒绝
     */
    public function testMissingOrderId(): void
    {
        $logic = new TestableQueryLogic();
        $this->expectException(PaymentException::class);
        $logic->queryOrder($this->merchant(), []);
    }

    /**
     * 订单不存在 → 拒绝
     */
    public function testOrderNotFound(): void
    {
        $logic = new TestableQueryLogic();
        $logic->order = null;
        $this->expectException(PaymentException::class);
        $logic->queryOrder($this->merchant(), ['order_id' => 'NOPE']);
    }
}
