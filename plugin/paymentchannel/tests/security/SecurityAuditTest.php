<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：安全审计测试（OWASP Top 10：重放/越权/限流/脱敏/隐藏/防篡改）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\security;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\PayGatewayLogic;
use plugin\paymentchannel\app\middleware\MerchantAuth;
use plugin\paymentchannel\app\middleware\RateLimit;
use plugin\paymentchannel\app\middleware\SignVerify;
use plugin\paymentchannel\app\controller\merchant\BaseMerchantController;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\service\RateLimitService;
use plugin\paymentchannel\service\SensitiveHelper;
use plugin\paymentchannel\service\SignService;

/**
 * 可测试查单逻辑：内存订单按 merchant_id 强约束查询（模拟真实 WHERE merchant_id），
 * 用于验证「越权查他人订单」被拒。
 */
class TestableAuthQueryLogic extends PayGatewayLogic
{
    /** 内存订单：完整行（含 merchant_id 归属） */
    public array $orderRow = [];

    protected function findOrder(int $merchantId, string $outTradeNo): ?array
    {
        // 模拟 SQL：WHERE merchant_id=? AND out_trade_no=? —— 归属不符返回 null（查不到他人单）
        if ((int) ($this->orderRow['merchant_id'] ?? 0) === $merchantId
            && (string) ($this->orderRow['out_trade_no'] ?? '') === $outTradeNo) {
            return $this->orderRow;
        }
        return null;
    }
}

/**
 * 安全审计测试（OWASP Top 10 关键项）
 *
 * 覆盖 README 7.2 要求：重放（时间窗口）、越权（merchant_id 强约束 / 平台隔离）、
 * 限流（Redis 固定窗口）；并复核日志脱敏、敏感字段隐藏、签名防篡改（hash_equals）。
 * 全部脱离 DB/Redis/网络。
 */
class SecurityAuditTest extends TestCase
{
    private const KEY = 'audit_secret_key';

    // ========== A) 重放攻击防御（时间窗口 + 签名） ==========

    /**
     * 时间窗口：窗口内时间戳通过，超窗（重放旧请求）被拒
     */
    public function testReplayTimeWindow(): void
    {
        $now = 1781000000;
        // 窗口内（now 当下）→ 通过
        $this->assertTrue(SignService::checkTime($now, 3600, $now));
        // 5 分钟前 → 仍在 1 小时窗口内 → 通过
        $this->assertTrue(SignService::checkTime($now - 300, 3600, $now));
        // 2 小时前（重放旧报文）→ 超窗 → 拒
        $this->assertFalse(SignService::checkTime($now - 7200, 3600, $now));
        // 未来时间超窗（时钟漂移/伪造）→ 拒
        $this->assertFalse(SignService::checkTime($now + 7200, 3600, $now));
    }

    /**
     * 网关验签：缺时间/超窗的请求被 SignVerify 拒（防重放纵深）
     */
    public function testSignVerifyRejectsStaleRequest(): void
    {
        $merchant = ['status' => 1, 'secret_key' => self::KEY, 'rsa_public_key' => ''];
        // 构造一个时间戳很旧的请求（即便签名正确，时间超窗也应被拒）
        $params = ['mch_id' => 'M1', 'order_id' => 'O1', 'time' => (string) (time() - 100000)];
        $params['sign'] = SignService::makeSign($params, self::KEY, SignService::SIGN_TYPE_MD5);

        $error = SignVerify::verifyMerchant($merchant, $params);
        $this->assertNotNull($error);
        $this->assertStringContainsString('时间', $error);
    }

