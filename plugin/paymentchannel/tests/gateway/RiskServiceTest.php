<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：下单风控服务测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\gateway;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\service\RiskService;

/**
 * 下单风控测试（纯逻辑，不依赖 DB）
 */
class RiskServiceTest extends TestCase
{
    /**
     * 商户停用 → 拒绝
     */
    public function testMerchantDisabledRejected(): void
    {
        $this->expectException(PaymentException::class);
        RiskService::assertMerchantActive(['status' => 2]);
    }

    /**
     * 商户正常 → 通过（无异常）
     */
    public function testMerchantActivePassed(): void
    {
        RiskService::assertMerchantActive(['status' => 1]);
        $this->assertTrue(true);
    }

    /**
     * 金额非正 → 拒绝
     */
    public function testAmountNotPositiveRejected(): void
    {
        $this->expectException(PaymentException::class);
        RiskService::assertAmountPositive('0');
    }

    /**
     * 单笔限额：低于下限 / 超过上限分别拒绝；区间内放行；0 表示不限
     */
    public function testSingleLimit(): void
    {
        // 区间 [10,1000] 内放行
        RiskService::assertSingleLimit('100.0000', '10', '1000');
        $this->assertTrue(true);

        // 低于下限
        try {
            RiskService::assertSingleLimit('5.0000', '10', '1000');
            $this->fail('应抛出低于下限异常');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('最小', $e->getMessage());
        }

        // 超过上限
        try {
            RiskService::assertSingleLimit('2000.0000', '10', '1000');
            $this->fail('应抛出超过上限异常');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('最大', $e->getMessage());
        }

        // min/max 为 0 → 不限制
        RiskService::assertSingleLimit('999999.0000', '0', '0');
        $this->assertTrue(true);
    }

    /**
     * 综合校验：正常商户 + 合法金额一次通过
     */
    public function testCheckSubmitOrderPassed(): void
    {
        RiskService::checkSubmitOrder(
            ['status' => 1, 'single_min' => '1', 'single_max' => '5000'],
            '100.0000'
        );
        $this->assertTrue(true);
    }

    /**
     * 综合校验：超限即拒
     */
    public function testCheckSubmitOrderOverLimit(): void
    {
        $this->expectException(PaymentException::class);
        RiskService::checkSubmitOrder(
            ['status' => 1, 'single_min' => '1', 'single_max' => '50'],
            '100.0000'
        );
    }

    /**
     * 通道限额：区间内放行
     */
    public function testCheckChannelLimitWithinRange(): void
    {
        RiskService::checkChannelLimit('100.0000', [
            'single_min' => '10.0000',
            'single_max' => '5000.0000',
        ]);
        $this->assertTrue(true);
    }

    /**
     * 通道限额：低于通道最小拒单
     */
    public function testCheckChannelLimitBelowMin(): void
    {
        try {
            RiskService::checkChannelLimit('5.0000', [
                'single_min' => '10.0000',
                'single_max' => '5000.0000',
            ]);
            $this->fail('应抛出通道最小限额异常');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('通道单笔最小', $e->getMessage());
        }
    }

    /**
     * 通道限额：超过通道最大拒单
     */
    public function testCheckChannelLimitAboveMax(): void
    {
        try {
            RiskService::checkChannelLimit('6000.0000', [
                'single_min' => '10.0000',
                'single_max' => '5000.0000',
            ]);
            $this->fail('应抛出通道最大限额异常');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('通道单笔最大', $e->getMessage());
        }
    }

    /**
     * 通道限额：0 表示不限
     */
    public function testCheckChannelLimitZeroUnlimited(): void
    {
        RiskService::checkChannelLimit('999999.0000', [
            'single_min' => '0',
            'single_max' => '0',
        ]);
        $this->assertTrue(true);
    }

    /**
     * 分层限额：全局通过、通道拒绝（模拟选路后二次校验）
     */
    public function testLayeredGlobalPassChannelReject(): void
    {
        RiskService::checkSubmitOrder(
            ['status' => 1, 'single_min' => '0', 'single_max' => '10000'],
            '100.0000'
        );

        $this->expectException(PaymentException::class);
        RiskService::checkChannelLimit('100.0000', [
            'single_min' => '0',
            'single_max' => '50',
        ]);
    }

    /**
     * 分层限额：全局兜底先于选路拒绝（通道配置再宽也不放行）
     */
    public function testLayeredGlobalRejectBeforeChannel(): void
    {
        $this->expectException(PaymentException::class);
        RiskService::checkSubmitOrder(
            ['status' => 1, 'single_min' => '0', 'single_max' => '50'],
            '100.0000'
        );
    }
}
