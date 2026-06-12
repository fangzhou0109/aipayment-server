<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：对账服务（上游账单 vs 本地订单差异比对）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use Closure;
use plugin\paymentchannel\app\model\Order;

/**
 * 对账服务
 *
 * 把「上游账单」与「本地订单」逐笔比对，输出差异报表（金额差 / 状态差 / 单边账/漏单），
 * 供日终对账发现并定位资金账实不一致。**核心比对 {@see compare} 为纯函数**（仅依据传入的
 * 两组记录，金额比较走 {@see AmountHelper} 禁浮点），便于 mock 数据做确定性单测，不依赖 DB/网络。
 *
 * 差异分类（每对匹配单归入且仅归入一类，金额安全优先）：
 *  - consistent   ：已匹配 + 金额一致 + 支付状态一致（账实相符）。
 *  - amount_diff  ：已匹配但**金额不一致**（最严重，资金风险，优先于状态差）。
 *  - status_diff  ：已匹配、金额一致但**支付状态不一致**（如本地待支付而上游已支付＝漏回调，
 *                   或本地已支付而上游未支付＝可疑超付）。
 *  - local_only   ：本地有、上游账单无（单边账：本地多记）。
 *  - upstream_only：上游账单有、本地无（漏单：上游多记/本地丢单）。
 *
 * 驱动层 {@see reconcile} 通过可注入/可重写接缝拉取两侧数据后调用 compare，
 * 真实落地（连库 + 调上游）按需在服务器侧进行；纯比对核心本地全 mock 测试。
 */
class ReconcileService
{
    /** 差异类型：账实相符 */
    public const RESULT_CONSISTENT = 'consistent';
    /** 差异类型：金额不一致 */
    public const RESULT_AMOUNT_DIFF = 'amount_diff';
    /** 差异类型：支付状态不一致 */
    public const RESULT_STATUS_DIFF = 'status_diff';
    /** 差异类型：本地有上游无（单边账） */
    public const RESULT_LOCAL_ONLY = 'local_only';
    /** 差异类型：上游有本地无（漏单） */
    public const RESULT_UPSTREAM_ONLY = 'upstream_only';

    /**
     * 本地订单加载接缝：fn(array $criteria): array<int,array>
     * 默认 null（驱动层 reconcile 用），测试可注入或重写 {@see loadLocalOrders}。
     * @var Closure|null
     */
    private ?Closure $localLoader;

    /**
     * 上游账单加载接缝：fn(array $criteria): array<int,array>
     * 默认 null，测试可注入或重写 {@see loadUpstreamBill}。
     * @var Closure|null
     */
    private ?Closure $upstreamLoader;

    /**
     * @param Closure|null $localLoader    本地订单加载器（测试注入）
     * @param Closure|null $upstreamLoader 上游账单加载器（测试注入）
     */
    public function __construct(?Closure $localLoader = null, ?Closure $upstreamLoader = null)
    {
        $this->localLoader = $localLoader;
        $this->upstreamLoader = $upstreamLoader;
    }

