<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：AmountHelper 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\AmountHelper;

/**
 * AmountHelper 金额精度工具测试
 *
 * 核心目标：证明全链路金额计算无浮点误差，且四舍五入、费率、比较、异常等行为符合预期。
 */
class AmountHelperTest extends TestCase
{
    /**
     * 经典浮点陷阱：0.1 + 0.2 必须精确等于 0.3000（而非 0.30000000000000004）
     */
    public function testAddAvoidsFloatError(): void
    {
        $this->assertSame('0.3000', AmountHelper::add('0.1', '0.2'));
        $this->assertSame('0.3000', AmountHelper::add(0.1, 0.2));
    }

    /**
     * 加减法基础与负数
     */
    public function testAddSubWithNegative(): void
    {
        $this->assertSame('100.0000', AmountHelper::add('99.5', '0.5'));
        $this->assertSame('-1.5000', AmountHelper::sub('1', '2.5'));
        $this->assertSame('0.0000', AmountHelper::add('-5', '5'));
    }

    /**
     * 乘法：金额 × 数量，结果回到 4 位精度
     */
    public function testMul(): void
    {
        $this->assertSame('300.0000', AmountHelper::mul('100', '3'));
        $this->assertSame('1.0000', AmountHelper::mul('0.1', '10'));
    }

    /**
     * 除法正常场景
     */
    public function testDiv(): void
    {
        $this->assertSame('33.3333', AmountHelper::div('100', '3'));
        $this->assertSame('25.0000', AmountHelper::div('100', '4'));
    }

    /**
     * 除以 0 必须抛异常（防止资金计算被污染为 null/inf）
     */
    public function testDivByZeroThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AmountHelper::div('100', '0');
    }

    /**
     * 费率手续费：amount × rate%
     * 100 元 × 2.6% = 2.6000；333.33 × 3.8% = 12.6665（四舍五入）
     */
    public function testFee(): void
    {
        $this->assertSame('2.6000', AmountHelper::fee('100', '2.6'));
        // 333.33 * 3.8 / 100 = 12.66654 -> 四舍五入到 4 位 = 12.6665
        $this->assertSame('12.6665', AmountHelper::fee('333.33', '3.8'));
        // 0 费率
        $this->assertSame('0.0000', AmountHelper::fee('100', '0'));
    }

    /**
     * 四舍五入边界（half up / 远离零）
     */
    public function testRoundingHalfUp(): void
    {
        // 第 5 位为 5，正数进位
        $this->assertSame('1.2345', AmountHelper::format('1.23445'));
        // 第 5 位为 4，舍去
        $this->assertSame('1.2344', AmountHelper::format('1.23444'));
        // 负数远离零
        $this->assertSame('-1.2345', AmountHelper::format('-1.23445'));
    }

    /**
     * format 指定精度
     */
    public function testFormatScale(): void
    {
        $this->assertSame('100.00', AmountHelper::format('100', 2));
        $this->assertSame('100', AmountHelper::format('100.4', 0));
        $this->assertSame('101', AmountHelper::format('100.5', 0));
    }

    /**
     * 比较与正数判定
     */
    public function testCompareAndGtZero(): void
    {
        $this->assertSame(1, AmountHelper::compare('100.0001', '100'));
        $this->assertSame(-1, AmountHelper::compare('99.9999', '100'));
        $this->assertSame(0, AmountHelper::compare('100.00000', '100'));
        $this->assertTrue(AmountHelper::gtZero('0.0001'));
        $this->assertFalse(AmountHelper::gtZero('0'));
        $this->assertFalse(AmountHelper::gtZero('-1'));
    }

    /**
     * 大额精度：decimal(16,4) 量级（万亿级）仍精确
     */
    public function testLargeAmountPrecision(): void
    {
        $this->assertSame('999999999999.9999', AmountHelper::add('999999999999.9998', '0.0001'));
    }

    /**
     * 非法入参抛异常
     */
    public function testInvalidInputThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AmountHelper::add('abc', '1');
    }
}
