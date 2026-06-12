<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：网关 IP 白名单中间件（纯逻辑）测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\gateway;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\middleware\IpWhitelist;

/**
 * IpWhitelist::isAllowed 纯判定逻辑测试
 */
class IpWhitelistTest extends TestCase
{
    /**
     * 命中白名单 → 放行
     */
    public function testIpInWhitelist(): void
    {
        $this->assertTrue(IpWhitelist::isAllowed('1.2.3.4, 5.6.7.8', '5.6.7.8'));
    }

    /**
     * 不在白名单 → 拒绝
     */
    public function testIpNotInWhitelist(): void
    {
        $this->assertFalse(IpWhitelist::isAllowed('1.2.3.4,5.6.7.8', '9.9.9.9'));
    }

    /**
     * 非严格模式：空白名单 → 视为不限制，放行
     */
    public function testEmptyWhitelistAllowsAllInLooseMode(): void
    {
        $this->assertTrue(IpWhitelist::isAllowed('', '9.9.9.9'));
        $this->assertTrue(IpWhitelist::isAllowed('  ,  ', '9.9.9.9'));
    }

    /**
     * 严格模式：空白名单 → 拒绝
     */
    public function testEmptyWhitelistRejectsInStrictMode(): void
    {
        $this->assertFalse(IpWhitelist::isAllowed('', '9.9.9.9', true));
        $this->assertFalse(IpWhitelist::isAllowed('  ,  ', '1.2.3.4', true));
    }

    /**
     * parseIpList 支持去空白
     */
    public function testParseIpListTrimsItems(): void
    {
        $this->assertSame(['1.2.3.4', '5.6.7.8'], IpWhitelist::parseIpList(' 1.2.3.4 , 5.6.7.8 '));
    }
}
