<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：Model 层结构测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use plugin\saiadmin\basic\think\BaseModel;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\model\MerchantRoute;
use plugin\paymentchannel\app\model\Route;
use plugin\paymentchannel\app\model\RouteChannel;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\app\model\Transfer;
use plugin\paymentchannel\app\model\Withdraw;
use plugin\paymentchannel\app\model\Recharge;
use plugin\paymentchannel\app\model\BankCard;
use plugin\paymentchannel\app\model\CapitalFlow;
use plugin\paymentchannel\app\model\NotifyLog;
use plugin\paymentchannel\app\model\ChannelLog;

/**
 * Model 层结构测试
 *
 * 说明：ThinkORM 的实际读写需要数据库连接（本地无 MySQL，且不连远端生产库），
 * 故本测试只做「不依赖 DB 的纯结构断言」：自动加载、继承基类、表名、状态常量、
 * 关联方法与隐藏字段配置。真正的 CRUD/关联查询在服务器侧按需验证。
 */
class ModelStructureTest extends TestCase
{
    /**
     * 全部 14 个模型 => 期望表名 的映射
     * @return array<string,string>
     */
    private function modelTableMap(): array
    {
        return [
            Merchant::class        => 'sa_pay_merchant',
            Channel::class         => 'sa_pay_channel',
            MerchantChannel::class => 'sa_pay_merchant_channel',
            MerchantRoute::class   => 'sa_pay_merchant_route',
            Route::class           => 'sa_pay_route',
            RouteChannel::class    => 'sa_pay_route_channel',
            Order::class           => 'sa_pay_order',
            Transfer::class        => 'sa_pay_transfer',
            Withdraw::class        => 'sa_pay_withdraw',
            Recharge::class        => 'sa_pay_recharge',
            BankCard::class        => 'sa_pay_bank_card',
            CapitalFlow::class     => 'sa_pay_capital_flow',
            NotifyLog::class       => 'sa_pay_notify_log',
            ChannelLog::class      => 'sa_pay_channel_log',
        ];
    }

    /**
     * 每个模型都应继承 BaseModel，且表名配置正确
     */
    public function testAllModelsExtendBaseAndHaveTable(): void
    {
        foreach ($this->modelTableMap() as $class => $table) {
            $this->assertTrue(is_subclass_of($class, BaseModel::class), "$class 应继承 BaseModel");
            $props = (new ReflectionClass($class))->getDefaultProperties();
            $this->assertSame($table, $props['table'] ?? null, "$class 表名应为 $table");
        }
    }

    /**
     * 订单状态机常量值符合 SQL 注释约定（0待支付 1已支付 2失败 3已关闭）
     */
    public function testOrderStatusConstants(): void
    {
        $this->assertSame(0, Order::STATUS_PENDING);
        $this->assertSame(1, Order::STATUS_PAID);
        $this->assertSame(2, Order::STATUS_FAILED);
        $this->assertSame(3, Order::STATUS_CLOSED);
        $this->assertSame(0, Order::SETTLE_PENDING);
        $this->assertSame(1, Order::SETTLE_DONE);
    }

    /**
     * 提现状态机常量含负值（审核拒绝 -1 / 代付失败 -2）
     */
    public function testWithdrawStatusConstants(): void
    {
        $this->assertSame(0, Withdraw::STATUS_PENDING);
        $this->assertSame(3, Withdraw::STATUS_SUCCESS);
        $this->assertSame(-1, Withdraw::STATUS_REJECTED);
        $this->assertSame(-2, Withdraw::STATUS_PAY_FAILED);
    }

    /**
     * 资金流水业务类型与账户类型常量齐全
     */
    public function testCapitalFlowConstants(): void
    {
        $this->assertSame(1, CapitalFlow::BIZ_PAY_IN);
        $this->assertSame(2, CapitalFlow::BIZ_WITHDRAW_FREEZE);
        $this->assertSame(4, CapitalFlow::BIZ_WITHDRAW_REFUND);
        $this->assertSame(1, CapitalFlow::ACCOUNT_BALANCE);
        $this->assertSame(2, CapitalFlow::ACCOUNT_FREEZE);
    }

    /**
     * 敏感字段隐藏：商户模型不得下发 secret_key / rsa_private_key / password
     */
    public function testMerchantHidesSensitiveFields(): void
    {
        $hidden = (new ReflectionClass(Merchant::class))->getDefaultProperties()['hidden'] ?? [];
        $this->assertContains('secret_key', $hidden);
        $this->assertContains('rsa_private_key', $hidden);
        $this->assertContains('password', $hidden);
        $this->assertContains('delete_time', $hidden);
    }

