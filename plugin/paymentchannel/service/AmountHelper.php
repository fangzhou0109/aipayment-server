<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：金额精度工具（bcmath 封装）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use InvalidArgumentException;

/**
 * 金额精度工具
 *
 * 设计目标：四方支付全链路金额计算「零浮点误差」。
 *  - 存储与对外金额一律「元」，数据库用 decimal(16,4)，本类内部用 bcmath 字符串运算；
 *  - 统一保留 4 位小数（SCALE=4），与 decimal(16,4) 对齐；
 *  - 费率乘除等中间运算使用更高精度（CALC_SCALE）再四舍五入回 4 位，避免链式截断累计误差；
 *  - 严禁使用 PHP 浮点运算符（+ - * / round）参与金额计算。
 *
 * 约定：所有入参可为 int|float|string，但推荐传字符串（如 '100.00'）以彻底避免
 *      浮点字面量在到达本类之前就已失真的问题。
 */
class AmountHelper
{
    /**
     * 对外金额标准精度（小数位）——与数据库 decimal(16,4) 一致
     */
    public const SCALE = 4;

    /**
     * 中间计算精度（高于 SCALE，乘除时先用此精度再四舍五入回 SCALE）
     */
    private const CALC_SCALE = 8;

    /**
     * 归一化：将任意数值入参转为 bcmath 可用的纯数字字符串
     *
     * 之所以单独处理 float：PHP 默认 (string)$float 可能产生科学计数法或精度丢失，
     * 这里用高精度格式化为定点字符串，杜绝 bcmath 接收到非法格式。
     *
     * @param int|float|string $value 金额数值
     * @return string 归一化后的定点数字字符串
     * @throws InvalidArgumentException 当入参非数值时
     */
    private static function normalize(int|float|string $value): string
    {
        if (is_float($value)) {
            // 用足够高的精度把浮点转成定点字符串，避免科学计数法
            $value = number_format($value, self::CALC_SCALE, '.', '');
        } else {
            $value = trim((string) $value);
        }
        if ($value === '' || !is_numeric($value)) {
            throw new InvalidArgumentException('AmountHelper: 非法金额数值 [' . $value . ']');
        }
        return $value;
    }

    /**
     * 加法 a + b
     * @param int|float|string $a 加数
     * @param int|float|string $b 加数
     * @return string 结果（保留 SCALE 位小数）
     */
    public static function add(int|float|string $a, int|float|string $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /**
     * 减法 a - b
     * @param int|float|string $a 被减数
     * @param int|float|string $b 减数
     * @return string 结果（保留 SCALE 位小数）
     */
    public static function sub(int|float|string $a, int|float|string $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /**
     * 乘法 a * b（先用高精度计算，再四舍五入回 SCALE）
     * 典型用途：金额 × 数量。费率百分比请用 fee()。
     * @param int|float|string $a 乘数
     * @param int|float|string $b 乘数
     * @return string 结果（保留 SCALE 位小数）
     */
    public static function mul(int|float|string $a, int|float|string $b): string
    {
        $raw = bcmul(self::normalize($a), self::normalize($b), self::CALC_SCALE);
        return self::round($raw);
    }

    /**
     * 除法 a / b（先用高精度计算，再四舍五入回 SCALE）
     * @param int|float|string $a 被除数
     * @param int|float|string $b 除数
     * @return string 结果（保留 SCALE 位小数）
     * @throws InvalidArgumentException 当除数为 0 时
     */
    public static function div(int|float|string $a, int|float|string $b): string
    {
        $divisor = self::normalize($b);
        // bccomp 判定除数是否为 0，避免 bcdiv 返回 null 或告警
        if (bccomp($divisor, '0', self::CALC_SCALE) === 0) {
            throw new InvalidArgumentException('AmountHelper: 除数不能为 0');
        }
        $raw = bcdiv(self::normalize($a), $divisor, self::CALC_SCALE);
        return self::round($raw);
    }

    /**
     * 比较 a 与 b
     * @param int|float|string $a 左值
     * @param int|float|string $b 右值
     * @return int a>b 返回 1，a<b 返回 -1，相等返回 0
     */
    public static function compare(int|float|string $a, int|float|string $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /**
     * 按百分比费率计算手续费：amount × rate%
     * 例：fee('100', '2.6') => '2.6000'（2.6%）
     * @param int|float|string $amount 本金金额
     * @param int|float|string $ratePercent 费率（百分数值，如 2.6 表示 2.6%）
     * @return string 手续费（保留 SCALE 位小数）
     */
    public static function fee(int|float|string $amount, int|float|string $ratePercent): string
    {
        // amount * rate / 100，全程高精度，最后四舍五入
        $raw = bcdiv(
            bcmul(self::normalize($amount), self::normalize($ratePercent), self::CALC_SCALE),
            '100',
            self::CALC_SCALE
        );
        return self::round($raw);
    }

    /**
     * 格式化为固定小数位字符串（默认 SCALE 位），用于展示或落库前归一
     * @param int|float|string $value 金额
     * @param int $scale 小数位，默认 SCALE
     * @return string 定点字符串
     */
    public static function format(int|float|string $value, int $scale = self::SCALE): string
    {
        // bcadd 加 0 即可按指定精度截断/补零（配合下方 round 实现四舍五入）
        return self::round(self::normalize($value), $scale);
    }

    /**
     * 是否为正数（> 0）
     * @param int|float|string $value 金额
     * @return bool
     */
    public static function gtZero(int|float|string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    /**
     * 四舍五入到指定精度（bcmath 默认是截断，这里手动实现 round half up）
     *
     * 原理：正数加 0.5×10^-scale 再截断；负数减 0.5×10^-scale 再截断。
     * @param string $value 高精度数字字符串
     * @param int $scale 目标小数位，默认 SCALE
     * @return string 四舍五入后的定点字符串
     */
    private static function round(string $value, int $scale = self::SCALE): string
    {
        // 构造 0.00..5 修正值（scale 位）
        $delta = '0.' . str_repeat('0', $scale) . '5';
        if (str_starts_with($value, '-')) {
            // 负数：先减修正值再按 scale 截断
            return bcsub($value, $delta, $scale);
        }
        // 正数：先加修正值再按 scale 截断
        return bcadd($value, $delta, $scale);
    }
}
