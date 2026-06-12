-- +----------------------------------------------------------------------
-- | 支付插件定时任务迁入 sa_tool_crontab（替代 config/process.php 常驻进程）
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

DELETE FROM `sa_tool_crontab` WHERE `id` BETWEEN 9101 AND 9109;

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
  '扫描 sa_pay_notify_log 到期通知并重发',
  1,
  1,
  NOW(),
  NOW(),
  NULL
);

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
  '关闭 expire_time 已过的待支付代收订单',
  1,
  1,
  NOW(),
  NOW(),
  NULL
);
