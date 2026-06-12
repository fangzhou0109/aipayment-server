<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付适配器契约测试 + 工厂测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service\transfer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;
use plugin\paymentchannel\service\transfer\AbstractTransferAdapter;
use plugin\paymentchannel\service\transfer\TransferAdapterFactory;
use plugin\paymentchannel\service\transfer\TransferAdapterInterface;
use plugin\paymentchannel\service\transfer\TransferAdapterRegistry;

/**
 * 代付适配器契约测试
 *
 * 保证注册表里每个已实现代付适配器都满足统一契约（实现接口、继承基类、可被工厂创建），
 * 这是「新增代付上游零改动」承诺的自动化守门。
 */
class TransferAdapterContractTest extends TestCase
{
    /**
     * 注册表中所有已实现适配器都必须实现接口且继承抽象基类
     */
    public function testAllRegisteredAdaptersHonorContract(): void
    {
        $implemented = 0;
        foreach (TransferAdapterRegistry::codes() as $code) {
            $class = TransferAdapterRegistry::resolveClass($code);
            if ($class === null) {
                continue;
            }
            $implemented++;
            $this->assertTrue(class_exists($class), "代付适配器类不存在: {$class}");
            $this->assertTrue(
                is_subclass_of($class, TransferAdapterInterface::class),
                "代付适配器未实现接口: {$class}"
            );
            $this->assertTrue(
                is_subclass_of($class, AbstractTransferAdapter::class),
                "代付适配器未继承抽象基类: {$class}"
            );
        }
        $this->assertGreaterThanOrEqual(1, $implemented);
    }

    /**
     * options 返回 label/value 结构且含 mock_transfer
     */
    public function testRegistryOptions(): void
    {
        $options = TransferAdapterRegistry::options();
        $this->assertNotEmpty($options);
        $values = array_column($options, 'value');
        $this->assertContains('mock_transfer', $values);
    }

    /**
     * 工厂能按 code 创建出实现接口的实例
     */
    public function testFactoryMakesInstance(): void
    {
        $adapter = TransferAdapterFactory::make('mock_transfer', new ChannelCredential());
        $this->assertInstanceOf(TransferAdapterInterface::class, $adapter);
    }

    /**
     * 工厂可直接从通道数据创建（须配置 transfer_adapter，Phase 9.5.4）
     */
    public function testFactoryMakesFromChannel(): void
    {
        $adapter = TransferAdapterFactory::makeFromChannel([
            'id'               => 9,
            'code'             => 'tf_bank',
            'transfer_adapter' => 'bank_transfer',
            'gateway_url'      => 'https://up.example.com/transfer',
            'upstream_mch_id'  => 'UP123',
            'upstream_key'     => 'secret',
        ]);
        $this->assertInstanceOf(TransferAdapterInterface::class, $adapter);
    }

    /**
     * Phase 9.5.4：未配置 transfer_adapter 时拒绝（不再回退 adapter）
     */
    public function testFactoryRejectsMissingTransferAdapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('transfer_adapter');
        TransferAdapterFactory::makeFromChannel([
            'id'      => 10,
            'code'    => 'pay_only_mock',
            'adapter' => 'mock_transfer',
        ]);
    }

    /**
     * 工厂遇到未注册/未实现标识抛异常
     */
    public function testFactoryThrowsOnUnknownAdapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransferAdapterFactory::make('not_registered', new ChannelCredential());
    }
}
