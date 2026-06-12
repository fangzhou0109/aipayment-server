<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：对账服务测试（纯比对核心，mock 数据，脱离 DB/网络）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\service\ReconcileService;

/**
 * 对账服务测试
 *
 * 覆盖 README 6.2 要求：一致、金额差、单边账（含漏单）。纯比对核心 + 驱动接缝，
 * 全部 mock 数据，脱离 DB/网络。
 */
class ReconcileServiceTest extends TestCase
{
    /** 构造本地订单行 */
    private function localOrder(string $orderNo, string $amount, int $status): array
    {
        return ['order_no' => $orderNo, 'amount' => $amount, 'status' => $status];
    }

    /** 构造上游账单行（status 与订单状态对齐：1=已支付） */
    private function upstreamRecord(string $orderNo, string $amount, int $status): array
    {
        return ['order_no' => $orderNo, 'amount' => $amount, 'status' => $status];
    }

    // ===== 一致 =====

    /**
     * 全部账实相符：金额与支付状态都一致
     */
    public function testAllConsistent(): void
    {
        $svc = new ReconcileService();
        $local = [
            $this->localOrder('P001', '100.0000', Order::STATUS_PAID),
            $this->localOrder('P002', '50.0000', Order::STATUS_PAID),
        ];
        $upstream = [
            $this->upstreamRecord('P001', '100.0000', Order::STATUS_PAID),
            $this->upstreamRecord('P002', '50.0000', Order::STATUS_PAID),
        ];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(2, $report['summary']['consistent']);
        $this->assertSame(0, $report['summary']['diff_total']);
        $this->assertCount(2, $report[ReconcileService::RESULT_CONSISTENT]);
        $this->assertSame('P001', $report[ReconcileService::RESULT_CONSISTENT][0]['order_no']);
    }

