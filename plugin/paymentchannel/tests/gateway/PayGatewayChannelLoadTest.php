<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：PayGatewayLogic 通道密钥加载回归测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\gateway;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\PayGatewayLogic;
use plugin\paymentchannel\app\model\Channel;
use ReflectionMethod;

/**
 * 下单网关须把 upstream_key 传给适配器；Channel::$hidden 会导致 toArray 丢密钥
 */
class PayGatewayChannelLoadTest extends TestCase
{
    /**
     * 回归前提：序列化通道时密钥被隐藏，不能作为 loadActiveChannel 的数据源
     */
    public function testChannelToArrayHidesUpstreamKey(): void
    {
        $channel = new Channel();
        $channel->id = 14;
        $channel->code = 'lqpay';
        $channel->adapter = 'lqpay';
        $channel->upstream_key = 'secret_upstream_key_32chars_xx';
        $channel->status = 1;

        $arr = $channel->toArray();
        $this->assertArrayNotHasKey('upstream_key', $arr);
        // 属性仍可读，供 loadActiveChannel 直接取值
        $this->assertSame('secret_upstream_key_32chars_xx', $channel->upstream_key);
    }

    /**
     * formatChannelForAdapter 在入参含密钥时应原样透传（loadActiveChannel 修复后依赖此行为）
     */
    public function testFormatChannelForAdapterKeepsUpstreamKey(): void
    {
        $logic = new PayGatewayLogic();
        $method = new ReflectionMethod(PayGatewayLogic::class, 'formatChannelForAdapter');
        $method->setAccessible(true);

        $row = [
            'id' => 14,
            'code' => 'lqpay',
            'adapter' => 'lqpay',
            'gateway_url' => 'https://api.example.com/prod/pay',
            'upstream_mch_id' => 'TEST_M001',
            'upstream_key' => 'secret_upstream_key_32chars_xx',
            'upstream_public_key' => '',
            'upstream_private_key' => '',
            'pay_type' => 3,
            'rate' => '2.0000',
            'rate_self' => '3.0000',
        ];

        $formatted = $method->invoke($logic, $row);
        $this->assertSame('secret_upstream_key_32chars_xx', $formatted['upstream_key']);
    }
}
