<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户业务测试（商户上下文隔离 + 首页统计，脱离 DB）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\merchant;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\controller\merchant\BaseMerchantController;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\MerchantDashboardLogic;

/**
 * 可测试的首页统计逻辑：内存替代 DB，覆盖聚合接缝。
 */
class TestableMerchantDashboardLogic extends MerchantDashboardLogic
{
    public array $merchant = [
        'mch_id'         => 'M007',
        'name'           => '测试商户',
        'balance'        => '888.0000',
        'balance_freeze' => '12.0000',
    ];
    public array $period = [
        'count'         => 5,
        'amount'        => '500.0000',
        'paid_count'    => 3,
        'paid_amount'   => '291.0000',
        'fee_amount'    => '9.0000',
        'pending_count' => 1,
        'failed_count'  => 1,
    ];
    public int $pendingWithdraw = 2;
    public int $pendingRecharge = 1;
    public string $pendingWithdrawAmountValue = '200.0000';
    public string $pendingRechargeAmountValue = '50.0000';
    /** 记录被查询的 merchantId，验证强制过滤 */
    public array $queriedIds = [];

    protected function loadMerchant(int $merchantId): ?array
    {
        $this->queriedIds[] = $merchantId;
        return $this->merchant;
    }

    protected function periodOrderStats(int $merchantId, string $start, string $end): array
    {
        $this->queriedIds[] = $merchantId;
        return $this->period;
    }

    protected function recentOrders(int $merchantId, int $limit): array
    {
        $this->queriedIds[] = $merchantId;
        return [
            ['id' => 1, 'order_no' => 'P001', 'out_trade_no' => 'O001', 'amount' => '10.0000', 'status' => 1],
        ];
    }

    protected function pendingWithdrawBundle(int $merchantId, int $recentLimit): array
    {
        $this->queriedIds[] = $merchantId;
        return [
            'count'  => $this->pendingWithdraw,
            'amount' => $this->pendingWithdrawAmountValue,
            'recent' => [
                ['id' => 1, 'withdraw_no' => 'W001', 'amount' => '100.0000', 'create_time' => '2026-06-09'],
            ],
        ];
    }

    protected function pendingRechargeBundle(int $merchantId, int $recentLimit): array
    {
        $this->queriedIds[] = $merchantId;
        return [
            'count'  => $this->pendingRecharge,
            'amount' => $this->pendingRechargeAmountValue,
            'recent' => [],
        ];
    }

    protected function payTypeDistToday(int $merchantId): array
    {
        $this->queriedIds[] = $merchantId;
        return [['pay_type' => 1, 'order_count' => 3, 'paid_amount' => '291.0000']];
    }
}

/**
 * 商户门户业务测试
 *
 * 覆盖 README 5.1/5.2 安全核心：商户ID 取自 token（不信任 params）；首页统计按商户聚合。
 */
class MerchantPortalTest extends TestCase
{
    // ===== 商户上下文解析（越权隔离核心）=====

    /**
     * 合法 token 头解析出商户ID
     */
    public function testResolveMerchantIdValid(): void
    {
        $id = BaseMerchantController::resolveMerchantId(['id' => 7, 'mch_id' => 'M007', 'plat' => 'merchant']);
        $this->assertSame(7, $id);
    }

    /**
     * 缺失/非数组头 → 抛异常（无身份不可访问业务）
     */
    public function testResolveMerchantIdRejectsMissing(): void
    {
        $this->expectException(PaymentException::class);
        BaseMerchantController::resolveMerchantId(null);
    }

    /**
     * id 为 0/负数 → 抛异常（非法身份）
     */
    public function testResolveMerchantIdRejectsZero(): void
    {
        $this->expectException(PaymentException::class);
        BaseMerchantController::resolveMerchantId(['id' => 0]);
    }

    /**
     * 头里没有 id 字段 → 抛异常
     */
    public function testResolveMerchantIdRejectsNoId(): void
    {
        $this->expectException(PaymentException::class);
        BaseMerchantController::resolveMerchantId(['mch_id' => 'M007']);
    }

    // ===== 首页统计 =====

    /**
     * 统计聚合：余额格式化 + 今日订单/已支付 + 待审核提现数，全部按传入 merchantId 过滤
     */
    public function testDashboardStats(): void
    {
        $logic = new TestableMerchantDashboardLogic();
        $data = $logic->stats(7);

        $this->assertSame('M007', $data['mch_id']);
        $this->assertSame('888.0000', $data['balance']);
        $this->assertSame('900.0000', $data['balance_total']);
        $this->assertSame(5, $data['today_order_count']);
        $this->assertSame(60.0, $data['today_success_rate']);
        $this->assertSame(2, $data['pending_withdraw_count']);
        $this->assertSame('200.0000', $data['pending_withdraw_amount']);
        $this->assertSame(1, $data['pending_recharge_count']);
        $this->assertSame('9.0000', $data['today_fee_amount']);
        $this->assertNotEmpty($data['stats_time']);
        $this->assertCount(7, $data['trend_7d']);
        $this->assertCount(1, $data['pay_type_dist_today']);
        $this->assertCount(1, $data['recent_orders']);
        $this->assertCount(1, $data['recent_pending_withdraws']);
        // 所有子查询都用同一个 merchantId（强制按商户过滤，无越权）
        $this->assertContains(7, $logic->queriedIds);
        $this->assertTrue(count($logic->queriedIds) >= 3);
    }

    /**
     * 商户不存在时余额回退为 0（不报错）
     */
    public function testDashboardStatsMerchantMissing(): void
    {
        $logic = new class extends MerchantDashboardLogic {
            protected function loadMerchant(int $merchantId): ?array
            {
                return null;
            }
            protected function periodOrderStats(int $merchantId, string $start, string $end): array
            {
                return [
                    'count' => 0, 'amount' => '0', 'paid_count' => 0, 'paid_amount' => '0',
                    'fee_amount' => '0', 'pending_count' => 0, 'failed_count' => 0,
                ];
            }
            protected function recentOrders(int $merchantId, int $limit): array
            {
                return [];
            }
            protected function pendingWithdrawBundle(int $merchantId, int $recentLimit): array
            {
                return ['count' => 0, 'amount' => '0.0000', 'recent' => []];
            }
            protected function pendingRechargeBundle(int $merchantId, int $recentLimit): array
            {
                return ['count' => 0, 'amount' => '0.0000', 'recent' => []];
            }
            protected function payTypeDistToday(int $merchantId): array
            {
                return [];
            }
        };
        $data = $logic->stats(99);
        $this->assertSame('0.0000', $data['balance']);
        $this->assertSame(0, $data['today_order_count']);
    }
}
