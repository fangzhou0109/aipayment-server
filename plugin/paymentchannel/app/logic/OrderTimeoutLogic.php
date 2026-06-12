<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代收订单超时关闭逻辑层（定时任务驱动）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\Order;
use plugin\saiadmin\basic\think\BaseLogic;

/**
 * 代收订单超时自动关闭逻辑（Phase 6.1 定时任务）
 *
 * 下单时已写入 `expire_time`（默认 30 分钟，见 {@see PayGatewayLogic::ORDER_TTL}）。
 * 本逻辑由 saiadmin 定时任务 {@see \plugin\paymentchannel\app\crontab\OrderTimeoutCrontab} 周期调用：
 * 扫描「仍待支付且已过期」的订单，置为「已关闭」，避免无效订单长期挂起、干扰对账。
 *
 * 设计要点：
 *  - **超时判定 {@see isTimeout} 为纯函数**（仅依据状态 + 过期时间 vs 当前时间），
 *    当前时间以参数注入，便于 mock 时间做确定性单测，不触发真实时钟/DB。
 *  - **关闭采用条件更新**（`where status=待支付`），与上游回调「置已支付」形成乐观并发，
 *    即便扫描与回调同时发生，也只会有一方成功，绝不把已支付订单误关闭。
 *  - DB 访问（查询过期单 / 关闭单）抽为 protected 接缝，单测以内存子类替代。
 */
class OrderTimeoutLogic extends BaseLogic
{
    /** 单批扫描默认上限，防止单次占用过久 */
    public const DEFAULT_LIMIT = 200;

    /**
     * 构造：绑定订单模型（与 OrderLogic 一致）
     */
    public function __construct()
    {
        $this->model = new Order();
    }

    /**
     * 判定单个订单是否「超时未支付」可关闭（纯函数，便于 mock 时间单测）
     *
     * 仅当「状态=待支付」且「过期时间存在且早于当前时间」时返回 true。
     * 无过期时间（NULL/空）保守处理为「不关闭」。
     *
     * @param array $order 订单行（需含 status、expire_time）
     * @param int   $now   当前时间戳（注入便于测试）
     * @return bool 是否应关闭
     */
    public function isTimeout(array $order, int $now): bool
    {
        // 仅「待支付」订单可被超时关闭；已支付/已失败/已关闭一律不动
        if ((int) ($order['status'] ?? -1) !== Order::STATUS_PENDING) {
            return false;
        }

        $expire = trim((string) ($order['expire_time'] ?? ''));
        if ($expire === '') {
            // 无过期时间则不关闭（保守，避免误伤历史/异常数据）
            return false;
        }

        $expireTs = strtotime($expire);
        if ($expireTs === false) {
            // 过期时间不可解析，保守不关闭
            return false;
        }

        // 严格早于当前时间才算过期（等于不关闭，留 1 秒余量）
        return $expireTs < $now;
    }

    /**
     * 扫描并关闭超时未支付订单
     *
     * @param int|null $now   当前时间戳（默认 time()，测试可注入固定值）
     * @param int      $limit 单批关闭上限
     * @return int 实际关闭的订单数量
     */
    public function closeTimeoutOrders(?int $now = null, int $limit = self::DEFAULT_LIMIT): int
    {
        $now = $now ?? time();
        $nowTime = date('Y-m-d H:i:s', $now);

        $orders = $this->loadExpiredPendingOrders($nowTime, $limit);

        $closed = 0;
        foreach ($orders as $order) {
            // 二次确认（防御查询条件与判定不一致），并以条件更新落地关闭
            if (!$this->isTimeout($order, $now)) {
                continue;
            }
            if ($this->closeOrder((int) ($order['id'] ?? 0))) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * 查询「待支付且已过期」的订单（接缝，测试以内存替代）
     *
     * 仅取 `expire_time < 当前时间` 的待支付单；NULL 过期时间因 `<` 比较自然被排除。
     *
     * @param string $nowTime 当前时间（Y-m-d H:i:s）
     * @param int    $limit   单批上限
     * @return array<int,array> 订单行数组
     */
    protected function loadExpiredPendingOrders(string $nowTime, int $limit): array
    {
        return Order::where('status', Order::STATUS_PENDING)
            ->where('expire_time', '<', $nowTime)
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 关闭单个订单（条件更新，接缝，测试以内存替代）
     *
     * 仅当订单「仍为待支付」时置为「已关闭」，与上游回调置「已支付」形成乐观并发，
     * 避免把刚被回调置为已支付的订单误关闭。
     *
     * @param int $id 订单ID
     * @return bool 是否成功关闭（受影响行数 > 0）
     */
    protected function closeOrder(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $affected = Order::where('id', $id)
            ->where('status', Order::STATUS_PENDING)
            ->update(['status' => Order::STATUS_CLOSED]);
        return $affected > 0;
    }
}
