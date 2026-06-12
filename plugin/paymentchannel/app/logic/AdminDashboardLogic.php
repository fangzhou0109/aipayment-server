<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：平台后台工作台统计逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\NotifyLog;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\app\model\Recharge;
use plugin\paymentchannel\app\model\Withdraw;
use plugin\paymentchannel\service\AmountHelper;

/**
 * 平台后台工作台统计逻辑层
 *
 * 聚合全平台商户、代收订单、待办审核与通道概况（只读）。
 * 金额统计统一走 {@see AmountHelper}（bcmath），禁止浮点累加。
 *
 * 可测试性：DB 访问抽为 protected 接缝，单测以子类内存替代。
 */
class AdminDashboardLogic
{
    /** 首页最近订单条数 */
    private const RECENT_ORDER_LIMIT = 10;

    /** 今日商户实收排行条数 */
    private const TOP_MERCHANT_LIMIT = 5;

    /** 首页待审简表条数 */
    private const RECENT_PENDING_LIMIT = 5;

    /** 商户状态：正常 */
    private const MERCHANT_STATUS_ACTIVE = 1;

    /** 通道状态：启用 */
    private const CHANNEL_STATUS_ACTIVE = 1;

    /**
     * 汇总平台工作台统计
     *
     * @return array 经营概览、资金池、待办、趋势与最近订单
     */
    public function stats(): array
    {
        $merchantStats = $this->merchantPoolStats();
        $today = $this->todayOrderStats();
        $yesterday = $this->yesterdayOrderStats();
        $month = $this->monthOrderStats();

        $todayPaid = (string) ($today['paid_amount'] ?? '0');
        $yesterdayPaid = (string) ($yesterday['paid_amount'] ?? '0');
        $todayFee = (string) ($today['fee_amount'] ?? '0');
        $yesterdayFee = (string) ($yesterday['fee_amount'] ?? '0');

        return [
            // 统计时间戳（前端展示「数据截至」）
            'stats_time'               => date('Y-m-d H:i:s'),
            // 商户与资金池
            'merchant_count'           => (int) ($merchantStats['total'] ?? 0),
            'merchant_active_count'    => (int) ($merchantStats['active'] ?? 0),
            'balance_total'            => AmountHelper::format((string) ($merchantStats['balance'] ?? '0')),
            'balance_freeze_total'     => AmountHelper::format((string) ($merchantStats['balance_freeze'] ?? '0')),
            'balance_pool_total'       => AmountHelper::format(
                AmountHelper::add(
                    (string) ($merchantStats['balance'] ?? '0'),
                    (string) ($merchantStats['balance_freeze'] ?? '0')
                )
            ),
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
            'fee_amount_change_pct'    => $this->calcChangePercent($todayFee, $yesterdayFee),
            // 本月累计
            'month_order_count'        => (int) ($month['count'] ?? 0),
            'month_order_amount'       => AmountHelper::format((string) ($month['amount'] ?? '0')),
            'month_paid_count'         => (int) ($month['paid_count'] ?? 0),
            'month_paid_amount'        => AmountHelper::format((string) ($month['paid_amount'] ?? '0')),
            'month_fee_amount'         => AmountHelper::format((string) ($month['fee_amount'] ?? '0')),
            // 待办
            'pending_withdraw_count'   => $this->pendingWithdrawCount(),
            'pending_withdraw_amount'  => $this->pendingWithdrawAmount(),
            'pending_recharge_count'   => $this->pendingRechargeCount(),
            'pending_recharge_amount'  => $this->pendingRechargeAmount(),
            'notify_pending_count'     => $this->notifyPendingCount(),
            'notify_failed_count'      => $this->notifyFailedCount(),
            // 通道概况
            'pay_channel_active_count' => $this->activePayChannelCount(),
            'transfer_channel_active_count' => $this->activeTransferChannelCount(),
            // 图表与列表
            'trend_7d'                 => $this->orderTrend7d(),
            'pay_type_dist_today'      => $this->payTypeDistToday(),
            'top_merchants_today'      => $this->topMerchantsToday(self::TOP_MERCHANT_LIMIT),
            'recent_orders'            => $this->recentOrders(self::RECENT_ORDER_LIMIT),
            'recent_pending_withdraws' => $this->recentPendingWithdraws(self::RECENT_PENDING_LIMIT),
            'recent_pending_recharges' => $this->recentPendingRecharges(self::RECENT_PENDING_LIMIT),
        ];
    }

    // ===== 接缝：DB 访问，单测可在子类重写 =====