    /**
     * 金额尾差也算一致：100.0000 vs 100（格式化后等值，禁浮点比较）
     */
    public function testConsistentWithEquivalentAmountFormats(): void
    {
        $svc = new ReconcileService();
        $local = [$this->localOrder('P001', '100', Order::STATUS_PAID)];
        $upstream = [$this->upstreamRecord('P001', '100.0000', Order::STATUS_PAID)];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['consistent']);
        $this->assertSame(0, $report['summary']['diff_total']);
    }

    // ===== 金额差 =====

    /**
     * 已匹配但金额不一致 → amount_diff（金额安全优先）
     */
    public function testAmountDiff(): void
    {
        $svc = new ReconcileService();
        $local = [$this->localOrder('P001', '100.0000', Order::STATUS_PAID)];
        $upstream = [$this->upstreamRecord('P001', '99.0000', Order::STATUS_PAID)];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['amount_diff']);
        $this->assertSame(0, $report['summary']['consistent']);
        $item = $report[ReconcileService::RESULT_AMOUNT_DIFF][0];
        $this->assertSame('P001', $item['order_no']);
        $this->assertSame('100.0000', $item['local_amount']);
        $this->assertSame('99.0000', $item['upstream_amount']);
    }

    /**
     * 金额差优先于状态差：金额与状态都不一致时归入 amount_diff（资金安全优先）
     */
    public function testAmountDiffTakesPriorityOverStatus(): void
    {
        $svc = new ReconcileService();
        // 本地已支付 100，上游未支付 80 —— 金额与状态都不同
        $local = [$this->localOrder('P001', '100.0000', Order::STATUS_PAID)];
        $upstream = [$this->upstreamRecord('P001', '80.0000', Order::STATUS_PENDING)];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['amount_diff']);
        $this->assertSame(0, $report['summary']['status_diff']);
    }

    // ===== 状态差 =====

    /**
     * 金额一致但状态不一致：本地未支付、上游已支付 → status_diff（疑似漏回调）
     */
    public function testStatusDiffMissedCallback(): void
    {
        $svc = new ReconcileService();
        $local = [$this->localOrder('P001', '100.0000', Order::STATUS_PENDING)];
        $upstream = [$this->upstreamRecord('P001', '100.0000', Order::STATUS_PAID)];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['status_diff']);
        $item = $report[ReconcileService::RESULT_STATUS_DIFF][0];
        $this->assertFalse($item['local_paid']);
        $this->assertTrue($item['upstream_paid']);
        $this->assertStringContainsString('漏回调', $item['detail']);
    }

    /**
     * 金额一致但状态不一致：本地已支付、上游未支付 → status_diff（疑似超付）
     */
    public function testStatusDiffSuspectedOverpay(): void
    {
        $svc = new ReconcileService();
        $local = [$this->localOrder('P001', '100.0000', Order::STATUS_PAID)];
        $upstream = [$this->upstreamRecord('P001', '100.0000', Order::STATUS_PENDING)];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['status_diff']);
        $item = $report[ReconcileService::RESULT_STATUS_DIFF][0];
        $this->assertTrue($item['local_paid']);
        $this->assertFalse($item['upstream_paid']);
        $this->assertStringContainsString('超付', $item['detail']);
    }

    // ===== 单边账 =====

    /**
     * 本地有、上游无 → local_only（单边账）
     */
    public function testLocalOnly(): void
    {
        $svc = new ReconcileService();
        $local = [
            $this->localOrder('P001', '100.0000', Order::STATUS_PAID),
            $this->localOrder('P002', '50.0000', Order::STATUS_PENDING),
        ];
        $upstream = [$this->upstreamRecord('P001', '100.0000', Order::STATUS_PAID)];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['local_only']);
        $this->assertSame('P002', $report[ReconcileService::RESULT_LOCAL_ONLY][0]['order_no']);
        $this->assertNull($report[ReconcileService::RESULT_LOCAL_ONLY][0]['upstream_amount']);
    }

    /**
     * 上游有、本地无 → upstream_only（漏单）
     */
    public function testUpstreamOnlyMissingOrder(): void
    {
        $svc = new ReconcileService();
        $local = [$this->localOrder('P001', '100.0000', Order::STATUS_PAID)];
        $upstream = [
            $this->upstreamRecord('P001', '100.0000', Order::STATUS_PAID),
            $this->upstreamRecord('P999', '300.0000', Order::STATUS_PAID),
        ];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['upstream_only']);
        $item = $report[ReconcileService::RESULT_UPSTREAM_ONLY][0];
        $this->assertSame('P999', $item['order_no']);
        $this->assertSame('300.0000', $item['upstream_amount']);
        $this->assertNull($item['local_amount']);
        $this->assertStringContainsString('漏单', $item['detail']);
    }

    // ===== 兼容字段 + 边界 =====

    /**
     * 上游账单的「已支付」可用 paid(bool) / outcome(int) 表达，等价于 status=1
     */
    public function testUpstreamPaidFieldVariants(): void
    {
        $svc = new ReconcileService();
        $local = [
            $this->localOrder('P001', '100.0000', Order::STATUS_PAID),
            $this->localOrder('P002', '100.0000', Order::STATUS_PAID),
        ];
        $upstream = [
            ['order_no' => 'P001', 'amount' => '100.0000', 'paid' => true],
            ['order_no' => 'P002', 'amount' => '100.0000', 'outcome' => 1],
        ];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(2, $report['summary']['consistent']);
    }

    /**
     * 无平台订单号的记录无法对账，被剔除（不计入任何分类）
     */
    public function testRecordsWithoutOrderNoAreSkipped(): void
    {
        $svc = new ReconcileService();
        $local = [
            $this->localOrder('', '100.0000', Order::STATUS_PAID),
            $this->localOrder('P001', '100.0000', Order::STATUS_PAID),
        ];
        $upstream = [
            ['order_no' => '', 'amount' => '50.0000', 'status' => 1],
            $this->upstreamRecord('P001', '100.0000', Order::STATUS_PAID),
        ];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['total_local']);
        $this->assertSame(1, $report['summary']['consistent']);
        $this->assertSame(0, $report['summary']['diff_total']);
    }

    /**
     * 混合场景：一致 + 金额差 + 状态差 + 双向单边账，汇总计数正确
     */
    public function testMixedScenarioSummary(): void
    {
        $svc = new ReconcileService();
        $local = [
            $this->localOrder('P001', '100.0000', Order::STATUS_PAID),    // 一致
            $this->localOrder('P002', '100.0000', Order::STATUS_PAID),    // 金额差
            $this->localOrder('P003', '100.0000', Order::STATUS_PENDING), // 状态差
            $this->localOrder('P004', '100.0000', Order::STATUS_PAID),    // 本地单边
        ];
        $upstream = [
            $this->upstreamRecord('P001', '100.0000', Order::STATUS_PAID),
            $this->upstreamRecord('P002', '88.0000', Order::STATUS_PAID),
            $this->upstreamRecord('P003', '100.0000', Order::STATUS_PAID),
            $this->upstreamRecord('P900', '100.0000', Order::STATUS_PAID), // 上游单边（漏单）
        ];

        $report = $svc->compare($local, $upstream);

        $this->assertSame(1, $report['summary']['consistent']);
        $this->assertSame(1, $report['summary']['amount_diff']);
        $this->assertSame(1, $report['summary']['status_diff']);
        $this->assertSame(1, $report['summary']['local_only']);
        $this->assertSame(1, $report['summary']['upstream_only']);
        $this->assertSame(4, $report['summary']['diff_total']);
        $this->assertSame(4, $report['summary']['total_local']);
    }

    /**
     * 两侧皆空 → 全 0，不报错
     */
    public function testEmptyBothSides(): void
    {
        $svc = new ReconcileService();
        $report = $svc->compare([], []);

        $this->assertSame(0, $report['summary']['total_local']);
        $this->assertSame(0, $report['summary']['diff_total']);
        $this->assertSame([], $report[ReconcileService::RESULT_CONSISTENT]);
    }

    // ===== 驱动层接缝 =====

    /**
     * 驱动层 reconcile：注入两侧加载器，串起 loadLocalOrders/loadUpstreamBill → compare
     */
    public function testReconcileDriverWithInjectedLoaders(): void
    {
        $criteriaSeen = [];
        $svc = new ReconcileService(
            function (array $criteria) use (&$criteriaSeen): array {
                $criteriaSeen['local'] = $criteria;
                return [$this->localOrder('P001', '100.0000', Order::STATUS_PAID)];
            },
            function (array $criteria) use (&$criteriaSeen): array {
                $criteriaSeen['upstream'] = $criteria;
                return [$this->upstreamRecord('P001', '100.0000', Order::STATUS_PAID)];
            }
        );

        $report = $svc->reconcile(['channel_id' => 7]);

        $this->assertSame(1, $report['summary']['consistent']);
        $this->assertSame(7, $criteriaSeen['local']['channel_id']);
        $this->assertSame(7, $criteriaSeen['upstream']['channel_id']);
    }

    /**
     * 驱动层默认上游账单为空：本地订单全部判为单边账（漏接对账文件时的保守表现）
     */
    public function testReconcileDriverEmptyUpstreamMarksAllLocalOnly(): void
    {
        $svc = new ReconcileService(
            fn(array $criteria): array => [
                $this->localOrder('P001', '100.0000', Order::STATUS_PAID),
                $this->localOrder('P002', '50.0000', Order::STATUS_PAID),
            ]
            // 不注入上游加载器 → loadUpstreamBill 默认返回 []
        );

        $report = $svc->reconcile();

        $this->assertSame(2, $report['summary']['local_only']);
        $this->assertSame(0, $report['summary']['consistent']);
    }
}
