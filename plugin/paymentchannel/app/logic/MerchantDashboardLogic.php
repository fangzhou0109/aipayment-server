<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户首页统计逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\app\model\Recharge;
use plugin\paymentchannel\app\model\Withdraw;
use plugin\paymentchannel\service\AmountHelper;

/**
 * 商户门户首页统计逻辑层
 *
 * 聚合当前商户的账户、今日/昨日/本月经营概览、近 7 日趋势与最近订单（只读）。
 * 所有统计**强制按 merchantId 过滤**。金额聚合走 {@see AmountHelper}（bcmath，禁浮点）。
 *
 * 可测试性：DB 访问全部抽为 protected 接缝，单测以子类内存替代 DB。
 */
class MerchantDashboardLogic
{
    /** 首页最近订单条数 */
    private const RECENT_ORDER_LIMIT = 10;

    /** 首页待审简表条数 */
    private const RECENT_PENDING_LIMIT = 5;

    /**
     * 汇总商户首页统计
     *
     * @param int $merchantId 商户ID（来自 token）
     * @return array 账户、经营报表、趋势与最近订单
     */
    public function stats(int $merchantId): array
    {
        $merchant = $this->loadMerchant($merchantId);
        $balance = (string) ($merchant['balance'] ?? '0');
        $freeze = (string) ($merchant['balance_freeze'] ?? '0');

        $today = $this->todayOrderStats($merchantId);
        $yesterday = $this->yesterdayOrderStats($merchantId);
        $month = $this->monthOrderStats($merchantId);

        $todayPaid = (string) ($today['paid_amount'] ?? '0');
        $yesterdayPaid = (string) ($yesterday['paid_amount'] ?? '0');

        return [
            'stats_time'               => date('Y-m-d H:i:s'),
            // 商户信息
            'mch_id'                   => (string) ($merchant['mch_id'] ?? ''),
            'merchant_name'            => (string) ($merchant['name'] ?? ''),
            // 账户（兼容旧字段）
            'balance'                  => AmountHelper::format($balance),
            'balance_freeze'           => AmountHelper::format($freeze),
            'balance_total'            => AmountHelper::format(AmountHelper::add($balance, $freeze)),
            // 今日代收
            'today_order_count'        => (int) ($today['count'] ?? 0),
            'today_order_amount'       => AmountHelper::format((string) ($today['amount'] ?? '0')),
            'today_paid_count'         => (int) ($today['paid_count'] ?? 0),
            'today_paid_amount'        => AmountHelper::format($todayPaid),
            'today_fee_amount'         => AmountHelper::format((string) ($today['fee_amount'] ?? '0')),
            'today_pending_count'      => (int) ($today['pending_count'] ?? 0),
            'today_failed_count'       => (int) ($today['failed_count'] ?? 0),
            'today_success_rate'       => $this->calcSuccessRate(
                (int) ($today['count'] ?? 0),
                (int) ($today['paid_count'] ?? 0)
            ),
            // 昨日对比（实收）
            'yesterday_paid_count'     => (int) ($yesterday['paid_count'] ?? 0),
            'yesterday_paid_amount'    => AmountHelper::format($yesterdayPaid),
            'paid_amount_change_pct'   => $this->calcChangePercent($todayPaid, $yesterdayPaid),
            'order_count_change_pct'   => $this->calcChangePercent(
                (string) ((int) ($today['count'] ?? 0)),
                (string) ((int) ($yesterday['count'] ?? 0))
            ),
            // 本月累计
            'month_order_count'        => (int) ($month['count'] ?? 0),
            'month_order_amount'       => AmountHelper::format((string) ($month['amount'] ?? '0')),
            'month_paid_count'         => (int) ($month['paid_count'] ?? 0),
            'month_paid_amount'        => AmountHelper::format((string) ($month['paid_amount'] ?? '0')),
            'month_fee_amount'         => AmountHelper::format((string) ($month['fee_amount'] ?? '0')),
            // 待办（单笔数/金额/队列同源聚合，避免 count 与 sum 不一致）
            ...$this->mapPendingWithdrawStats($merchantId),
            ...$this->mapPendingRechargeStats($merchantId),
            // 图表与列表
            'trend_7d'                 => $this->orderTrend7d($merchantId),
            'pay_type_dist_today'      => $this->payTypeDistToday($merchantId),
            'recent_orders'            => $this->recentOrders($merchantId, self::RECENT_ORDER_LIMIT),
        ];
    }

    // ===== 接缝：DB 访问，默认走 ThinkORM，单测可在子类重写以脱离数据库 =====

    /**
     * 加载商户账户与展示信息
     *
     * @param int $merchantId 商户ID
     * @return array|null
     */
    protected function loadMerchant(int $merchantId): ?array
    {
        $m = Merchant::where('id', $merchantId)->find();
        if (!$m) {
            return null;
        }

        return [
            'mch_id'         => (string) $m->mch_id,
            'name'           => (string) $m->name,
            'balance'        => (string) $m->balance,
            'balance_freeze' => (string) $m->balance_freeze,
        ];
    }

