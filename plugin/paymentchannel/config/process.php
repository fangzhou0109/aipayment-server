<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：自定义常驻进程（本插件无独立 Workerman 定时进程）
// +----------------------------------------------------------------------

// 支付插件定时任务统一走 saiadmin「工具 → 定时任务」（sa_tool_crontab + plugin\saiadmin\process\Task）。
// 种子见 db/crontab.sql / migrations/20260609_payment_crontab.sql：
//   - NotifyRetryCrontab   每 10s  商户通知重试
//   - OrderTimeoutCrontab  每 60s  代收订单超时关闭
return [];
