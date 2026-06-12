-- +----------------------------------------------------------------------
-- | SaiPayment 四方支付 —— 定时任务种子（sa_tool_crontab）
-- +----------------------------------------------------------------------
-- | 由 saiadmin Task 进程加载；后台「工具 → 定时任务」可查看/改规则/看日志。
-- | ID 段 9101~9109，避免与 saiadmin 示例任务冲突。
-- | 导入后须 reload webman（或后台改任务触发 Channel 热更新）。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

DELETE FROM `sa_tool_crontab` WHERE `id` BETWEEN 9101 AND 9109;

-- 商户异步通知失败重试（原 pay-notify-retry 常驻进程，每 10 秒）
INSERT INTO `sa_tool_crontab` (
  `id`, `name`, `type`, `target`, `parameter`, `task_style`, `rule`,
  `singleton`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`
) VALUES (
  9101,
  '支付-商户通知重试',
  3,
  '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab',
  '{\"limit\":100}',
  5,
  '*/10 * * * * *',
  2,
  1,
  '扫描 sa_pay_notify_log 到期通知并重发；类任务 type=3',
  1,
  1,
  NOW(),
  NOW(),
  NULL
);

-- 代收订单超时自动关闭（原 pay-order-timeout 常驻进程，每 60 秒）
INSERT INTO `sa_tool_crontab` (
  `id`, `name`, `type`, `target`, `parameter`, `task_style`, `rule`,
  `singleton`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`
) VALUES (
  9102,
  '支付-订单超时关闭',
  3,
  '\\plugin\\paymentchannel\\app\\crontab\\OrderTimeoutCrontab',
  '{\"limit\":200}',
  5,
  '*/60 * * * * *',
  2,
  1,
  '关闭 expire_time 已过的待支付代收订单；类任务 type=3',
  1,
  1,
  NOW(),
  NOW(),
  NULL
);
