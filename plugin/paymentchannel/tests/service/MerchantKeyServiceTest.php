<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：MerchantKeyService 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\MerchantKeyService;
use plugin\paymentchannel\service\SignService;

/**
 * MerchantKeyService 商户密钥生成测试
 *
 * 纯函数，不依赖 DB；验证 MD5 密钥格式/随机性，以及生成的 RSA 密钥对可正常签验。
 */
class MerchantKeyServiceTest extends TestCase
{
    /**
     * MD5 密钥为 32 位十六进制，且两次生成不相同（随机性）
     */
    public function testGenerateSecretKey(): void
    {
        $k1 = MerchantKeyService::generateSecretKey();
        $k2 = MerchantKeyService::generateSecretKey();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $k1);
        $this->assertNotSame($k1, $k2, '两次生成的密钥应不同');
    }

    /**
     * 生成的 RSA 密钥对应为合法 PEM，且能完成签名/验签闭环
     */
    public function testGenerateRsaKeyPairUsableForSigning(): void
    {
        $pair = MerchantKeyService::generateRsaKeyPair();
        $this->assertStringContainsString('BEGIN', $pair['private']);
        $this->assertStringContainsString('BEGIN', $pair['public']);

        // 用生成的私钥签名、公钥验签，验证可用性（secretKey 任意，仅参与拼串）
        $params = ['mch_id' => 'M1', 'money' => 100];
        $sign = SignService::makeSign($params, 'k', SignService::SIGN_TYPE_RSA, $pair['private']);
        $params['sign'] = $sign;
        $this->assertTrue(
            SignService::verify($params, 'k', SignService::SIGN_TYPE_RSA, $pair['public'])
        );
    }

    /**
     * 平台私钥可提取公钥，供测试 notify 验签使用
     */
    public function testExtractPublicKeyFromPrivate(): void
    {
        $pair = MerchantKeyService::generateRsaKeyPair();
        $extracted = MerchantKeyService::extractPublicKeyFromPrivate($pair['private']);
        $this->assertNotNull($extracted);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $extracted);

        $params = ['mch_id' => 'M1', 'money' => '100'];
        $sign = SignService::makeSign($params, 'k', SignService::SIGN_TYPE_RSA, $pair['private']);
        $params['sign'] = $sign;
        $this->assertTrue(
            SignService::verify($params, 'k', SignService::SIGN_TYPE_RSA, $extracted)
        );
    }
}
