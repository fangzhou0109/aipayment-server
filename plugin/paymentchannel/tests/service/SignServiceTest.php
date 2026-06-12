<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：SignService 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\SignService;

/**
 * SignService 签名/验签测试
 *
 * 重点：与参考项目 GpayDome 的签名规则「对拍一致」，并覆盖 MD5/RSA 正确性、
 * 篡改拒签、空值与中文参数、时间窗口、异常分支。RSA 密钥对在测试内动态生成，
 * 不硬编码、不依赖外部文件与服务。
 */
class SignServiceTest extends TestCase
{
    /** 商户密钥（与参考项目 Demo 同值，用于对拍） */
    private const KEY = '7f23278bc4814831e45cb58c2922c8cf';

    /**
     * 参考项目 sign_str() 的等价实现，用于「对拍」验证我方 buildSignString 一致。
     *
     * @param array $para 业务参数
     * @param string $secret 密钥
     * @return string
     */
    private function referenceSignStr(array $para, string $secret): string
    {
        unset($para['sign'], $para['sign_type']);
        ksort($para);
        $arg = '';
        foreach ($para as $k => $v) {
            $arg .= ($k . '=' . $v . '&');
        }
        return $arg . 'key=' . $secret;
    }

    /**
     * 待签串与参考项目逐字节一致（含空值字段参与拼接）
     */
    public function testBuildSignStringMatchesReference(): void
    {
        $params = [
            'mch_id' => 'N201907231000',
            'pay_type' => 3,
            'money' => 10000,
            'time' => 1588999999,
            'order_id' => 'TG20200508',
            'extra' => '', // 空值也应参与
            'client_ip' => '127.0.0.1',
        ];
        $expected = $this->referenceSignStr($params, self::KEY);
        $actual = SignService::buildSignString($params, self::KEY);
        $this->assertSame($expected, $actual);
        // 同时验证 ksort 生效（client_ip 在最前，pay_type 在 order_id 之后）
        $this->assertStringStartsWith('client_ip=127.0.0.1&', $actual);
        $this->assertStringEndsWith('&key=' . self::KEY, $actual);
    }

    /**
     * MD5 签名 = 大写 md5(待签串)，与参考项目算法一致
     */
    public function testMd5SignMatchesReference(): void
    {
        $params = ['mch_id' => 'M1', 'money' => 100, 'time' => 1588999999];
        $expected = strtoupper(md5($this->referenceSignStr($params, self::KEY)));
        $actual = SignService::makeSign($params, self::KEY, SignService::SIGN_TYPE_MD5);
        $this->assertSame($expected, $actual);
    }

    /**
     * MD5 验签：正确签名通过，且大小写不敏感（verify 内部统一转大写）
     */
    public function testMd5VerifyOkAndCaseInsensitive(): void
    {
        $params = ['mch_id' => 'M1', 'money' => 100, 'time' => 1588999999];
        $sign = SignService::makeSign($params, self::KEY, SignService::SIGN_TYPE_MD5);
        $params['sign'] = $sign;
        $this->assertTrue(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_MD5));
        // 小写签名也应通过
        $params['sign'] = strtolower($sign);
        $this->assertTrue(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_MD5));
    }

    /**
     * 篡改任一参数后验签必败
     */
    public function testMd5VerifyFailsOnTamper(): void
    {
        $params = ['mch_id' => 'M1', 'money' => 100, 'time' => 1588999999];
        $params['sign'] = SignService::makeSign($params, self::KEY, SignService::SIGN_TYPE_MD5);
        $params['money'] = 999; // 篡改金额
        $this->assertFalse(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_MD5));
    }

    /**
     * 缺失签名直接判负
     */
    public function testVerifyFailsWhenSignMissing(): void
    {
        $params = ['mch_id' => 'M1', 'money' => 100];
        $this->assertFalse(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_MD5));
    }

    /**
     * 中文参数也能稳定签名与验签
     */
    public function testChineseParam(): void
    {
        $params = ['commodity_name' => '测试产品名称', 'money' => 100];
        $params['sign'] = SignService::makeSign($params, self::KEY, SignService::SIGN_TYPE_MD5);
        $this->assertTrue(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_MD5));
    }

    /**
     * RSA 签名/验签闭环（动态生成 2048 位密钥对）
     *
     * 注意：待签串仍用 secretKey 拼接（与参考项目一致），RSA 私钥/公钥单独传入。
     */
    public function testRsaSignAndVerify(): void
    {
        [$privateKey, $publicKey] = $this->generateRsaKeyPair();
        $params = ['mch_id' => 'M1', 'money' => 10000, 'time' => 1588999999];

        // 私钥签名（secretKey 拼串 + 私钥签名）
        $sign = SignService::makeSign($params, self::KEY, SignService::SIGN_TYPE_RSA, $privateKey);
        $this->assertNotSame('', $sign);

        // 公钥验签通过
        $params['sign'] = $sign;
        $this->assertTrue(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_RSA, $publicKey));

        // 篡改后验签失败
        $params['money'] = 1;
        $this->assertFalse(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_RSA, $publicKey));
    }

    /**
     * RSA 签名缺少私钥时抛异常
     */
    public function testRsaSignWithoutPrivateKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SignService::makeSign(['a' => 1], self::KEY, SignService::SIGN_TYPE_RSA);
    }

    /**
     * 非法签名类型抛异常
     */
    public function testInvalidSignTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SignService::makeSign(['a' => 1], self::KEY, 99);
    }

    /**
     * 时间窗口：窗口内通过、超窗拒绝、非法时间拒绝
     */
    public function testCheckTimeWindow(): void
    {
        $now = 1_700_000_000;
        $this->assertTrue(SignService::checkTime($now - 10, 3600, $now));   // 10 秒前，窗口内
        $this->assertTrue(SignService::checkTime($now + 10, 3600, $now));   // 轻微超前，窗口内
        $this->assertFalse(SignService::checkTime($now - 7200, 3600, $now)); // 2 小时前，超窗
        $this->assertFalse(SignService::checkTime(0, 3600, $now));           // 非法时间
    }

    /**
     * 动态生成 RSA 2048 密钥对，返回 [私钥 PEM, 公钥 PEM]
     *
     * @return array{0:string,1:string}
     */
    private function generateRsaKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privateKey);
        $publicKey = openssl_pkey_get_details($res)['key'];
        return [$privateKey, $publicKey];
    }
}
