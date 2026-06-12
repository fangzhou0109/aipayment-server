<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：路由-通道绑定验证器单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\admin;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\validate\RouteChannelValidate;

/**
 * 可注入路由/通道数据的验证器（脱离 DB）
 */
class TestableRouteChannelValidate extends RouteChannelValidate
{
    public ?array $route = null;
    public ?array $channel = null;

    protected function loadRouteForMatch(int $routeId): ?array
    {
        return $this->route;
    }

    protected function loadChannelForMatch(int $channelId): ?array
    {
        return $this->channel;
    }
}

/**
 * RouteChannelValidate 规则测试
 */
class RouteChannelValidateTest extends TestCase
{
    private function validator(array $route, array $channel): TestableRouteChannelValidate
    {
        $v = new TestableRouteChannelValidate();
        $v->route = $route;
        $v->channel = $channel;

        return $v;
    }

    /**
     * 支付类型一致且具备代收能力时通过
     */
    public function testChannelRouteMatchPasses(): void
    {
        $v = $this->validator(
            ['id' => 1, 'pay_type' => 3, 'status' => 1],
            ['id' => 10, 'pay_type' => 3, 'channel_biz' => Channel::BIZ_PAY_ONLY, 'status' => 1]
        );

        $this->assertTrue($v->scene('save')->check([
            'route_id'   => 1,
            'channel_id' => 10,
            'money_rule' => '100-500',
            'weight'     => 1,
            'status'     => 1,
        ]));
    }

    /**
     * 支付类型不一致时拒绝
     */
    public function testChannelRouteMatchRejectsPayTypeMismatch(): void
    {
        $v = $this->validator(
            ['id' => 1, 'pay_type' => 3, 'status' => 1],
            ['id' => 10, 'pay_type' => 1, 'channel_biz' => Channel::BIZ_PAY_ONLY, 'status' => 1]
        );

        $this->assertFalse($v->scene('save')->check([
            'route_id'   => 1,
            'channel_id' => 10,
            'money_rule' => '',
            'weight'     => 1,
            'status'     => 1,
        ]));
        $this->assertStringContainsString('支付类型', $v->getError());
    }

    /**
     * 仅代付通道不可绑定代收路由
     */
    public function testChannelRouteMatchRejectsTransferOnlyChannel(): void
    {
        $v = $this->validator(
            ['id' => 1, 'pay_type' => 3, 'status' => 1],
            ['id' => 10, 'pay_type' => 3, 'channel_biz' => Channel::BIZ_TRANSFER_ONLY, 'status' => 1]
        );

        $this->assertFalse($v->scene('save')->check([
            'route_id'   => 1,
            'channel_id' => 10,
            'money_rule' => '',
            'weight'     => 1,
            'status'     => 1,
        ]));
        $this->assertStringContainsString('代收', $v->getError());
    }
}
