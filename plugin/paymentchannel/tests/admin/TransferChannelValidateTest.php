<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付通道验证器测试（Phase 9.5.2）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\admin;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\validate\TransferChannelValidate;

/**
 * TransferChannelValidate 规则与场景测试
 */
class TransferChannelValidateTest extends TestCase
{
    private function validator(): TransferChannelValidate
    {
        return new TransferChannelValidate();
    }

    private function saveRow(array $override = []): array
    {
        return array_merge([
            'title'              => '测试代付通道',
            'code'               => 'tf_mock_01',
            'transfer_adapter'   => 'mock_transfer',
            'rate_transfer_self' => '1.5000',
            'status'             => 1,
        ], $override);
    }

    /**
     * 纯代付建档：无 adapter / pay_type 时通过
     */
    public function testSaveWithoutPayFieldsPasses(): void
    {
        $v = $this->validator();
        $this->assertTrue($v->scene('save')->check($this->saveRow()));
    }

    /**
     * transfer_adapter 未注册时拒绝
     */
    public function testInvalidTransferAdapterRejected(): void
    {
        $v = $this->validator();
        $this->assertFalse($v->scene('save')->check($this->saveRow([
            'transfer_adapter' => 'not_in_registry',
        ])));
        $this->assertStringContainsString('代付适配器', $v->getError());
    }

    /**
     * rate_transfer_self 必填
     */
    public function testMissingRateTransferSelfRejected(): void
    {
        $v = $this->validator();
        $row = $this->saveRow();
        unset($row['rate_transfer_self']);
        $this->assertFalse($v->scene('save')->check($row));
        $this->assertStringContainsString('代付平台费率', $v->getError());
    }

    /**
     * update 场景不要求 code
     */
    public function testUpdateSceneWithoutCodePasses(): void
    {
        $v = $this->validator();
        $this->assertTrue($v->scene('update')->check([
            'title'              => '改名',
            'transfer_adapter'   => 'bank_transfer',
            'rate_transfer_self' => '2.0000',
            'status'             => 1,
        ]));
    }

    /**
     * 费率超出 0~100 拒绝
     */
    public function testRateTransferSelfOutOfRangeRejected(): void
    {
        $v = $this->validator();
        $this->assertFalse($v->scene('save')->check($this->saveRow([
            'rate_transfer_self' => '100.0001',
        ])));
    }
}
