<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：TestNotifyService 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\MerchantKeyService;
use plugin\paymentchannel\service\MerchantNotifyService;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\TestNotifyService;

/**
 * 可注入商户数据的测试接收器
 */
class TestableTestNotifyService extends TestNotifyService
{
    public ?array $merchant = null;

    protected function loadMerchantByMchId(string $mchId): ?array
    {
        return $this->merchant;
    }

    protected function isEnabled(): bool
    {
        return true;
    }

    protected function acceptInvalidSign(): bool
    {
        return false;
    }
}

class TestNotifyServiceTest extends TestCase
{
    private const KEY = 'test_secret_001';

    /**
     * 验签通过时回应 SUCCESS
     */
    public function testHandleReturnsSuccessWhenSignValid(): void
    {
        $body = [
            'order_id'  => 'OUT001',
            'order_no'  => 'P20260609001',
            'money'     => '10000',
            'mch_id'    => 'M001',
            'extra'     => '',
            'status'    => 'success',
            'time'      => '1710000000',
            'sign_type' => SignService::SIGN_TYPE_MD5,
        ];
        $body['sign'] = SignService::makeSign($body, self::KEY, SignService::SIGN_TYPE_MD5);

        $svc = new TestableTestNotifyService();
        $svc->merchant = [
            'mch_id' => 'M001', 'secret_key' => self::KEY,
            'rsa_private_key' => '', 'rsa_public_key' => '',
        ];

        $result = $svc->handle($body, '127.0.0.1');
        $this->assertSame(200, $result['http_code']);
        $this->assertSame(TestNotifyService::RESPONSE_SUCCESS, $result['response']);
        $this->assertTrue($result['sign_ok']);
    }

    /**
     * 验签失败时回应 FAIL
     */
    public function testHandleReturnsFailWhenSignInvalid(): void
    {
        $svc = new TestableTestNotifyService();
        $svc->merchant = [
            'mch_id' => 'M001', 'secret_key' => self::KEY,
            'rsa_private_key' => '', 'rsa_public_key' => '',
        ];

        $result = $svc->handle([
            'order_id' => 'OUT001',
            'order_no' => 'P20260609001',
            'money'    => '10000',
            'mch_id'   => 'M001',
            'status'   => 'success',
            'sign'     => 'BAD',
            'sign_type' => SignService::SIGN_TYPE_MD5,
        ]);

        $this->assertSame(TestNotifyService::RESPONSE_FAIL, $result['response']);
        $this->assertFalse($result['sign_ok']);
    }

    /**
     * 与 MerchantNotifyService 出站签名对齐：MD5 闭环验签通过
     */
    public function testHandleMatchesMerchantNotifyDispatchBody(): void
    {
        $secretKey = 'notify_loop_secret_32hex00000001';
        $order = [
            'order_no'     => 'P20260609120000001',
            'out_trade_no' => 'OUT_LOOP_001',
            'mch_id'       => 'M001',
            'merchant_id'  => 1,
            'amount'       => '100.0000',
            'notify_url'   => 'http://127.0.0.1/pay/test/notify',
            'sign_type'    => SignService::SIGN_TYPE_MD5,
            'extra'        => '',
        ];

        $captured = [];
        $verifyResult = [];
        $notifySvc = new class($secretKey, $captured, $verifyResult) extends MerchantNotifyService {
            public function __construct(
                private string $key,
                public array &$captured,
                public array &$verifyResult,
            ) {}

            protected function loadMerchantSecret(int $merchantId): array
            {
                return ['secret_key' => $this->key, 'rsa_private_key' => ''];
            }

            protected function createLog(array $data): int { return 1; }

            protected function attempt(int $logId, string $url, array $body, string $orderNo, int $retryNum): bool
            {
                $this->captured = $body;
                $receiver = new TestableTestNotifyService();
                $receiver->merchant = [
                    'mch_id' => 'M001', 'secret_key' => $this->key,
                    'rsa_private_key' => '', 'rsa_public_key' => '',
                ];
                $this->verifyResult = $receiver->handle($body);

                return $this->verifyResult['response'] === TestNotifyService::RESPONSE_SUCCESS;
            }

            protected function now(): int { return 1710000000; }
        };

        $this->assertTrue($notifySvc->dispatch($order));
        $this->assertNotEmpty($captured['sign']);
        $this->assertTrue($verifyResult['sign_ok'] ?? false, $verifyResult['sign_message'] ?? '');
    }

    /**
     * RSA 通知：须用平台私钥对应公钥验签（非商户 rsa_public_key）
     */
    public function testHandleRsaUsesPlatformPublicKey(): void
    {
        $pair = MerchantKeyService::generateRsaKeyPair();
        $secretKey = 'rsa_loop_secret_32hex000000001';

        $body = [
            'order_id'  => 'OUT_RSA',
            'order_no'  => 'P_RSA_001',
            'money'     => '5000',
            'mch_id'    => 'M002',
            'extra'     => '',
            'status'    => 'success',
            'time'      => '1710000000',
            'sign_type' => SignService::SIGN_TYPE_RSA,
        ];
        $body['sign'] = SignService::makeSign($body, $secretKey, SignService::SIGN_TYPE_RSA, $pair['private']);

        $svc = new TestableTestNotifyService();
        $svc->merchant = [
            'mch_id'          => 'M002',
            'secret_key'      => $secretKey,
            'rsa_private_key' => $pair['private'],
            'rsa_public_key'  => 'merchant_uploaded_public_should_not_be_used',
        ];

        $result = $svc->handle($body);
        $this->assertTrue($result['sign_ok']);
        $this->assertSame(TestNotifyService::RESPONSE_SUCCESS, $result['response']);
    }
}