    /**
     * 通道模型隐藏上游密钥
     */
    public function testChannelHidesUpstreamSecrets(): void
    {
        $hidden = (new ReflectionClass(Channel::class))->getDefaultProperties()['hidden'] ?? [];
        $this->assertContains('upstream_key', $hidden);
        $this->assertContains('upstream_private_key', $hidden);
    }

    /**
     * 关联方法存在性：订单关联商户/通道，商户关联银行卡/通道定制
     */
    public function testRelationMethodsExist(): void
    {
        $this->assertTrue(method_exists(Order::class, 'merchant'));
        $this->assertTrue(method_exists(Order::class, 'channel'));
        $this->assertTrue(method_exists(Merchant::class, 'bankCards'));
        $this->assertTrue(method_exists(Merchant::class, 'merchantChannels'));
        $this->assertTrue(method_exists(Route::class, 'routeChannels'));
        $this->assertTrue(method_exists(Withdraw::class, 'bankCard'));
    }

    /**
     * 商户-通道授权：状态常量与费率继承哨兵值（Phase 9.1）
     */
    public function testMerchantChannelAuthSemantics(): void
    {
        $this->assertSame(1, MerchantChannel::STATUS_NORMAL);
        $this->assertSame(2, MerchantChannel::STATUS_DISABLED);
        $this->assertSame('0.0000', MerchantChannel::RATE_INHERIT);
        $this->assertTrue(method_exists(MerchantChannel::class, 'searchMerchantIdAttr'));
        $this->assertTrue(method_exists(MerchantChannel::class, 'searchChannelIdAttr'));
        $this->assertTrue(method_exists(MerchantChannel::class, 'searchStatusAttr'));
    }

    /**
     * 商户-通道代付授权与费率字段（Phase 9.4.1）
     */
    public function testMerchantChannelTransferSemantics(): void
    {
        $this->assertSame(1, MerchantChannel::TRANSFER_ENABLED);
        $this->assertSame(2, MerchantChannel::TRANSFER_DISABLED);
        $this->assertTrue(method_exists(MerchantChannel::class, 'searchTransferEnabledAttr'));
        // rate_transfer=0 与代收 rate 共用继承哨兵
        $this->assertSame(MerchantChannel::RATE_INHERIT, '0.0000');
    }

    /**
     * 商户-通道单笔限额：0 表示不限（Phase 9.2.1）
     */
    public function testMerchantChannelSingleLimitSemantics(): void
    {
        $this->assertSame('0.0000', MerchantChannel::LIMIT_UNLIMITED);
        $this->assertSame(MerchantChannel::RATE_INHERIT, MerchantChannel::LIMIT_UNLIMITED);
    }

    /**
     * 订单费率来源常量（Phase 9.3.4）
     */
    public function testOrderRateSourceConstants(): void
    {
        $this->assertSame('merchant_channel', Order::RATE_SOURCE_MERCHANT_CHANNEL);
        $this->assertSame('route', Order::RATE_SOURCE_ROUTE);
        $this->assertSame('channel', Order::RATE_SOURCE_CHANNEL);
    }

    /**
     * 通道业务能力 channel_biz 常量与搜索器（Phase 9.5.1）
     */
    public function testChannelBizSemantics(): void
    {
        $this->assertSame(0, Channel::BIZ_NONE);
        $this->assertSame(1, Channel::BIZ_PAY_ONLY);
        $this->assertSame(2, Channel::BIZ_TRANSFER_ONLY);
        $this->assertSame(3, Channel::BIZ_BOTH);
        $this->assertSame('pay', Channel::BIZ_SCOPE_PAY);
        $this->assertSame('transfer', Channel::BIZ_SCOPE_TRANSFER);
        $this->assertTrue(method_exists(Channel::class, 'searchChannelBizAttr'));
    }

    /**
     * 商户-路由授权：状态常量与搜索器（Phase 9.3.1）
     */
    public function testMerchantRouteAuthSemantics(): void
    {
        $this->assertSame(1, MerchantRoute::STATUS_NORMAL);
        $this->assertSame(2, MerchantRoute::STATUS_DISABLED);
        $this->assertTrue(method_exists(MerchantRoute::class, 'searchMerchantIdAttr'));
        $this->assertTrue(method_exists(MerchantRoute::class, 'searchRouteIdAttr'));
        $this->assertTrue(method_exists(MerchantRoute::class, 'searchStatusAttr'));
        $this->assertTrue(method_exists(MerchantRoute::class, 'merchant'));
        $this->assertTrue(method_exists(MerchantRoute::class, 'route'));
    }
}
