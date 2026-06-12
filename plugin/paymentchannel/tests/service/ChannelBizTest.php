<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：ChannelBizResolver / ChannelLogic channel_biz 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\ChannelLogic;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\service\ChannelBizResolver;

/**
 * Phase 9.5.1：channel_biz 计算、能力判定与保存归一化
 */
class ChannelBizTest extends TestCase
{
    private ChannelBizResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ChannelBizResolver();
    }

    /**
     * 仅合法代收 adapter → BIZ_PAY_ONLY
     */
    public function testResolvePayOnly(): void
    {
        $biz = $this->resolver->resolve([
            'adapter'          => 'mock',
            'transfer_adapter' => '',
        ]);

        $this->assertSame(Channel::BIZ_PAY_ONLY, $biz);
        $this->assertTrue($this->resolver->isPayCapable(['adapter' => 'mock']));
        $this->assertFalse($this->resolver->isTransferCapable(['transfer_adapter' => '']));
    }

    /**
     * 仅合法代付 transfer_adapter → BIZ_TRANSFER_ONLY
     */
    public function testResolveTransferOnly(): void
    {
        $biz = $this->resolver->resolve([
            'adapter'          => '',
            'transfer_adapter' => 'mock_transfer',
        ]);

        $this->assertSame(Channel::BIZ_TRANSFER_ONLY, $biz);
        $this->assertFalse($this->resolver->isPayCapable(['adapter' => '']));
        $this->assertTrue($this->resolver->isTransferCapable(['transfer_adapter' => 'mock_transfer']));
    }

    /**
     * 两端均合法 → BIZ_BOTH
     */
    public function testResolveBoth(): void
    {
        $biz = $this->resolver->resolve([
            'adapter'          => 'alipay_scan',
            'transfer_adapter' => 'bank_transfer',
        ]);

        $this->assertSame(Channel::BIZ_BOTH, $biz);
    }

    /**
     * 非法或未注册适配器 → BIZ_NONE
     */
    public function testResolveNoneWhenAdaptersInvalid(): void
    {
        $this->assertSame(Channel::BIZ_NONE, $this->resolver->resolve([
            'adapter'          => 'not_registered',
            'transfer_adapter' => 'also_bad',
        ]));
        // 代付不回退 adapter：仅有 mock_transfer 在 adapter 字段不算代付能力
        $this->assertSame(Channel::BIZ_PAY_ONLY, $this->resolver->resolve([
            'adapter'          => 'mock',
            'transfer_adapter' => '',
        ]));
        $this->assertFalse($this->resolver->isTransferCapable([
            'adapter'          => 'mock_transfer',
            'transfer_adapter' => '',
        ]));
    }

    /**
     * applyToSaveData 剔除前端手填 channel_biz 并重算
     */
    public function testApplyToSaveDataStripsClientBiz(): void
    {
        $out = $this->resolver->applyToSaveData([
            'adapter'          => 'mock',
            'transfer_adapter' => 'mock_transfer',
            'channel_biz'      => 99,
        ]);

        $this->assertSame(Channel::BIZ_BOTH, $out['channel_biz']);
    }

    /**
     * 编辑合并：在仅代收行上补丁 transfer_adapter → 升为双能力
     */
    public function testMergeForUpdatePreservesDualCapability(): void
    {
        $existing = [
            'adapter'          => 'mock',
            'transfer_adapter' => '',
            'channel_biz'      => Channel::BIZ_PAY_ONLY,
        ];
        $patch = ['transfer_adapter' => 'mock_transfer'];
        $merged = $this->resolver->mergeForUpdate($patch, $existing);
        $biz = $this->resolver->resolve($merged);

        $this->assertSame(Channel::BIZ_BOTH, $biz);
    }

    /**
     * ChannelLogic::add 归一化路径：剔除手填 biz 并按 adapter 重算（不触库）
     */
    public function testChannelLogicAddNormalizesBiz(): void
    {
        $logic = new TestableChannelLogic(null, new ChannelBizResolver());
        $logic->add([
            'title'            => '测试',
            'code'             => 'biz_test',
            'adapter'          => 'wechat_scan',
            'transfer_adapter' => '',
            'pay_type'         => 3,
            'status'           => 1,
            'channel_biz'      => 2,
        ]);

        $this->assertSame(Channel::BIZ_PAY_ONLY, (int) ($logic->lastPayload['channel_biz'] ?? -1));
    }
}

/**
 * 可测试 ChannelLogic：拦截 add/edit 载荷，脱离 ORM
 */
class TestableChannelLogic extends ChannelLogic
{
    /** @var array<string,mixed> */
    public array $lastPayload = [];

    public function add(array $data): mixed
    {
        $this->lastPayload = $this->getBizResolver()->applyToSaveData($data);

        return 1;
    }
}
