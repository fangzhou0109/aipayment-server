<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户异步通知重试 —— saiadmin 定时任务类（type=3）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\crontab;

use plugin\paymentchannel\service\MerchantNotifyService;
use Throwable;

/**
 * 商户通知重试定时任务
 *
 * 由后台「工具 → 定时任务」调度（建议每 10 秒），扫描 sa_pay_notify_log 到期记录并重发。
 * 类名须在 sa_tool_crontab.target 中配置；方法固定为 run()。
 */
class NotifyRetryCrontab
{
    /** 与旧 NotifyRetryProcess 一致的单批上限 */
    public const DEFAULT_LIMIT = 100;

    /**
     * @param mixed $parameter JSON 字符串，可选 {"limit":100}
     * @return string 执行摘要（写入 sa_tool_crontab_log.exception_info）
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

            $count = (new MerchantNotifyService())->retryPending($limit);

            return "notify_retry processed={$count}, limit={$limit}";
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