    /**
     * 比对上游账单与本地订单，输出差异报表（纯函数核心）
     *
     * @param array<int,array> $localOrders     本地订单（每项含 order_no、amount、status）
     * @param array<int,array> $upstreamRecords 上游账单（每项含 order_no、amount，及 paid|outcome|status 之一）
     * @return array{
     *   summary: array<string,int>,
     *   consistent: array<int,array>,
     *   amount_diff: array<int,array>,
     *   status_diff: array<int,array>,
     *   local_only: array<int,array>,
     *   upstream_only: array<int,array>
     * } 差异报表
     */
    public function compare(array $localOrders, array $upstreamRecords): array
    {
        // 1) 归一化并按平台订单号建索引（无单号的记录无法对账，单独剔除不参与）
        $localMap = $this->indexByOrderNo($localOrders, fn($row) => $this->normalizeLocal($row));
        $upstreamMap = $this->indexByOrderNo($upstreamRecords, fn($row) => $this->normalizeUpstream($row));

        $report = [
            self::RESULT_CONSISTENT    => [],
            self::RESULT_AMOUNT_DIFF   => [],
            self::RESULT_STATUS_DIFF   => [],
            self::RESULT_LOCAL_ONLY    => [],
            self::RESULT_UPSTREAM_ONLY => [],
        ];

        // 2) 以本地为主遍历：匹配到上游 → 比金额→比状态；匹配不到 → 单边账（本地多记）
        foreach ($localMap as $orderNo => $local) {
            if (!isset($upstreamMap[$orderNo])) {
                $report[self::RESULT_LOCAL_ONLY][] = $this->buildItem(
                    self::RESULT_LOCAL_ONLY,
                    $orderNo,
                    $local,
                    null,
                    '本地订单在上游账单中不存在'
                );
                continue;
            }

            $upstream = $upstreamMap[$orderNo];
            // 标记已配对，便于第 3 步找出「上游有本地无」
            unset($upstreamMap[$orderNo]);

            // 金额安全优先：先比金额（禁浮点），不一致即金额差
            if (AmountHelper::compare($local['amount'], $upstream['amount']) !== 0) {
                $report[self::RESULT_AMOUNT_DIFF][] = $this->buildItem(
                    self::RESULT_AMOUNT_DIFF,
                    $orderNo,
                    $local,
                    $upstream,
                    sprintf('金额不一致：本地 %s，上游 %s', $local['amount'], $upstream['amount'])
                );
                continue;
            }

            // 金额一致再比支付状态
            if ($local['paid'] !== $upstream['paid']) {
                $report[self::RESULT_STATUS_DIFF][] = $this->buildItem(
                    self::RESULT_STATUS_DIFF,
                    $orderNo,
                    $local,
                    $upstream,
                    $local['paid']
                        ? '状态不一致：本地已支付，上游未支付（疑似超付/上游未到账）'
                        : '状态不一致：本地未支付，上游已支付（疑似漏回调，需补单）'
                );
                continue;
            }

            // 金额一致 + 状态一致 → 账实相符
            $report[self::RESULT_CONSISTENT][] = $this->buildItem(
                self::RESULT_CONSISTENT,
                $orderNo,
                $local,
                $upstream,
                '账实相符'
            );
        }

        // 3) 上游剩余未配对 → 漏单（上游有本地无）
        foreach ($upstreamMap as $orderNo => $upstream) {
            $report[self::RESULT_UPSTREAM_ONLY][] = $this->buildItem(
                self::RESULT_UPSTREAM_ONLY,
                $orderNo,
                null,
                $upstream,
                '上游账单存在但本地无此订单（疑似漏单）'
            );
        }

        // 4) 汇总计数
        $report['summary'] = [
            'total_local'    => count($localMap),
            'total_upstream' => count($localMap) - count($report[self::RESULT_LOCAL_ONLY]) + count($report[self::RESULT_UPSTREAM_ONLY]),
            'consistent'     => count($report[self::RESULT_CONSISTENT]),
            'amount_diff'    => count($report[self::RESULT_AMOUNT_DIFF]),
            'status_diff'    => count($report[self::RESULT_STATUS_DIFF]),
            'local_only'     => count($report[self::RESULT_LOCAL_ONLY]),
            'upstream_only'  => count($report[self::RESULT_UPSTREAM_ONLY]),
        ];
        // 差异总数（除一致外的所有异常项），便于上层「是否需人工介入」判定
        $report['summary']['diff_total'] = $report['summary']['amount_diff']
            + $report['summary']['status_diff']
            + $report['summary']['local_only']
            + $report['summary']['upstream_only'];

        return $report;
    }

    /**
     * 驱动层：加载两侧数据并比对（连库/调上游按需落地，纯比对核心见 compare）
     *
     * @param array $criteria 对账条件（如 channel_id、日期范围）
     * @return array 差异报表（同 compare 返回）
     */
    public function reconcile(array $criteria = []): array
    {
        $local = $this->loadLocalOrders($criteria);
        $upstream = $this->loadUpstreamBill($criteria);
        return $this->compare($local, $upstream);
    }