    /**
     * 商户数量与资金池汇总
     *
     * @return array{total:int, active:int, balance:string, balance_freeze:string}
     */
    protected function merchantPoolStats(): array
    {
        $total = (int) Merchant::count();
        $active = (int) Merchant::where('status', self::MERCHANT_STATUS_ACTIVE)->count();
        $balance = (string) (Merchant::sum('balance') ?: '0');
        $freeze = (string) (Merchant::sum('balance_freeze') ?: '0');

        return [
            'total'          => $total,
            'active'         => $active,
            'balance'        => $balance,
            'balance_freeze' => $freeze,
        ];
    }

    /**
     * 统计今日全平台订单
     */
    protected function todayOrderStats(): array
    {
        return $this->periodOrderStats(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
    }

    /**
     * 统计昨日全平台订单
     */
    protected function yesterdayOrderStats(): array
    {
        $day = date('Y-m-d', strtotime('-1 day'));

        return $this->periodOrderStats($day . ' 00:00:00', $day . ' 23:59:59');
    }

    /**
     * 统计本月全平台订单（自然月）
     */
    protected function monthOrderStats(): array
    {
        return $this->periodOrderStats(date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59'));
    }

    /**
     * 按时间窗统计全平台订单
     *
     * @return array{count:int, amount:string, paid_count:int, paid_amount:string, fee_amount:string, pending_count:int, failed_count:int}
     */
    protected function periodOrderStats(string $start, string $end): array
    {
        $base = Order::where('create_time', 'between', [$start, $end]);

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
     * 近 7 日全平台代收趋势（含今日）
     *
     * @return list<array{date:string, label:string, order_count:int, order_amount:string, paid_count:int, paid_amount:string, fee_amount:string}>
     */
    protected function orderTrend7d(): array
    {
        $items = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $stats = $this->periodOrderStats($day . ' 00:00:00', $day . ' 23:59:59');
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
     * 最近代收订单（首页简表，含商户展示字段）
     *
     * @return list<array<string, mixed>>
     */
    protected function recentOrders(int $limit): array
    {
        $rows = Order::with(['merchant' => function ($query) {
            $query->field('id,mch_id,name');
        }])
            ->field('id,order_no,out_trade_no,mch_id,merchant_id,amount,real_amount,fee,status,pay_type,create_time,pay_time')
            ->order('id', 'desc')
            ->limit(max(1, min(20, $limit)))
            ->select();

        $items = [];
        foreach ($rows as $row) {
            $merchant = $row->merchant;
            $items[] = [
                'id'            => (int) $row->id,
                'order_no'      => (string) $row->order_no,
                'out_trade_no'  => (string) $row->out_trade_no,
                'mch_id'        => (string) ($row->mch_id ?: ($merchant ? $merchant->mch_id : '')),
                'merchant_name' => (string) ($merchant ? $merchant->name : ''),
                'amount'        => AmountHelper::format((string) $row->amount),
                'real_amount'   => AmountHelper::format((string) $row->real_amount),
                'fee'           => AmountHelper::format((string) $row->fee),
                'status'        => (int) $row->status,
                'pay_type'      => (int) $row->pay_type,
                'create_time'   => (string) $row->create_time,
                'pay_time'      => (string) ($row->pay_time ?? ''),
            ];
        }

        return $items;
    }

    /**
     * 全平台待审核提现笔数
     */
    protected function pendingWithdrawCount(): int
    {
        return (int) Withdraw::where('status', Withdraw::STATUS_PENDING)->count();
    }

    /**
     * 全平台待审核提现金额合计
     */
    protected function pendingWithdrawAmount(): string
    {
        $sum = (string) (Withdraw::where('status', Withdraw::STATUS_PENDING)->sum('amount') ?: '0');

        return AmountHelper::format($sum);
    }

    /**
     * 全平台待审核充值笔数
     */
    protected function pendingRechargeCount(): int
    {
        return (int) Recharge::where('status', Recharge::STATUS_PENDING)->count();
    }

    /**
     * 全平台待审核充值金额合计
     */
    protected function pendingRechargeAmount(): string
    {
        $sum = (string) (Recharge::where('status', Recharge::STATUS_PENDING)->sum('amount') ?: '0');

        return AmountHelper::format($sum);
    }

    /**
     * 今日已支付订单按支付类型分布（平台看支付结构）
     *
     * @return list<array{pay_type:int, order_count:int, paid_amount:string}>
     */
    protected function payTypeDistToday(): array
    {
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');

        $rows = Order::where('create_time', 'between', [$start, $end])
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
     * 今日商户实收 TOP N（平台关注头部商户贡献）
     *
     * @return list<array{merchant_id:int, mch_id:string, merchant_name:string, paid_count:int, paid_amount:string, fee_amount:string}>
     */
    protected function topMerchantsToday(int $limit): array
    {
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');

        $rows = Order::where('create_time', 'between', [$start, $end])
            ->where('status', Order::STATUS_PAID)
            ->field('merchant_id, MAX(mch_id) as mch_id, COUNT(*) as paid_count, SUM(real_amount) as paid_amount, SUM(fee) as fee_amount')
            ->group('merchant_id')
            ->order('paid_amount', 'desc')
            ->limit(max(1, min(10, $limit)))
            ->select();

        if ($rows->isEmpty()) {
            return [];
        }

        $merchantIds = [];
        foreach ($rows as $row) {
            $merchantIds[] = (int) $row->merchant_id;
        }

        $merchantMap = [];
        $merchants = Merchant::whereIn('id', array_unique($merchantIds))->field('id,mch_id,name')->select();
        foreach ($merchants as $merchant) {
            $merchantMap[(int) $merchant->id] = $merchant;
        }

        $items = [];
        foreach ($rows as $row) {
            $merchantId = (int) $row->merchant_id;
            $merchant = $merchantMap[$merchantId] ?? null;
            $items[] = [
                'merchant_id'   => $merchantId,
                'mch_id'        => (string) ($row->mch_id ?: ($merchant ? $merchant->mch_id : '')),
                'merchant_name' => (string) ($merchant ? $merchant->name : ''),
                'paid_count'    => (int) $row->paid_count,
                'paid_amount'   => AmountHelper::format((string) ($row->paid_amount ?: '0')),
                'fee_amount'    => AmountHelper::format((string) ($row->fee_amount ?: '0')),
            ];
        }

        return $items;
    }

    /**
     * 最近待审核提现（平台运营快捷处理）
     *
     * @return list<array<string, mixed>>
     */
    protected function recentPendingWithdraws(int $limit): array
    {
        $rows = Withdraw::with(['merchant' => function ($query) {
            $query->field('id,mch_id,name');
        }])
            ->where('status', Withdraw::STATUS_PENDING)
            ->field('id,withdraw_no,merchant_id,amount,create_time')
            ->order('id', 'desc')
            ->limit(max(1, min(10, $limit)))
            ->select();

        $items = [];
        foreach ($rows as $row) {
            $merchant = $row->merchant;
            $items[] = [
                'id'            => (int) $row->id,
                'withdraw_no'   => (string) $row->withdraw_no,
                'mch_id'        => (string) ($merchant ? $merchant->mch_id : ''),
                'merchant_name' => (string) ($merchant ? $merchant->name : ''),
                'amount'        => AmountHelper::format((string) $row->amount),
                'create_time'   => (string) $row->create_time,
            ];
        }

        return $items;
    }

    /**
     * 最近待审核充值
     *
     * @return list<array<string, mixed>>
     */
    protected function recentPendingRecharges(int $limit): array
    {
        $rows = Recharge::with(['merchant' => function ($query) {
            $query->field('id,mch_id,name');
        }])
            ->where('status', Recharge::STATUS_PENDING)
            ->field('id,recharge_no,merchant_id,amount,create_time')
            ->order('id', 'desc')
            ->limit(max(1, min(10, $limit)))
            ->select();

        $items = [];
        foreach ($rows as $row) {
            $merchant = $row->merchant;
            $items[] = [
                'id'            => (int) $row->id,
                'recharge_no'   => (string) $row->recharge_no,
                'mch_id'        => (string) ($merchant ? $merchant->mch_id : ''),
                'merchant_name' => (string) ($merchant ? $merchant->name : ''),
                'amount'        => AmountHelper::format((string) $row->amount),
                'create_time'   => (string) $row->create_time,
            ];
        }

        return $items;
    }

    /**
     * 待通知笔数（需关注或人工重发）
     */
    protected function notifyPendingCount(): int
    {
        return (int) NotifyLog::where('status', NotifyLog::STATUS_PENDING)->count();
    }

    /**
     * 通知失败笔数
     */
    protected function notifyFailedCount(): int
    {
        return (int) NotifyLog::where('status', NotifyLog::STATUS_FAILED)->count();
    }

    /**
     * 启用中的代收通道数（含双能力）
     */
    protected function activePayChannelCount(): int
    {
        return (int) Channel::where('status', self::CHANNEL_STATUS_ACTIVE)
            ->whereIn('channel_biz', [Channel::BIZ_PAY_ONLY, Channel::BIZ_BOTH])
            ->count();
    }

    /**
     * 启用中的代付通道数（含双能力）
     */
    protected function activeTransferChannelCount(): int
    {
        return (int) Channel::where('status', self::CHANNEL_STATUS_ACTIVE)
            ->whereIn('channel_biz', [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH])
            ->count();
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