    /**
     * 统计今日订单
     */
    protected function todayOrderStats(int $merchantId): array
    {
        return $this->periodOrderStats($merchantId, date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
    }

    /**
     * 统计昨日订单
     */
    protected function yesterdayOrderStats(int $merchantId): array
    {
        $day = date('Y-m-d', strtotime('-1 day'));

        return $this->periodOrderStats($merchantId, $day . ' 00:00:00', $day . ' 23:59:59');
    }

    /**
     * 统计本月订单（自然月）
     */
    protected function monthOrderStats(int $merchantId): array
    {
        return $this->periodOrderStats(
            $merchantId,
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59')
        );
    }

    /**
     * 按时间窗统计订单笔数/金额、已支付、待支付、失败关闭
     *
     * @return array{count:int, amount:string, paid_count:int, paid_amount:string, fee_amount:string, pending_count:int, failed_count:int}
     */
    protected function periodOrderStats(int $merchantId, string $start, string $end): array
    {
        $base = Order::where('merchant_id', $merchantId)
            ->where('create_time', 'between', [$start, $end]);

        $count = (clone $base)->count();
        $amount = (string) ((clone $base)->sum('amount') ?: '0');

        $paidBase = (clone $base)->where('status', Order::STATUS_PAID);
        $paidCount = (clone $paidBase)->count();
        $paidAmount = (string) ((clone $paidBase)->sum('real_amount') ?: '0');
        $feeAmount = (string) ((clone $paidBase)->sum('fee') ?: '0');

        $pendingCount = (clone $base)->where('status', Order::STATUS_PENDING)->count();
        $failedCount = (clone $base)->whereIn('status', [Order::STATUS_FAILED, Order::STATUS_CLOSED])->count();

        return [
            'count'         => (int) $count,
            'amount'        => $amount,
            'paid_count'    => (int) $paidCount,
            'paid_amount'   => $paidAmount,
            'fee_amount'    => $feeAmount,
            'pending_count' => (int) $pendingCount,
            'failed_count'  => (int) $failedCount,
        ];
    }

    /**
     * 近 7 日代收趋势（含今日）
     *
     * @return list<array{date:string, label:string, order_count:int, order_amount:string, paid_count:int, paid_amount:string, fee_amount:string}>
     */
    protected function orderTrend7d(int $merchantId): array
    {
        $items = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $stats = $this->periodOrderStats($merchantId, $day . ' 00:00:00', $day . ' 23:59:59');
            $items[] = [
                'date'         => $day,
                'label'        => date('m-d', strtotime($day)),
                'order_count'  => (int) ($stats['count'] ?? 0),
                'order_amount' => AmountHelper::format((string) ($stats['amount'] ?? '0')),
                'paid_count'   => (int) ($stats['paid_count'] ?? 0),
                'paid_amount'  => AmountHelper::format((string) ($stats['paid_amount'] ?? '0')),
                'fee_amount'   => AmountHelper::format((string) ($stats['fee_amount'] ?? '0')),
            ];
        }

        return $items;
    }

