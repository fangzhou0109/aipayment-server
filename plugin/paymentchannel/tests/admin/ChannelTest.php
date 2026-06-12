<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游通道管理 Registry/Validate 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\admin;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use plugin\paymentchannel\app\validate\ChannelValidate;
use plugin\paymentchannel\service\channel\ChannelAdapterRegistry;

/**
 * 上游通道管理测试
 *
 * 纯逻辑（不依赖 DB）：适配器注册表的选项/存在性/类解析，以及验证器 checkAdapter 规则。
 */
class ChannelTest extends TestCase
{
    /**
     * 注册表 options 返回 label/value 结构且非空
     */
    public function testAdapterOptionsStructure(): void
    {
        $options = ChannelAdapterRegistry::options();
        $this->assertNotEmpty($options);
        foreach ($options as $opt) {
            $this->assertArrayHasKey('label', $opt);
            $this->assertArrayHasKey('value', $opt);
        }
        // mock 适配器应始终可选
        $values = array_column($options, 'value');
        $this->assertContains('mock', $values);
    }

    /**
     * 存在性判断：已注册返回 true，未注册返回 false
     */
    public function testAdapterExists(): void
    {
        $this->assertTrue(ChannelAdapterRegistry::exists('mock'));
        $this->assertFalse(ChannelAdapterRegistry::exists('not_registered'));
    }

    /**
     * 类解析：已实现的适配器返回类全名，未注册返回 null（Phase 3.1 已回填实现类）
     */
    public function testResolveClassNullWhenUnimplemented(): void
    {
        // Phase 3.1 起 mock 已实现，应解析到具体类
        $this->assertSame(
            \plugin\paymentchannel\service\channel\adapters\MockAdapter::class,
            ChannelAdapterRegistry::resolveClass('mock')
        );
        // 未注册标识仍返回 null
        $this->assertNull(ChannelAdapterRegistry::resolveClass('not_registered'));
    }

    /**
     * 验证器 checkAdapter：合法适配器通过，非法返回错误串
     */
    public function testValidateCheckAdapter(): void
    {
        $v = new ChannelValidate();
        $m = new ReflectionMethod($v, 'checkAdapter');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($v, 'mock'));
        $result = $m->invoke($v, 'bad_adapter');
        $this->assertIsString($result);
        $this->assertStringContainsString('非法', $result);
    }

    /**
     * 验证器 checkMoneyRule：空/范围/固定池通过，非法格式拒绝
     */
    public function testValidateCheckMoneyRule(): void
    {
        $v = new ChannelValidate();
        $m = new ReflectionMethod($v, 'checkMoneyRule');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($v, ''));
        $this->assertTrue($m->invoke($v, '300-10000'));
        $this->assertTrue($m->invoke($v, '800+1000+2000'));

        $result = $m->invoke($v, 'bad_rule');
        $this->assertIsString($result);
        $this->assertStringContainsString('金额规则', $result);
    }
}
