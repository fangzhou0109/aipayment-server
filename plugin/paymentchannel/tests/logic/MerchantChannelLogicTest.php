<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-通道授权逻辑测试（批量绑定，脱离 DB）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\MerchantChannelLogic;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\validate\MerchantChannelValidate;
use plugin\paymentchannel\tests\admin\TestableMerchantChannelValidate;
use plugin\saiadmin\exception\ApiException;

/**
 * 可测试逻辑：内存替代绑定表与通道表
 */
class TestableMerchantChannelLogic extends MerchantChannelLogic
{
    /** merchantId => channelId => binding row */
    public array $bindings = [];
    /** channelId => channel row */
    public array $channels = [];
    /** merchantId => merchant row */
    public array $merchants = [];
    /** 捕获新增 */
    public array $created = [];
    /** 捕获更新 */
    public array $updated = [];
    private int $autoId = 8000;

    public function __construct()
    {
        // 跳过父构造，避免实例化 ORM
    }

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function makeValidate(): MerchantChannelValidate
    {
        $v = new TestableMerchantChannelValidate();
        foreach ($this->channels as $id => $ch) {
            $v->channels[(int) $id] = ['rate' => (string) ($ch['rate'] ?? '0')];
        }
        return $v;
    }

    protected function loadBindingsByMerchant(int $merchantId): array
    {
        $rows = [];
        foreach ($this->bindings[$merchantId] ?? [] as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    protected function loadChannelForList(int $channelId): ?array
    {
        return $this->channels[$channelId] ?? null;
    }

    protected function loadTransferCapableChannels(): array
    {
        $rows = [];
        foreach ($this->channels as $channel) {
            $biz = (int) ($channel['channel_biz'] ?? Channel::BIZ_PAY_ONLY);
            if (in_array($biz, [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH], true)) {
                $rows[] = $channel;
            }
        }

        return $rows;
    }

    protected function findBinding(int $merchantId, int $channelId): ?array
    {
        return $this->bindings[$merchantId][$channelId] ?? null;
    }

    protected function loadBindingsByChannel(int $channelId): array
    {
        $rows = [];
        foreach ($this->bindings as $byChannel) {
            if (isset($byChannel[$channelId])) {
                $rows[] = $byChannel[$channelId];
            }
        }
        return $rows;
    }

    protected function loadActiveMerchants(): array
    {
        return array_values($this->merchants);
    }

    protected function loadMerchantForAuthorize(int $merchantId): ?array
    {
        return $this->merchants[$merchantId] ?? null;
    }

    public function add(array $data): mixed
    {
        $id = ++$this->autoId;
        $merchantId = (int) $data['merchant_id'];
        $channelId = (int) $data['channel_id'];
        $row = array_merge($data, ['id' => $id]);
        $this->bindings[$merchantId][$channelId] = $row;
        $this->created[] = $row;
        return $id;
    }

    public function edit($id, array $data): mixed
    {
        foreach ($this->bindings as $mid => &$byChannel) {
            foreach ($byChannel as $cid => &$row) {
                if ((int) $row['id'] === (int) $id) {
                    $row = array_merge($row, $data, ['id' => (int) $id]);
                    $byChannel[$cid] = $row;
                    $this->updated[] = $row;
                    return true;
                }
            }
        }
        throw new ApiException('数据不存在');
    }
}

/**
 * MerchantChannelLogic 批量绑定与列表测试
 */
class MerchantChannelLogicTest extends TestCase
{
    private function channel(int $id, array $override = []): array
    {
        return array_merge([
            'id'                 => $id,
            'title'              => '通道' . $id,
            'code'               => 'ch' . $id,
            'pay_type'           => 3,
            'rate'               => '1.5000',
            'rate_self'          => '2.6000',
            'rate_transfer_self' => '1.2000',
            'transfer_adapter'   => 'mock_transfer',
            'channel_biz'        => Channel::BIZ_BOTH,
            'money_rule'         => '',
            'sort'               => 0,
            'status'             => 1,
        ], $override);
    }

    private function merchant(int $id, array $override = []): array
    {
        return array_merge([
            'id'     => $id,
            'mch_id' => 'M' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
            'name'   => '商户' . $id,
            'status' => 1,
        ], $override);
    }

    private function logic(): TestableMerchantChannelLogic
    {
        $logic = new TestableMerchantChannelLogic();
        $logic->channels = [
            10 => $this->channel(10, ['channel_biz' => Channel::BIZ_PAY_ONLY, 'transfer_adapter' => '']),
            20 => $this->channel(20, ['rate' => '2.0000', 'rate_self' => '3.0000']),
            30 => $this->channel(30, [
                'title'              => '纯代付',
                'code'               => 'tf_only',
                'channel_biz'        => Channel::BIZ_TRANSFER_ONLY,
                'rate_transfer_self' => '0.8000',
            ]),
        ];
        $logic->merchants = [
            1 => $this->merchant(1),
            2 => $this->merchant(2),
            3 => $this->merchant(3),
        ];
        return $logic;
    }

    /**
     * batchBind 新增绑定
     */
    public function testBatchBindCreatesNew(): void
    {
        $logic = $this->logic();
        $result = $logic->batchBind(1, [
            ['channel_id' => 10, 'rate' => '0', 'status' => 1],
        ]);

        $this->assertSame(1, $result['saved']);
        $this->assertCount(1, $logic->created);
        $this->assertSame(1, $logic->created[0]['merchant_id']);
        $this->assertSame(10, $logic->created[0]['channel_id']);
    }

    /**
     * batchBind 同商户+通道 upsert 更新
     */
    public function testBatchBindUpdatesExisting(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][10] = [
            'id' => 99, 'merchant_id' => 1, 'channel_id' => 10,
            'rate' => '0.0000', 'day_limit' => '0.0000', 'status' => 1,
        ];

        $logic->batchBind(1, [
            ['channel_id' => 10, 'rate' => '2.6000', 'status' => 2],
        ]);

        $this->assertCount(1, $logic->updated);
        $this->assertSame('2.6000', $logic->updated[0]['rate']);
        $this->assertSame(2, $logic->updated[0]['status']);
        $this->assertCount(0, $logic->created);
    }

    /**
     * merchant_id 以参数为准，行内伪造 merchant_id 被覆盖
     */
    public function testBatchBindIgnoresRowMerchantId(): void
    {
        $logic = $this->logic();
        $logic->batchBind(5, [
            ['merchant_id' => 999, 'channel_id' => 10, 'rate' => '0', 'status' => 1],
        ]);

        $this->assertArrayHasKey(5, $logic->bindings);
        $this->assertArrayHasKey(10, $logic->bindings[5]);
        $this->assertSame(5, $logic->bindings[5][10]['merchant_id']);
    }

    /**
     * 亏损费率 batchBind 拒绝
     */
    public function testBatchBindRejectsUnprofitableRate(): void
    {
        $logic = $this->logic();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('上游成本');
        $logic->batchBind(1, [
            ['channel_id' => 10, 'rate' => '1.5000', 'status' => 1],
        ]);
    }

    /**
     * listByMerchant 含通道只读参考字段
     */
    public function testListByMerchantIncludesChannelRefs(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][20] = [
            'id' => 1, 'merchant_id' => 1, 'channel_id' => 20,
            'rate' => '0.0000', 'day_limit' => '100.0000', 'status' => MerchantChannel::STATUS_NORMAL,
            'remark' => '',
        ];

        $list = $logic->listByMerchant(1);
        $this->assertCount(1, $list);
        $this->assertSame('通道20', $list[0]['channel_title']);
        $this->assertSame('ch20', $list[0]['channel_code']);
        $this->assertSame(3, $list[0]['channel_pay_type']);
        $this->assertSame('3.0000', $list[0]['channel_rate_self']);
        $this->assertSame('2.0000', $list[0]['channel_upstream_rate']);
        $this->assertSame('0.0000', $list[0]['rate']);
    }

