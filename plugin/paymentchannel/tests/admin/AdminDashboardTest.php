<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：平台工作台统计单测（脱离 DB）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\admin;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\AdminDashboardLogic;

/**
 * 可测试的平台工作台统计逻辑：内存替代 DB。
 */
class TestableAdminDashboardLogic extends AdminDashboardLogic
{
    public array $merchantPool = [
        'total' => 12,
        'active' => 10,
        'balance' => '50000.0000',
        'balance_freeze' => '1200.0000',
    ];
    public array $period = [
        'count' => 100,
        'amount' => '10000.0000',
        'paid_count' => 80,
        'paid_amount' => '7800.0000',
        'fee_amount' => '200.0000',
        'pending_count' => 15,
        'failed_count' => 5,
    ];
    public int $pendingWithdraw = 3;
    public int $pendingRecharge = 2;
    public int $notifyPending = 1;
    public int $notifyFailed = 4;
    public int $payChannels = 5;
    public int $transferChannels = 3;
    public string $pendingWithdrawAmount = '300.0000';
    public string $pendingRechargeAmount = '150.0000';

    protected function merchantPoolStats(): array
    {
        return $this->merchantPool;
    }

    protected function periodOrderStats(string $start, string $end): array
    {
        return $this->period;
    }

    protected function pendingWithdrawCount(): int
    {
        return $this->pendingWithdraw;
    }

    protected function pendingRechargeCount(): int
    {
        return $this->pendingRecharge;
    }

    protected function notifyPendingCount(): int
    {
        return $this->notifyPending;
    }

    protected function notifyFailedCount(): int
    {
        return $this->notifyFailed;
    }

    protected function activePayChannelCount(): int
    {
        return $this->payChannels;
    }

    protected function activeTransferChannelCount(): int
    {
        return $this->transferChannels;
    }

    protected function recentOrders(int $limit): array
    {
        return [
            ['id' => 1, 'order_no' => 'P001', 'merchant_name' => '测试商户', 'status' => 1],
        ];
    }

    protected function pendingWithdrawAmount(): string
    {
        return $this->pendingWithdrawAmount;
    }

    protected function pendingRechargeAmount(): string
    {
        return $this->pendingRechargeAmount;
    }

    protected function payTypeDistToday(): array
    {
        return [
            ['pay_type' => 1, 'order_count' => 10, 'paid_amount' => '1000.0000'],
        ];
    }

    protected function topMerchantsToday(int $limit): array
    {
        return [
            [
                'merchant_id' => 1,
                'mch_id' => 'M001',
                'merchant_name' => '头部商户',
                'paid_count' => 20,
                'paid_amount' => '5000.0000',
                'fee_amount' => '50.0000',
            ],
        ];
    }

    protected function recentPendingWithdraws(int $limit): array
    {
        return [
            ['id' => 1, 'withdraw_no' => 'W001', 'merchant_name' => '测试商户', 'amount' => '100.0000'],
        ];
    }

    protected function recentPendingRecharges(int $limit): array
    {
        return [];
    }
}

/**
 * 平台工作台统计测试
 */
class AdminDashboardTest extends TestCase
{
    /**
     * 全平台聚合：资金池、今日代收、待办与趋势
     */
    public function testDashboardStats(): void
    {
        $logic = new TestableAdminDashboardLogic();
        $data = $logic->stats();

        $this->assertSame(12, $data['merchant_count']);
        $this->assertSame(10, $data['merchant_active_count']);
        $this->assertSame('51200.0000', $data['balance_pool_total']);
        $this->assertSame(100, $data['today_order_count']);
        $this->assertSame('7800.0000', $data['today_paid_amount']);
        $this->assertSame('200.0000', $data['today_fee_amount']);
        $this->assertSame(80.0, $data['today_success_rate']);
        $this->assertSame(3, $data['pending_withdraw_count']);
        $this->assertSame('300.0000', $data['pending_withdraw_amount']);
        $this->assertSame(4, $data['notify_failed_count']);
        $this->assertSame(5, $data['pay_channel_active_count']);
        $this->assertNotEmpty($data['stats_time']);
        $this->assertCount(7, $data['trend_7d']);
        $this->assertCount(1, $data['pay_type_dist_today']);
        $this->assertCount(1, $data['top_merchants_today']);
        $this->assertCount(1, $data['recent_orders']);
        $this->assertCount(1, $data['recent_pending_withdraws']);
    }
}