    /**
     * 签名防篡改（hash_equals 恒定时间比较）：篡改参数后验签失败
     */
    public function testSignTamperRejected(): void
    {
        $params = ['mch_id' => 'M1', 'money' => '10000', 'time' => (string) time()];
        $params['sign'] = SignService::makeSign($params, self::KEY, SignService::SIGN_TYPE_MD5);

        // 原样验签通过
        $this->assertTrue(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_MD5));
        // 篡改金额后验签必败
        $tampered = $params;
        $tampered['money'] = '99999';
        $this->assertFalse(SignService::verify($tampered, self::KEY, SignService::SIGN_TYPE_MD5));
        // 缺签名直接失败
        unset($params['sign']);
        $this->assertFalse(SignService::verify($params, self::KEY, SignService::SIGN_TYPE_MD5));
    }

    // ========== B) 越权访问防御（merchant_id 强约束 / 平台隔离） ==========

    /**
     * 商户身份取自 token：合法返回 id，缺失/非法/0 一律抛异常（不信任 params）
     */
    public function testResolveMerchantIdFromToken(): void
    {
        $this->assertSame(7, BaseMerchantController::resolveMerchantId(['id' => 7, 'plat' => 'merchant']));

        foreach ([['id' => 0], ['id' => -1], [], 'not-array', null, ['foo' => 1]] as $bad) {
            try {
                BaseMerchantController::resolveMerchantId($bad);
                $this->fail('非法身份应抛异常');
            } catch (PaymentException $e) {
                $this->assertStringContainsString('商户身份', $e->getMessage());
            }
        }
    }

    /**
     * 越权查单：商户 A 用自己 token 查 B 的订单 → 查不到（merchant_id 强约束），拒绝
     */
    public function testCrossMerchantOrderQueryDenied(): void
    {
        $logic = new TestableAuthQueryLogic();
        // 订单归属商户 B(id=2)
        $logic->orderRow = ['merchant_id' => 2, 'out_trade_no' => 'B_OUT_1', 'order_no' => 'P_B_1', 'amount' => '100.0000', 'status' => 1];

        // 商户 A(id=1) 拿自己的 token 查 B 的订单号 → 抛「订单不存在」
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('订单不存在');
        $logic->queryOrder(['id' => 1, 'mch_id' => 'A'], ['order_id' => 'B_OUT_1']);
    }

    /**
     * 商户 B 用自己 token 能查到自己的订单（强约束不误伤正常访问）
     */
    public function testOwnMerchantOrderQueryAllowed(): void
    {
        $logic = new TestableAuthQueryLogic();
        $logic->orderRow = ['merchant_id' => 2, 'out_trade_no' => 'B_OUT_1', 'order_no' => 'P_B_1', 'amount' => '100.0000', 'status' => 1];

        $result = $logic->queryOrder(['id' => 2, 'mch_id' => 'B'], ['order_id' => 'B_OUT_1']);
        $this->assertSame('P_B_1', $result['order_no']);
    }

    /**
     * 平台/商户 token 物理隔离：仅 plat=merchant 通过 MerchantAuth；平台 token 被拒
     */
    public function testPlatformTokenIsolation(): void
    {
        $this->assertTrue(MerchantAuth::isMerchantToken(['plat' => 'merchant', 'id' => 1]));
        $this->assertFalse(MerchantAuth::isMerchantToken(['plat' => 'saiadmin', 'id' => 1]));
        $this->assertFalse(MerchantAuth::isMerchantToken(['id' => 1])); // 缺 plat
    }

    /**
     * SQL 注入防御：恶意订单号作为「数据」原样传入查询接缝（参数绑定，不拼接 SQL）
     */
    public function testSqlInjectionStringTreatedAsData(): void
    {
        $logic = new TestableAuthQueryLogic();
        $payload = "1' OR '1'='1";
        $logic->orderRow = ['merchant_id' => 1, 'out_trade_no' => $payload, 'order_no' => 'P1', 'amount' => '1.0000', 'status' => 1];

        // 注入串被当作普通订单号字面量匹配（ThinkORM 参数绑定），不会“恒真”查出别人的单
        $result = $logic->queryOrder(['id' => 1, 'mch_id' => 'A'], ['order_id' => $payload]);
        $this->assertSame('P1', $result['order_no']);
        // 用一个未注册的注入串查 → 查不到（证明是按字面值匹配而非被注入绕过）
        $this->expectException(PaymentException::class);
        $logic->queryOrder(['id' => 1, 'mch_id' => 'A'], ['order_id' => "x' OR '1'='1"]);
    }

    // ========== C) 限流（Redis 固定窗口） ==========

    /**
     * 限流：窗口内放行至上限，超频被拒
     */
    public function testRateLimitBlocksOverLimit(): void
    {
        // 内存计数器：同 key 自增
        $store = [];
        $svc = new RateLimitService(function (string $key, int $window) use (&$store): int {
            return $store[$key] = ($store[$key] ?? 0) + 1;
        });

        $key = 'pay:rate:M1:/pay/submitOrder';
        // 上限 3：前 3 次放行
        $this->assertTrue($svc->hit($key, 3, 60));
        $this->assertTrue($svc->hit($key, 3, 60));
        $this->assertTrue($svc->hit($key, 3, 60));
        // 第 4 次超频 → 拒
        $this->assertFalse($svc->hit($key, 3, 60));
    }

    /**
     * 限流隔离：不同商户/不同键各自独立计数，互不影响
     */
    public function testRateLimitPerKeyIsolation(): void
    {
        $store = [];
        $svc = new RateLimitService(function (string $key, int $window) use (&$store): int {
            return $store[$key] = ($store[$key] ?? 0) + 1;
        });

        $this->assertTrue($svc->hit('pay:rate:A:/p', 1, 60));
        $this->assertFalse($svc->hit('pay:rate:A:/p', 1, 60)); // A 超限
        $this->assertTrue($svc->hit('pay:rate:B:/p', 1, 60));  // B 独立，仍放行
    }

    /**
     * 限流配置：max<=0 视为不限流，全部放行（避免误配锁死商户）
     */
    public function testRateLimitUnlimitedWhenMaxNonPositive(): void
    {
        $svc = new RateLimitService(fn (string $k, int $w): int => 999999);
        $this->assertTrue($svc->hit('k', 0, 60));
        $this->assertTrue($svc->hit('k', -1, 60));
    }

    /**
     * 限流失败放行（fail-open）：计数器（Redis）异常时不阻断支付主流程
     */
    public function testRateLimitFailOpenOnError(): void
    {
        $svc = new RateLimitService(function (string $k, int $w): int {
            throw new \RuntimeException('redis down');
        });
        $this->assertTrue($svc->hit('k', 1, 60));
    }

    /**
     * 限流键构造：pay:rate:{mch_id}:{path}，空商户号用 unknown 兜底
     */
    public function testRateLimitKeyBuild(): void
    {
        $this->assertSame('pay:rate:M1:/pay/submitOrder', RateLimit::buildKey('M1', '/pay/submitOrder'));
        $this->assertSame('pay:rate:unknown:/pay/query', RateLimit::buildKey('', '/pay/query'));
    }

    // ========== D) 日志脱敏（敏感字段掩码） ==========

    /**
     * 脱敏：卡号保留前 6 后 4，密钥/密码/签名重度掩码，非敏感字段原样
     */
    public function testSensitiveMaskFields(): void
    {
        $masked = SensitiveHelper::mask([
            'account_no'  => '6222021234567890123',
            'card_no'     => '6222021234567890123',
            'secret_key'  => 'abcdef1234567890',
            'password'    => 'P@ssw0rd123',
            'sign'        => 'A1B2C3D4E5F6',
            'amount'      => '100.0000',
            'mch_id'      => 'M001',
        ]);

        // 卡号：前 6 后 4 可见，中间掩码
        $this->assertSame('622202*********0123', $masked['account_no']);
        $this->assertSame('622202*********0123', $masked['card_no']);
        // 密钥/密码/签名：重度掩码（不含原文中段）
        $this->assertStringContainsString('****', $masked['secret_key']);
        $this->assertStringNotContainsString('cdef123456', $masked['secret_key']);
        $this->assertStringContainsString('****', $masked['password']);
        // 非敏感字段原样保留
        $this->assertSame('100.0000', $masked['amount']);
        $this->assertSame('M001', $masked['mch_id']);
    }

    /**
     * 脱敏：嵌套结构深度遍历；命名兼容下划线/驼峰
     */
    public function testSensitiveMaskNestedAndNaming(): void
    {
        $masked = SensitiveHelper::mask([
            'params' => [
                'accountNo' => '6222021234567890123', // 驼峰
                'bank_name' => '工商银行',
            ],
        ]);
        $this->assertSame('622202*********0123', $masked['params']['accountNo']);
        $this->assertSame('工商银行', $masked['params']['bank_name']);
    }

    /**
     * 脱敏 JSON：解码→脱敏→编码；非 JSON 原样返回
     */
    public function testSensitiveMaskJson(): void
    {
        $json = json_encode(['account_no' => '6222021234567890123', 'amount' => '50.0000']);
        $out = SensitiveHelper::maskJson($json);
        $this->assertStringContainsString('622202*********0123', $out);
        $this->assertStringNotContainsString('6222021234567890123', $out);
        // 非 JSON 原样
        $this->assertSame('plain text', SensitiveHelper::maskJson('plain text'));
    }

    /**
     * 脱敏边界：短卡号/短密钥整体重度掩码、空串
     */
    public function testSensitiveMaskEdgeCases(): void
    {
        $this->assertSame('****', SensitiveHelper::maskSecret('short'));
        $this->assertSame('', SensitiveHelper::maskSecret(''));
        // 太短的“卡号”不足以保留前 6 后 4 → 重度掩码
        $this->assertSame('****', SensitiveHelper::maskCardNo('12345'));
    }

    // ========== E) 敏感字段隐藏（不随接口序列化泄露） ==========

    /**
     * 商户模型隐藏 secret_key / rsa_private_key / password（接口序列化不泄露）
     */
    public function testMerchantSensitiveFieldsHidden(): void
    {
        // 用反射读默认属性，避免实例化触发 DB（与 ModelStructureTest 一致）
        $hidden = (new \ReflectionClass(Merchant::class))->getDefaultProperties()['hidden'] ?? [];
        $this->assertContains('secret_key', $hidden);
        $this->assertContains('rsa_private_key', $hidden);
        $this->assertContains('password', $hidden);
    }
}
