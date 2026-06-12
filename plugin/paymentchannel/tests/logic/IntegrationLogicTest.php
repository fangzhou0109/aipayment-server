<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户对接逻辑测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\IntegrationLogic;
use plugin\paymentchannel\service\SignService;

/**
 * IntegrationLogic 单测
 */
class IntegrationLogicTest extends TestCase
{
    /**
     * resolveGatewayBaseUrl：notify_domain 配置时拼 /pay（含 nginx 反代前缀）
     */
    public function testResolveGatewayBaseUrlUsesNotifyDomain(): void
    {
        $logic = new IntegrationLogic();
        $domain = trim((string) config('plugin.paymentchannel.app.notify_domain', ''));
        $explicit = trim((string) config('plugin.paymentchannel.app.pay_gateway_base', ''));

        $url = $logic->resolveGatewayBaseUrl(null);

        if ($explicit !== '') {
            $this->assertSame(rtrim($explicit, '/'), $url);
        } elseif ($domain !== '') {
            $this->assertSame(rtrim($domain, '/') . '/pay', $url);
            $this->assertStringContainsString('/prod/pay', $url);
        } else {
            $this->assertStringEndsWith('/pay', $url);
        }
    }

    /**
     * PHP Demo 下载 URL 与 notify_domain 反代前缀一致
     */
    public function testResolvePhpDemoDownloadMetaUsesNotifyDomain(): void
    {
        $logic = new IntegrationLogic();
        $meta = $logic->resolvePhpDemoDownloadMeta(null);

        $this->assertSame('merchant-php.zip', $meta['filename']);
        $this->assertNotSame('', $meta['url']);

        $domain = trim((string) config('plugin.paymentchannel.app.notify_domain', ''));
        $explicit = trim((string) config('plugin.paymentchannel.app.php_demo_download_url', ''));
        if ($explicit !== '') {
            $this->assertSame($explicit, $meta['url']);
        } elseif ($domain !== '') {
            $this->assertSame(rtrim($domain, '/') . '/merchant-php.zip', $meta['url']);
        }

        $this->assertIsBool($meta['available']);
    }

    /**
     * MD5 签名与 SignService 规则一致（冒烟）
     */
    public function testMd5SignSmoke(): void
    {
        $this->assertSame(
            strtoupper(md5('mch_id=M001&order_id=O1&key=secret')),
            SignService::makeSign(
                ['mch_id' => 'M001', 'order_id' => 'O1'],
                'secret',
                SignService::SIGN_TYPE_MD5
            )
        );
    }
}
