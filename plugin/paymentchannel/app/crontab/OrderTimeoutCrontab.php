<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代收订单超时关闭 —— saiadmin 定时任务类（type=3）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\crontab;

use plugin\paymentchannel\app\logic\OrderTimeoutLogic;
use Throwable;

/**
 * 代收订单超时关闭定时任务
 *
 * 由后台「工具 → 定时任务」调度（建议每 60 秒），关闭 expire_time 已过的待支付订单。
 */
class OrderTimeoutCrontab
{
    /** 与旧 OrderTimeoutProcess 一致的单批上限 */
    public const DEFAULT_LIMIT = 200;

    /**
     * @param mixed $parameter JSON 字符串，可选 {"limit":200}
     * @return string 执行摘要
     */
    public function run($parameter = null): string
    {
        if (empty(env('DB_NAME'))) {
            return 'skip: DB_NAME 未配置';
        }

        try {
            $limit = self::DEFAULT_LIMIT;
            if ($parameter !== null && $parameter !== '') {
                $cfg = json_decode((string) $parameter, true);
                if (is_array($cfg) && isset($cfg['limit'])) {
                    $limit = max(1, (int) $cfg['limit']);
                }
            }

            $count = (new OrderTimeoutLogic())->closeTimeoutOrders(null, $limit);

            return "order_timeout closed={$count}, limit={$limit}";
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