    /**
     * 最近代收订单（首页简表）
     *
     * @return list<array<string, mixed>>
     */
    protected function recentOrders(int $merchantId, int $limit): array
    {
        $rows = Order::where('merchant_id', $merchantId)
            ->field('id,order_no,out_trade_no,amount,real_amount,status,pay_type,create_time,pay_time')
            ->order('id', 'desc')
            ->limit(max(1, min(20, $limit)))
            ->select();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'           => (int) $row->id,
                'order_no'     => (string) $row->order_no,
                'out_trade_no' => (string) $row->out_trade_no,
                'amount'       => AmountHelper::format((string) $row->amount),
                'real_amount'  => AmountHelper::format((string) $row->real_amount),
                'status'       => (int) $row->status,
                'pay_type'     => (int) $row->pay_type,
                'create_time'  => (string) $row->create_time,
                'pay_time'     => (string) ($row->pay_time ?? ''),
            ];
        }

        return $items;
    }

    /**
     * 映射待审提现统计字段（供 stats 展开）
     *
     * @return array{pending_withdraw_count:int, pending_withdraw_amount:string, recent_pending_withdraws:list<array<string,mixed>>}
     */
    protected function mapPendingWithdrawStats(int $merchantId): array
    {
        $bundle = $this->pendingWithdrawBundle($merchantId, self::RECENT_PENDING_LIMIT);

        return [
            'pending_withdraw_count'   => (int) ($bundle['count'] ?? 0),
            'pending_withdraw_amount'  => (string) ($bundle['amount'] ?? '0.0000'),
            'recent_pending_withdraws' => $bundle['recent'] ?? [],
        ];
    }

    /**
     * 映射待审充值统计字段
     *
     * @return array{pending_recharge_count:int, pending_recharge_amount:string, recent_pending_recharges:list<array<string,mixed>>}
     */
    protected function mapPendingRechargeStats(int $merchantId): array
    {
        $bundle = $this->pendingRechargeBundle($merchantId, self::RECENT_PENDING_LIMIT);

        return [
            'pending_recharge_count'   => (int) ($bundle['count'] ?? 0),
            'pending_recharge_amount'  => (string) ($bundle['amount'] ?? '0.0000'),
            'recent_pending_recharges' => $bundle['recent'] ?? [],
        ];
    }

    /**
     * 待审提现：一次查询聚合笔数、金额与最近队列（bcmath 累加，禁 ORM sum 浮点误差）
     *
     * @return array{count:int, amount:string, recent:list<array<string,mixed>>}
     */
    protected function pendingWithdrawBundle(int $merchantId, int $recentLimit): array
    {
        // status 须用 whereRaw/三元，避免 where('status', 0) 在部分 ORM 版本被当成空值忽略
        $rows = Withdraw::where('merchant_id', $merchantId)
            ->whereRaw('status = ?', [Withdraw::STATUS_PENDING])
            ->order('id', 'desc')
            ->select();

        return $this->buildPendingBundle($rows, $recentLimit, function ($row) {
            return [
                'id'          => (int) $row->id,
                'withdraw_no' => (string) $row->withdraw_no,
                'amount'      => AmountHelper::format((string) $row->amount),
                'create_time' => (string) ($row->create_time ?? ''),
            ];
        });
    }

    /**
     * 待审充值：同源聚合
     *
     * @return array{count:int, amount:string, recent:list<array<string,mixed>>}
     */
    protected function pendingRechargeBundle(int $merchantId, int $recentLimit): array
    {
        $rows = Recharge::where('merchant_id', $merchantId)
            ->whereRaw('status = ?', [Recharge::STATUS_PENDING])
            ->order('id', 'desc')
            ->select();

        return $this->buildPendingBundle($rows, $recentLimit, function ($row) {
            return [
                'id'          => (int) $row->id,
                'recharge_no' => (string) $row->recharge_no,
                'amount'      => AmountHelper::format((string) $row->amount),
                'create_time' => (string) ($row->create_time ?? ''),
            ];
        });
    }

    /**
     * 从待审记录集合构建 count/amount/recent
     *
     * @param iterable $rows ORM 结果集
     * @param callable $mapRecent 单行 → 队列项
     * @return array{count:int, amount:string, recent:list<array<string,mixed>>}
     */
    protected function buildPendingBundle(iterable $rows, int $recentLimit, callable $mapRecent): array
    {
        $total = '0';
        $recent = [];
        $count = 0;
        $limit = max(1, min(10, $recentLimit));

        foreach ($rows as $row) {
            $count++;
            $total = AmountHelper::add($total, (string) ($row->amount ?? '0'));
            if (count($recent) < $limit) {
                $recent[] = $mapRecent($row);
            }
        }

        return [
            'count'  => $count,
            'amount' => AmountHelper::format($total),
            'recent' => $recent,
        ];
    }

    /**
     * 今日已支付订单按支付类型分布
     *
     * @return list<array{pay_type:int, order_count:int, paid_amount:string}>
     */
    protected function payTypeDistToday(int $merchantId): array
    {
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');

        $rows = Order::where('merchant_id', $merchantId)
            ->where('create_time', 'between', [$start, $end])
            ->where('status', Order::STATUS_PAID)
            ->field('pay_type, COUNT(*) as order_count, SUM(real_amount) as paid_amount')
            ->group('pay_type')
            ->order('order_count', 'desc')
            ->select();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'pay_type'    => (int) $row->pay_type,
                'order_count' => (int) $row->order_count,
                'paid_amount' => AmountHelper::format((string) ($row->paid_amount ?: '0')),
            ];
        }

        return $items;
    }

    /**
     * 支付成功率（已支付笔数 / 下单笔数，百分比，保留 2 位）
     */
    protected function calcSuccessRate(int $orderCount, int $paidCount): float
    {
        if ($orderCount <= 0) {
            return 0.0;
        }

        $rate = AmountHelper::div((string) $paidCount, (string) $orderCount);
        $pct = AmountHelper::mul($rate, '100');

        return (float) AmountHelper::format($pct, 2);
    }

    /**
     * 环比变化百分比（今日 vs 昨日实收）；昨日为 0 时返回 null
     */
    protected function calcChangePercent(string $current, string $previous): ?float
    {
        if (!AmountHelper::gtZero($previous)) {
            return null;
        }

        $diff = AmountHelper::sub($current, $previous);
        $ratio = AmountHelper::div($diff, $previous);
        $pct = AmountHelper::mul($ratio, '100');

        return (float) AmountHelper::format($pct, 2);
    }
}
