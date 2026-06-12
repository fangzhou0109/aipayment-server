<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：下单风控服务
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use plugin\paymentchannel\app\exception\PaymentException;

/**
 * 下单风控服务
 *
 * 收敛下单前的「准入校验」：商户状态、金额合法性、分层单笔限额。
 * Phase 9.2.2：商户全局 single_min/max（checkSubmitOrder）+ 命中通道后商户×通道限额（checkChannelLimit）。
 * 全部为纯静态方法、不依赖 DB/请求，便于充分单测；校验不通过抛 {@see PaymentException}。
 *
 * 金额比较一律走 {@see AmountHelper}（decimal+bcmath），禁止浮点。
 */
class RiskService
{
    /** 商户状态：正常（与 sa_pay_merchant.status 约定一致） */
    private const MERCHANT_STATUS_NORMAL = 1;

    /**
     * 校验商户处于可交易状态
     *
     * @param array $merchant 商户数据（须含 status）
     * @throws PaymentException 商户停用时
     */
    public static function assertMerchantActive(array $merchant): void
    {
        if ((int) ($merchant['status'] ?? 0) !== self::MERCHANT_STATUS_NORMAL) {
            throw new PaymentException('商户已停用');
        }
    }

    /**
     * 校验订单金额为正
     *
     * @param string $amount 订单金额（元）
     * @throws PaymentException 金额非正时
     */
    public static function assertAmountPositive(string $amount): void
    {
        if (!AmountHelper::gtZero($amount)) {
            throw new PaymentException('订单金额必须大于 0');
        }
    }

    /**
     * 校验单笔限额（min/max 为 0 表示不限）
     *
     * @param string $amount 订单金额（元）
     * @param string $singleMin 单笔最小金额（元，0=不限）
     * @param string $singleMax 单笔最大金额（元，0=不限）
     * @throws PaymentException 低于下限或超过上限时
     */
    public static function assertSingleLimit(string $amount, string $singleMin, string $singleMax): void
    {
        // 下限：min>0 且 amount<min → 拒绝
        if (AmountHelper::gtZero($singleMin) && AmountHelper::compare($amount, $singleMin) < 0) {
            throw new PaymentException('订单金额低于单笔最小限额');
        }
        // 上限：max>0 且 amount>max → 拒绝
        if (AmountHelper::gtZero($singleMax) && AmountHelper::compare($amount, $singleMax) > 0) {
            throw new PaymentException('订单金额超过单笔最大限额');
        }
    }

    /**
     * 校验商户×通道单笔限额（命中通道后调用；min/max 为 0 表示不限）
     *
     * @param string $amount 订单金额（元）
     * @param array $merchantChannelRow merchant_channel 行（须含 single_min/single_max）
     * @throws PaymentException 低于通道下限或超过通道上限时
     */
    public static function checkChannelLimit(string $amount, array $merchantChannelRow): void
    {
        self::assertChannelSingleLimit(
            $amount,
            (string) ($merchantChannelRow['single_min'] ?? '0'),
            (string) ($merchantChannelRow['single_max'] ?? '0'),
        );
    }

    /**
     * 通道级单笔限额断言（文案与商户全局区分，便于运营排障）
     *
     * @param string $amount 订单金额（元）
     * @param string $singleMin 通道单笔最小（0=不限）
     * @param string $singleMax 通道单笔最大（0=不限）
     * @throws PaymentException
     */
    public static function assertChannelSingleLimit(string $amount, string $singleMin, string $singleMax): void
    {
        if (AmountHelper::gtZero($singleMin) && AmountHelper::compare($amount, $singleMin) < 0) {
            throw new PaymentException('订单金额低于通道单笔最小限额');
        }
        if (AmountHelper::gtZero($singleMax) && AmountHelper::compare($amount, $singleMax) > 0) {
            throw new PaymentException('订单金额超过通道单笔最大限额');
        }
    }

    /**
     * 下单准入综合校验（商户状态 + 金额为正 + 商户全局单笔限额兜底）
     *
     * @param array $merchant 商户数据（status/single_min/single_max）
     * @param string $amount 订单金额（元）
     * @throws PaymentException 任一校验不通过
     */
    public static function checkSubmitOrder(array $merchant, string $amount): void
    {
        self::assertMerchantActive($merchant);
        self::assertAmountPositive($amount);
        self::assertSingleLimit(
            $amount,
            (string) ($merchant['single_min'] ?? '0'),
            (string) ($merchant['single_max'] ?? '0'),
        );
    }
}
