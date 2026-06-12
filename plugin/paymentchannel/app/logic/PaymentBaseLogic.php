<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：支付插件逻辑层基类（列表默认排序）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\saiadmin\basic\think\BaseLogic;

/**
 * 支付插件逻辑层基类
 *
 * 平台后台与商户门户的列表/导出接口默认按 create_time 倒序（最新记录在前）。
 * 前端表格仍可通过请求参数 orderField / orderType 覆盖默认排序。
 */
class PaymentBaseLogic extends BaseLogic
{
    /** @var string 默认排序字段 */
    protected string $orderField = 'create_time';

    /** @var string 默认排序方向 */
    protected string $orderType = 'DESC';
}