    /**
     * batchBind 持久化 single_min/max（缺省为 0 不限）
     */
    public function testBatchBindPersistsSingleLimits(): void
    {
        $logic = $this->logic();
        $logic->batchBind(1, [
            [
                'channel_id' => 10,
                'rate'       => '0',
                'single_min' => '10.0000',
                'single_max' => '5000.0000',
                'status'     => 1,
            ],
        ]);

        $this->assertSame('10.0000', $logic->created[0]['single_min']);
        $this->assertSame('5000.0000', $logic->created[0]['single_max']);
    }

    /**
     * listByMerchant 返回 single_min/max
     */
    public function testListByMerchantIncludesSingleLimits(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][10] = [
            'id' => 1, 'merchant_id' => 1, 'channel_id' => 10,
            'rate' => '0.0000', 'day_limit' => '0.0000',
            'single_min' => '50.0000', 'single_max' => '1000.0000',
            'status' => MerchantChannel::STATUS_NORMAL,
            'remark' => '',
        ];

        $list = $logic->listByMerchant(1);
        $this->assertSame('50.0000', $list[0]['single_min']);
        $this->assertSame('1000.0000', $list[0]['single_max']);
    }

    /**
     * listByChannel：合并正常商户与绑定状态
     */
    public function testListByChannelMergesMerchants(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][10] = [
            'id' => 50, 'merchant_id' => 1, 'channel_id' => 10,
            'rate' => '0.0000', 'status' => MerchantChannel::STATUS_NORMAL,
        ];

        $list = $logic->listByChannel(10);
        $this->assertCount(3, $list);

        $m1 = array_values(array_filter($list, fn ($r) => $r['merchant_id'] === 1))[0];
        $m2 = array_values(array_filter($list, fn ($r) => $r['merchant_id'] === 2))[0];

        $this->assertSame(1, $m1['status']);
        $this->assertSame('0.0000', $m1['rate']);
        $this->assertSame(2, $m2['status']);
        $this->assertSame('M001', $m1['mch_id']);
    }

    /**
     * batchAuthorizeByChannel：新商户开通，rate=0 继承
     */
    public function testBatchAuthorizeByChannelCreatesBinding(): void
    {
        $logic = $this->logic();
        $result = $logic->batchAuthorizeByChannel(10, [2, 3], MerchantChannel::STATUS_NORMAL);

        $this->assertSame(2, $result['saved']);
        $this->assertCount(2, $logic->created);
        $this->assertSame('0.0000', $logic->created[0]['rate']);
        $this->assertSame(10, $logic->created[0]['channel_id']);
        $this->assertArrayHasKey(2, $logic->bindings);
        $this->assertArrayHasKey(10, $logic->bindings[2]);
    }

    /**
     * batchAuthorizeByChannel：已有绑定仅恢复 status，保留定制费率
     */
    public function testBatchAuthorizeByChannelReEnablesPreservesRate(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][10] = [
            'id' => 88, 'merchant_id' => 1, 'channel_id' => 10,
            'rate' => '2.8000', 'status' => MerchantChannel::STATUS_DISABLED,
        ];

        $logic->batchAuthorizeByChannel(10, [1], MerchantChannel::STATUS_NORMAL);

        $this->assertCount(0, $logic->created);
        $this->assertCount(1, $logic->updated);
        $this->assertSame(1, $logic->updated[0]['status']);
        $this->assertSame('2.8000', $logic->bindings[1][10]['rate']);
    }

    /**
     * batchAuthorizeByChannel：批量关闭授权
     */
    public function testBatchAuthorizeByChannelDisables(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][10] = [
            'id' => 88, 'merchant_id' => 1, 'channel_id' => 10,
            'rate' => '0.0000', 'status' => MerchantChannel::STATUS_NORMAL,
        ];
        $logic->bindings[2][10] = [
            'id' => 89, 'merchant_id' => 2, 'channel_id' => 10,
            'rate' => '0.0000', 'status' => MerchantChannel::STATUS_NORMAL,
        ];

        $result = $logic->batchAuthorizeByChannel(10, [1, 2], MerchantChannel::STATUS_DISABLED);

        $this->assertSame(2, $result['saved']);
        $this->assertSame(2, $logic->bindings[1][10]['status']);
        $this->assertSame(2, $logic->bindings[2][10]['status']);
    }

    /**
     * 通道不存在时 batchAuthorizeByChannel 拒绝
     */
    public function testBatchAuthorizeByChannelRejectsMissingChannel(): void
    {
        $logic = $this->logic();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('通道不存在');
        $logic->batchAuthorizeByChannel(999, [1], MerchantChannel::STATUS_NORMAL);
    }

    /**
     * 通道不存在时 batchBind 拒绝
     */
    public function testBatchBindRejectsMissingChannel(): void
    {
        $logic = $this->logic();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('通道不存在');
        $logic->batchBind(1, [
            ['channel_id' => 999, 'rate' => '0', 'status' => 1],
        ]);
    }

    /**
     * listTransferByMerchant：仅返回具备代付能力的通道
     */
    public function testListTransferByMerchantOnlyTransferCapableChannels(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][20] = [
            'id' => 1, 'merchant_id' => 1, 'channel_id' => 20,
            'rate_transfer' => '0.0000', 'transfer_enabled' => MerchantChannel::TRANSFER_ENABLED,
            'status' => MerchantChannel::STATUS_NORMAL, 'rate' => '0.0000',
        ];

        $list = $logic->listTransferByMerchant(1);
        $ids = array_column($list, 'channel_id');

        $this->assertContains(20, $ids);
        $this->assertContains(30, $ids);
        $this->assertNotContains(10, $ids);

        $row20 = array_values(array_filter($list, fn ($r) => $r['channel_id'] === 20))[0];
        $row30 = array_values(array_filter($list, fn ($r) => $r['channel_id'] === 30))[0];

        $this->assertSame(1, $row20['transfer_enabled']);
        $this->assertSame('1.2000', $row20['channel_rate_transfer_self']);
        $this->assertSame(2, $row30['transfer_enabled']);
        $this->assertSame('0.8000', $row30['channel_rate_transfer_self']);
    }

    /**
     * batchBindTransfer：不覆盖既有代收 status / rate
     */
    public function testBatchBindTransferPreservesPayAuthorization(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][20] = [
            'id' => 77, 'merchant_id' => 1, 'channel_id' => 20,
            'rate' => '2.8000', 'status' => MerchantChannel::STATUS_NORMAL,
            'rate_transfer' => '0.0000', 'transfer_enabled' => MerchantChannel::TRANSFER_DISABLED,
            'day_limit' => '0.0000', 'single_min' => '0.0000', 'single_max' => '0.0000',
        ];

        $logic->batchBindTransfer(1, [
            ['channel_id' => 20, 'rate_transfer' => '1.5000', 'transfer_enabled' => 1],
        ]);

        $this->assertCount(1, $logic->updated);
        $this->assertSame('2.8000', $logic->bindings[1][20]['rate']);
        $this->assertSame(1, $logic->bindings[1][20]['status']);
        $this->assertSame('1.5000', $logic->bindings[1][20]['rate_transfer']);
        $this->assertSame(1, $logic->bindings[1][20]['transfer_enabled']);
    }

    /**
     * batchAuthorizeTransferByChannel：新商户开通代付，代收默认停用
     */
    public function testBatchAuthorizeTransferByChannelCreatesBinding(): void
    {
        $logic = $this->logic();
        $result = $logic->batchAuthorizeTransferByChannel(30, [2], MerchantChannel::TRANSFER_ENABLED);

        $this->assertSame(1, $result['saved']);
        $this->assertCount(1, $logic->created);
        $this->assertSame(2, $logic->created[0]['merchant_id']);
        $this->assertSame(30, $logic->created[0]['channel_id']);
        $this->assertSame(1, $logic->created[0]['transfer_enabled']);
        $this->assertSame(2, $logic->created[0]['status']);
        $this->assertSame('0.0000', $logic->created[0]['rate_transfer']);
    }

    /**
     * batchAuthorizeTransferByChannel：纯代收通道拒绝
     */
    public function testBatchAuthorizeTransferByChannelRejectsPayOnlyChannel(): void
    {
        $logic = $this->logic();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('不具备代付能力');
        $logic->batchAuthorizeTransferByChannel(10, [1], MerchantChannel::TRANSFER_ENABLED);
    }

    /**
     * batchBindTransfer：纯代收通道拒绝
     */
    public function testBatchBindTransferRejectsPayOnlyChannel(): void
    {
        $logic = $this->logic();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('不具备代付能力');
        $logic->batchBindTransfer(1, [
            ['channel_id' => 10, 'rate_transfer' => '0', 'transfer_enabled' => 1],
        ]);
    }

    /**
     * 商户门户代收列表：仅返回已授权且通道启用的代收通道，并脱敏
     */
    public function testListEnabledPayForMerchantPortalFiltersAndFormats(): void
    {
        $logic = $this->logic();
        // 通道 10：绑定已开但通道平台侧停用 → 不展示
        $logic->bindings[1][10] = [
            'id' => 1, 'merchant_id' => 1, 'channel_id' => 10,
            'rate' => '2.5000', 'status' => MerchantChannel::STATUS_NORMAL,
            'single_min' => '10.0000', 'single_max' => '0.0000', 'day_limit' => '50000.0000',
            'rate_transfer' => '0.0000', 'transfer_enabled' => MerchantChannel::TRANSFER_DISABLED,
        ];
        // 通道 20：双能力，绑定已开 → 应展示
        $logic->bindings[1][20] = [
            'id' => 2, 'merchant_id' => 1, 'channel_id' => 20,
            'rate' => '2.5000', 'status' => MerchantChannel::STATUS_NORMAL,
            'single_min' => '10.0000', 'single_max' => '0.0000', 'day_limit' => '50000.0000',
            'rate_transfer' => '0.0000', 'transfer_enabled' => MerchantChannel::TRANSFER_DISABLED,
        ];
        // 通道 30：纯代付，即使代收 status=1 也不应出现在代收列表
        $logic->bindings[1][30] = [
            'id' => 3, 'merchant_id' => 1, 'channel_id' => 30,
            'rate' => '0.0000', 'status' => MerchantChannel::STATUS_NORMAL,
            'single_min' => '0.0000', 'single_max' => '0.0000', 'day_limit' => '0.0000',
            'rate_transfer' => '1.0000', 'transfer_enabled' => MerchantChannel::TRANSFER_ENABLED,
        ];
        $logic->channels[10]['status'] = 2;
        $logic->channels[20]['money_rule'] = '100-500';

        $list = $logic->listEnabledPayForMerchantPortal(1);

        $this->assertCount(1, $list);
        $this->assertSame(2, $list[0]['id']);
        $this->assertSame(3, $list[0]['pay_type']);
        $this->assertSame('微信PC', $list[0]['pay_type_name']);
        $this->assertSame('100-500', $list[0]['money_rule']);
        $this->assertSame('2.5000', $list[0]['rate']);
        $this->assertSame('2.5000', $list[0]['effective_rate']);
        $this->assertFalse($list[0]['rate_inherit']);
        $this->assertArrayNotHasKey('channel_code', $list[0]);
        $this->assertArrayNotHasKey('channel_title', $list[0]);
        $this->assertArrayNotHasKey('channel_id', $list[0]);
    }

    /**
     * 商户门户代付列表：仅返回 transfer_enabled=1 且通道启用的代付通道
     */
    public function testListEnabledTransferForMerchantPortalFiltersAndFormats(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][20] = [
            'id' => 1, 'merchant_id' => 1, 'channel_id' => 20,
            'rate_transfer' => '1.8000', 'transfer_enabled' => MerchantChannel::TRANSFER_ENABLED,
            'status' => MerchantChannel::STATUS_NORMAL, 'rate' => '0.0000',
        ];
        $logic->bindings[1][30] = [
            'id' => 2, 'merchant_id' => 1, 'channel_id' => 30,
            'rate_transfer' => '0.0000', 'transfer_enabled' => MerchantChannel::TRANSFER_DISABLED,
            'status' => MerchantChannel::STATUS_DISABLED, 'rate' => '0.0000',
        ];

        $logic->channels[20]['money_rule'] = '1000-50000';
        $logic->channels[20]['sort'] = 10;

        $list = $logic->listEnabledTransferForMerchantPortal(1);

        $this->assertCount(1, $list);
        $this->assertSame(1, $list[0]['id']);
        $this->assertSame(Channel::BIZ_BOTH, $list[0]['channel_biz']);
        $this->assertSame('代收+代付', $list[0]['channel_biz_name']);
        $this->assertSame('1000-50000', $list[0]['money_rule']);
        $this->assertSame('1.8000', $list[0]['rate_transfer']);
        $this->assertSame('1.8000', $list[0]['effective_rate_transfer']);
        $this->assertFalse($list[0]['rate_inherit']);
        $this->assertSame(1, $list[0]['is_fee_default']);
        $this->assertArrayNotHasKey('channel_code', $list[0]);
        $this->assertArrayNotHasKey('channel_title', $list[0]);
        $this->assertArrayNotHasKey('channel_id', $list[0]);
        $this->assertArrayNotHasKey('channel_transfer_adapter', $list[0]);
    }
}