    /**
     * 加载本地订单（接缝：默认走注入闭包，测试可重写/注入）
     *
     * @param array $criteria 对账条件
     * @return array<int,array>
     */
    protected function loadLocalOrders(array $criteria): array
    {
        if ($this->localLoader !== null) {
            return ($this->localLoader)($criteria);
        }
        // 默认实现：按条件查本地订单（仅取对账所需字段）。真实落地在服务器侧验证。
        $query = Order::field('order_no,amount,status');
        if (!empty($criteria['channel_id'])) {
            $query->where('channel_id', (int) $criteria['channel_id']);
        }
        if (!empty($criteria['start_time'])) {
            $query->where('create_time', '>=', $criteria['start_time']);
        }
        if (!empty($criteria['end_time'])) {
            $query->where('create_time', '<', $criteria['end_time']);
        }
        return $query->select()->toArray();
    }

    /**
     * 加载上游账单（接缝：默认走注入闭包，测试可重写/注入）
     *
     * 上游账单来源因渠道而异（对账文件下载 / 账单查询接口），此处仅留接缝：
     * 注入闭包则用之；否则返回空（视为上游无账单，全部本地单将判为单边账）。
     *
     * @param array $criteria 对账条件
     * @return array<int,array>
     */
    protected function loadUpstreamBill(array $criteria): array
    {
        if ($this->upstreamLoader !== null) {
            return ($this->upstreamLoader)($criteria);
        }
        return [];
    }

    /**
     * 按 order_no 建索引（剔除无单号的记录，后写覆盖先写）
     *
     * @param array<int,array> $rows      原始记录
     * @param Closure          $normalize 归一化函数 fn(array $row): array
     * @return array<string,array> order_no => 归一化记录
     */
    private function indexByOrderNo(array $rows, Closure $normalize): array
    {
        $map = [];
        foreach ($rows as $row) {
            $norm = $normalize($row);
            $orderNo = $norm['order_no'];
            if ($orderNo === '') {
                // 无平台订单号无法对账，跳过
                continue;
            }
            $map[$orderNo] = $norm;
        }
        return $map;
    }

    /**
     * 归一化本地订单为对账记录
     *
     * @param array $row 本地订单行（order_no、amount、status）
     * @return array{order_no:string, amount:string, paid:bool, status:int}
     */
    private function normalizeLocal(array $row): array
    {
        $status = (int) ($row['status'] ?? Order::STATUS_PENDING);
        return [
            'order_no' => trim((string) ($row['order_no'] ?? '')),
            'amount'   => AmountHelper::format($row['amount'] ?? 0),
            // 本地以「已支付」状态视为成功（已入账等后续状态在本表仍为 status=1）
            'paid'     => $status === Order::STATUS_PAID,
            'status'   => $status,
        ];
    }

    /**
     * 归一化上游账单为对账记录
     *
     * 上游账单字段不统一，兼容多种「是否已支付」表达：
     *  - paid    ：布尔，最高优先；
     *  - outcome ：int，与 PaymentOutcome 对齐（1=已支付）；
     *  - status  ：int，与 sa_pay_order.status 对齐（1=已支付）。
     *
     * @param array $row 上游账单行（order_no、amount，及 paid|outcome|status 之一）
     * @return array{order_no:string, amount:string, paid:bool}
     */
    private function normalizeUpstream(array $row): array
    {
        if (array_key_exists('paid', $row)) {
            $paid = (bool) $row['paid'];
        } elseif (array_key_exists('outcome', $row)) {
            $paid = (int) $row['outcome'] === Order::STATUS_PAID;
        } else {
            $paid = (int) ($row['status'] ?? 0) === Order::STATUS_PAID;
        }

        return [
            'order_no' => trim((string) ($row['order_no'] ?? '')),
            'amount'   => AmountHelper::format($row['amount'] ?? 0),
            'paid'     => $paid,
        ];
    }

    /**
     * 组装单条差异报表项
     *
     * @param string     $type     差异类型
     * @param string     $orderNo  平台订单号
     * @param array|null $local    归一化本地记录（无则 null）
     * @param array|null $upstream 归一化上游记录（无则 null）
     * @param string     $detail   人类可读的差异说明
     * @return array
     */
    private function buildItem(string $type, string $orderNo, ?array $local, ?array $upstream, string $detail): array
    {
        return [
            'order_no'        => $orderNo,
            'type'            => $type,
            'local_amount'    => $local['amount'] ?? null,
            'upstream_amount' => $upstream['amount'] ?? null,
            'local_paid'      => $local['paid'] ?? null,
            'upstream_paid'   => $upstream['paid'] ?? null,
            'detail'          => $detail,
        ];
    }
}
