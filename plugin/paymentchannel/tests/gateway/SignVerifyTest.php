<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：网关签名校验中间件（纯逻辑）测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\gateway;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\middleware\SignVerify;
use plugin\paymentchannel\service\SignService;

/**
 * SignVerify::verifyMerchant 纯校验逻辑测试（不依赖 DB/请求）
 */
class SignVerifyTest extends TestCase
{
    /** 商户 MD5 密钥 */
    private const KEY = 'merchant_secret_key_001';

    /**
     * 构造一组带正确签名的下单参数
     *
     * @param array $override 覆盖字段
     * @return array
     */
    private function signedParams(array $override = []): array
    {
        $params = array_merge([
            'mch_id' => 'M001',
            'pay_type' => 3,
            'money' => '10000',
            'time' => (string) time(),
            'order_id' => 'OUT20260608001',
            'notify_url' => 'https://merchant.example.com/notify',
        ], $override);
        $params['sign'] = SignService::makeSign($params, self::KEY, SignService::SIGN_TYPE_MD5);
        $params['sign_type'] = SignService::SIGN_TYPE_MD5;
        return $params;
    }

    /**
     * 正常：状态正常 + 时间有效 + 签名正确 → 通过（返回 null）
     */
    public function testValidPassed(): void
    {
        $merchant = ['status' => 1, 'secret_key' => self::KEY, 'rsa_public_key' => ''];
        $this->assertNull(SignVerify::verifyMerchant($merchant, $this->signedParams()));
    }

    /**
     * 商户停用 → 返回错误
     */
    public function testDisabledMerchant(): void
    {
        $merchant = ['status' => 2, 'secret_key' => self::KEY, 'rsa_public_key' => ''];
        $this->assertSame('商户已停用', SignVerify::verifyMerchant($merchant, $this->signedParams()));
    }

    /**
     * 缺少签名 → 返回错误
     */
    public function testMissingSign(): void
    {
        $merchant = ['status' => 1, 'secret_key' => self::KEY, 'rsa_public_key' => ''];
        $params = $this->signedParams();
        unset($params['sign']);
        $this->assertSame('缺少签名 sign', SignVerify::verifyMerchant($merchant, $params));
    }

    /**
     * 时间超窗 → 返回错误
     */
    public function testTimeOutOfWindow(): void
    {
        $merchant = ['status' => 1, 'secret_key' => self::KEY, 'rsa_public_key' => ''];
        // 时间设为 2 小时前（超过默认 1 小时窗口）；签名需基于该 time 重算
        $params = $this->signedParams(['time' => (string) (time() - 7200)]);
        $this->assertSame('请求时间超出允许范围', SignVerify::verifyMerchant($merchant, $params));
    }

    /**
     * 签名被篡改 → 返回错误
     */
    public function testTamperedSign(): void
    {
        $merchant = ['status' => 1, 'secret_key' => self::KEY, 'rsa_public_key' => ''];
        $params = $this->signedParams();
        $params['money'] = '99999'; // 改金额但不更新 sign
        $this->assertSame('签名校验失败', SignVerify::verifyMerchant($merchant, $params));
    }
}
