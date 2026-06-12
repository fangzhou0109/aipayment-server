<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-通道授权验证器测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\admin;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\validate\MerchantChannelValidate;

/**
 * 可测试验证器：内存注入通道上游成本
 */
class TestableMerchantChannelValidate extends MerchantChannelValidate
{
    /** channelId => ['rate' => string] */
    public array $channels = [];

    /** bindingId => channel_id */
    public array $bindingChannelMap = [];

    protected function loadChannelForRateCheck(int $channelId): ?array
    {
        return $this->channels[$channelId] ?? null;
    }

    protected function loadBindingChannelId(int $bindingId): int
    {
        return (int) ($this->bindingChannelMap[$bindingId] ?? 0);
    }
}

/**
 * MerchantChannelValidate 费率利润校验测试
 */
class MerchantChannelValidateTest extends TestCase
{
    private function validator(array $channels = []): TestableMerchantChannelValidate
    {
        $v = new TestableMerchantChannelValidate();
        $v->channels = $channels;
        return $v;
    }

    private function baseRow(array $override = []): array
    {
        return array_merge([
            'merchant_id' => 1,
            'channel_id'  => 10,
            'rate'        => '0',
            'day_limit'   => '0',
            'single_min'  => '0',
            'single_max'  => '0',
            'status'      => 1,
        ], $override);
    }

    /**
     * rate=0 允许（继承通道 rate_self）
     */
    public function testRateZeroAllowed(): void
    {
        $v = $this->validator([10 => ['rate' => '2.5000']]);
        $this->assertTrue($v->scene('save')->check($this->baseRow(['rate' => '0'])));
    }

    /**
     * rate>0 且大于上游成本时通过
     */
    public function testRateAboveUpstreamPasses(): void
    {
        $v = $this->validator([10 => ['rate' => '2.0000']]);
        $this->assertTrue($v->scene('save')->check($this->baseRow(['rate' => '2.6000'])));
    }

    /**
     * rate 等于上游成本时拒绝
     */
    public function testRateEqualUpstreamRejected(): void
    {
        $v = $this->validator([10 => ['rate' => '2.6000']]);
        $this->assertFalse($v->scene('save')->check($this->baseRow(['rate' => '2.6000'])));
        $this->assertStringContainsString('上游成本', $v->getError());
    }

    /**
     * rate 低于上游成本时拒绝
     */
    public function testRateBelowUpstreamRejected(): void
    {
        $v = $this->validator([10 => ['rate' => '2.5000']]);
        $this->assertFalse($v->scene('save')->check($this->baseRow(['rate' => '2.0000'])));
    }

    /**
     * 通道不存在时拒绝
     */
    public function testChannelMissingRejected(): void
    {
        $v = $this->validator([]);
        $this->assertFalse($v->scene('save')->check($this->baseRow(['rate' => '3.0000'])));
        $this->assertStringContainsString('通道不存在', $v->getError());
    }

    /**
     * 小数精度：2.6001 > 2.6000 通过
     */
    public function testDecimalPrecisionPasses(): void
    {
        $v = $this->validator([10 => ['rate' => '2.6000']]);
        $this->assertTrue($v->scene('save')->check($this->baseRow(['rate' => '2.6001'])));
    }

    /**
     * update 场景无 channel_id 时按 id 反查绑定通道
     */
    public function testUpdateSceneResolvesChannelByBindingId(): void
    {
        $v = $this->validator([10 => ['rate' => '2.0000']]);
        $v->bindingChannelMap[88] = 10;

        $this->assertTrue($v->scene('update')->check([
            'id'     => 88,
            'rate'   => '2.5000',
            'status' => 1,
        ]));
    }

    /**
     * single_min/max 均为 0 表示不限，允许
     */
    public function testSingleLimitZeroAllowed(): void
    {
        $v = $this->validator([10 => ['rate' => '2.0000']]);
        $this->assertTrue($v->scene('save')->check($this->baseRow([
            'single_min' => '0',
            'single_max' => '0',
        ])));
    }

    /**
     * min < max 时通过
     */
    public function testSingleLimitRangeValid(): void
    {
        $v = $this->validator([10 => ['rate' => '2.0000']]);
        $this->assertTrue($v->scene('save')->check($this->baseRow([
            'single_min' => '10.0000',
            'single_max' => '5000.0000',
        ])));
    }

    /**
     * max < min 时拒绝
     */
    public function testSingleLimitMaxLessThanMinRejected(): void
    {
        $v = $this->validator([10 => ['rate' => '2.0000']]);
        $this->assertFalse($v->scene('save')->check($this->baseRow([
            'single_min' => '100.0000',
            'single_max' => '50.0000',
        ])));
        $this->assertStringContainsString('最大限额', $v->getError());
    }

    /**
     * 单笔限额不能为负
     */
    public function testSingleLimitNegativeRejected(): void
    {
        $v = $this->validator([10 => ['rate' => '2.0000']]);
        $this->assertFalse($v->scene('save')->check($this->baseRow([
            'single_min' => '-1',
        ])));
    }
}
