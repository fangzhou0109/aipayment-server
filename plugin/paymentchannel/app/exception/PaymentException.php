<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：支付网关业务异常
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\exception;

use RuntimeException;

/**
 * 支付网关业务异常
 *
 * 用于下单/回调等网关流程中「可预期的业务拒绝」（验签失败、风控拦截、无可用通道、
 * 重复订单等）。控制器捕获后以统一响应体 `{code:400, message}` 返回（HTTP 仍 200，
 * 符合本系统约定）。与 saiadmin 的 ApiException 区分：网关不走后台异常处理（不返回 401 等）。
 */
class PaymentException extends RuntimeException
{
}
