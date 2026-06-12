<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付通道逻辑层测试（Phase 9.5.2）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\TransferChannelLogic;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\service\ChannelBizResolver;
use plugin\paymentchannel\service\transfer\TransferAdapterRegistry;

/**
 * TransferChannelLogic 作用域、读写守卫与建档归一化
 */
class TransferChannelLogicTest extends TestCase
{
    /**
     * searchTransfer 注入代付 channel_biz 作用域
     */
    public function testSearchTransferInjectsBizScope(): void
    {
        $logic = new TestableTransferChannelLogic();
        $logic->searchTransfer(['keyword' => 'mock']);

        $this->assertSame(Channel::BIZ_SCOPE_TRANSFER, $logic->lastSearchWhere['channel_biz'] ?? null);
        $this->assertSame('mock', $logic->lastSearchWhere['keyword'] ?? null);
    }

    /**
     * readTransfer 拒绝仅代收通道
     */
    public function testReadTransferRejectsPayOnlyChannel(): void
    {
        $logic = new TestableTransferChannelLogic();
        $logic->rows[1] = [
            'id'          => 1,
            'channel_biz' => Channel::BIZ_PAY_ONLY,
            'adapter'     => 'mock',
        ];

        $this->assertNull($logic->readTransfer(1));
    }

    /**
     * readTransfer 接受仅代付与双能力通道
     */
    public function testReadTransferAcceptsTransferCapableChannels(): void
    {
        $logic = new TestableTransferChannelLogic();
        $logic->rows[2] = ['id' => 2, 'channel_biz' => Channel::BIZ_TRANSFER_ONLY];
        $logic->rows[3] = ['id' => 3, 'channel_biz' => Channel::BIZ_BOTH];

        $this->assertNotNull($logic->readTransfer(2));
        $this->assertNotNull($logic->readTransfer(3));
    }

    /**
     * 纯代付 add 将 adapter 归一化为空串并算出 biz=2
     */
    public function testAddNormalizesTransferOnlyPayload(): void
    {
        $logic = new TestableTransferChannelLogic(null, new ChannelBizResolver());
        $logic->add([
            'title'              => '纯代付',
            'code'               => 'pure_tf',
            'transfer_adapter'   => 'mock_transfer',
            'rate_transfer_self' => '1.0000',
            'status'             => 1,
        ]);

        $this->assertSame('', $logic->lastPayload['adapter'] ?? null);
        $this->assertSame(Channel::BIZ_TRANSFER_ONLY, (int) ($logic->lastPayload['channel_biz'] ?? -1));
    }

    /**
     * transferAdapterOptions 与注册表 options 一致
     */
    public function testTransferAdapterOptionsMatchRegistry(): void
    {
        $logic = new TransferChannelLogic();
        $this->assertSame(TransferAdapterRegistry::options(), $logic->transferAdapterOptions());
    }
}

/**
 * 可测试 TransferChannelLogic：拦截 search/read/add 载荷
 */
class TestableTransferChannelLogic extends TransferChannelLogic
{
    /** @var array<string,mixed> */
    public array $lastSearchWhere = [];

    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    /** @var array<string,mixed> */
    public array $lastPayload = [];

    public function search(array $searchWhere = []): mixed
    {
        $this->lastSearchWhere = $searchWhere;

        return null;
    }

    public function read($id): mixed
    {
        return $this->rows[(int) $id] ?? null;
    }

    public function add(array $data): mixed
    {
        $data = $this->normalizeTransferCreate($data);
        $this->lastPayload = $this->getBizResolver()->applyToSaveData($data);

        return 1;
    }
}
