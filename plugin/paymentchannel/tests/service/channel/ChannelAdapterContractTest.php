<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：适配器契约测试 + 工厂测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service\channel;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\channel\AbstractChannelAdapter;
use plugin\paymentchannel\service\channel\ChannelAdapterFactory;
use plugin\paymentchannel\service\channel\ChannelAdapterInterface;
use plugin\paymentchannel\service\channel\ChannelAdapterRegistry;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;

/**
 * 适配器契约测试
 *
 * 保证「注册表里每个已实现适配器」都满足统一契约（实现接口、继承基类、可被工厂创建），
 * 这是「新增上游零改动」承诺的自动化守门：任何登记了 class 的适配器都必须合规。
 */
class ChannelAdapterContractTest extends TestCase
{
    /**
     * 注册表中所有已实现适配器都必须实现接口且继承抽象基类
     */
    public function testAllRegisteredAdaptersHonorContract(): void
    {
        $implemented = 0;
        foreach (ChannelAdapterRegistry::codes() as $code) {
            $class = ChannelAdapterRegistry::resolveClass($code);
            if ($class === null) {
                continue; // 未实现的标识跳过
            }
            $implemented++;
            $this->assertTrue(class_exists($class), "适配器类不存在: {$class}");
            $this->assertTrue(
                is_subclass_of($class, ChannelAdapterInterface::class),
                "适配器未实现接口: {$class}"
            );
            $this->assertTrue(
                is_subclass_of($class, AbstractChannelAdapter::class),
                "适配器未继承抽象基类: {$class}"
            );
        }
        // 至少应有 mock 一个已实现适配器
        $this->assertGreaterThanOrEqual(1, $implemented);
    }

    /**
     * 工厂能按 code 创建出实现接口的实例
     */
    public function testFactoryMakesInstance(): void
    {
        $adapter = ChannelAdapterFactory::make('mock', new ChannelCredential());
        $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
    }

    /**
     * 工厂可直接从通道数据创建（解析 adapter 字段 + 凭证）
     */
    public function testFactoryMakesFromChannel(): void
    {
        $adapter = ChannelAdapterFactory::makeFromChannel([
            'id' => 9,
            'adapter' => 'alipay_scan',
            'gateway_url' => 'https://up.example.com/pay',
            'upstream_mch_id' => 'UP123',
            'upstream_key' => 'secret',
        ]);
        $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
    }

    /**
     * 工厂遇到未注册/未实现标识抛异常
     */
    public function testFactoryThrowsOnUnknownAdapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ChannelAdapterFactory::make('not_registered', new ChannelCredential());
    }
}
