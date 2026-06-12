<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：支付结果枚举
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\dto;

/**
 * 支付结果枚举
 *
 * 适配器解析上游回调 / 查单后，统一用本枚举表达「这笔单当前的支付结果」，
 * 屏蔽各上游五花八门的 status/trade_status 取值，让上层（回调处理、查单）
 * 只关心三态：待支付 / 已支付 / 失败。
 *
 * 取值刻意与 sa_pay_order.status 对齐（0待支付/1已支付/2失败），便于回写订单。
 */
enum PaymentOutcome: int
{
    /** 待支付（上游尚未收到款，订单维持原状） */
    case Pending = 0;
    /** 已支付（可触发入账） */
    case Paid = 1;
    /** 失败（订单置失败） */
    case Failed = 2;

    /**
     * 是否为「已支付成功」终态
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this === self::Paid;
    }
}
