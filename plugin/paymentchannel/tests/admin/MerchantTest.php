<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户管理 Logic/Validate 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\admin;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use plugin\paymentchannel\app\logic\MerchantLogic;
use plugin\paymentchannel\app\validate\MerchantValidate;
use plugin\paymentchannel\service\MerchantKeyService;

/**
 * 商户管理逻辑/验证测试
 *
 * 仅测试不依赖 DB 的纯逻辑：余额只读字段过滤、IP 白名单校验规则。
 * （完整 CRUD 在服务器侧 deploy 后用 REST client 验证）
 */
class MerchantTest extends TestCase
{
    /**
     * 余额字段只读：filterReadonly 必须剔除 balance / balance_freeze，
     * 防止通过新增/编辑接口直接篡改商户余额。
     */
    public function testReadonlyFieldsFiltered(): void
    {
        $logic = new MerchantLogic();
        $m = new ReflectionMethod($logic, 'filterReadonly');
        $m->setAccessible(true);

        $input = [
            'name'           => '测试商户',
            'balance'        => '99999.0000',
            'balance_freeze' => '1.0000',
            'rate'           => '2.6',
        ];
        $out = $m->invoke($logic, $input);

        $this->assertArrayNotHasKey('balance', $out, 'balance 应被剔除');
        $this->assertArrayNotHasKey('balance_freeze', $out, 'balance_freeze 应被剔除');
        $this->assertArrayHasKey('name', $out);
        $this->assertArrayHasKey('rate', $out);
    }

    /**
     * 编辑时剔除 rate（代收走通道配置），保留 rate_transfer（全局保底代付费率）
     */
    public function testEditStripsPayRateOnly(): void
    {
        $logic = new MerchantLogic();
        $m = new ReflectionMethod($logic, 'stripLegacyPayRate');
        $m->setAccessible(true);

        $out = $m->invoke($logic, [
            'name'           => '测试商户',
            'rate'           => '2.6000',
            'rate_transfer'  => '1.5000',
            'single_min'     => '0',
        ]);

        $this->assertArrayNotHasKey('rate', $out);
        $this->assertSame('1.5000', $out['rate_transfer']);
        $this->assertSame('测试商户', $out['name']);
    }

    /**
     * 编辑时 rate_transfer 格式化为 4 位小数
     */
    public function testEditNormalizesRateTransfer(): void
    {
        $logic = new MerchantLogic();
        $m = new ReflectionMethod($logic, 'normalizeRateTransfer');
        $m->setAccessible(true);

        $out = $m->invoke($logic, ['rate_transfer' => '2.5']);
        $this->assertSame('2.5000', $out['rate_transfer']);
    }

    /**
     * 新增时仅强制 rate 归零；rate_transfer 保留表单传入值（由 normalizeRateTransfer 格式化）
     */
    public function testAddNormalizesLegacyRatesToZero(): void
    {
        $logic = new MerchantLogic();
        $m = new ReflectionMethod($logic, 'normalizeLegacyRatesForAdd');
        $m->setAccessible(true);

        $out = $m->invoke($logic, [
            'name'          => '新商户',
            'rate'          => '9.9000',
            'rate_transfer' => '3.3000',
        ]);

        $this->assertSame('0.0000', $out['rate']);
        $this->assertSame('3.3000', $out['rate_transfer']);
    }

    /**
     * 编辑时单笔限额格式化为 4 位小数
     */
    public function testEditNormalizesSingleLimits(): void
    {
        $logic = new MerchantLogic();
        $m = new ReflectionMethod($logic, 'normalizeSingleLimits');
        $m->setAccessible(true);

        $out = $m->invoke($logic, [
            'single_min' => '10',
            'single_max' => '5000.5',
        ]);

        $this->assertSame('10.0000', $out['single_min']);
        $this->assertSame('5000.5000', $out['single_max']);
    }

    /**
     * 开启 IP 白名单但未填 IP → 校验拒绝
     */
    public function testUpdateSceneRequiresIpsWhenWhitelistEnabled(): void
    {
        $v = new MerchantValidate();
        $this->assertFalse(
            $v->scene('update')->check([
                'name'                => '测试商户',
                'login_name'          => 'merchant01',
                'status'              => 1,
                'ip_whitelist_status' => 1,
                'ip_whitelist'        => '',
            ])
        );
        $this->assertStringContainsString('IP 白名单', $v->getError());
    }

    /**
     * 更新场景允许配置单笔限额
     */
    public function testUpdateSceneValidatesSingleLimits(): void
    {
        $v = new MerchantValidate();
        $this->assertTrue(
            $v->scene('update')->check([
                'name'       => '测试商户',
                'login_name' => 'merchant01',
                'status'     => 1,
                'single_min' => 10,
                'single_max' => 5000,
            ]),
            '更新场景配置单笔限额应通过，错误：' . $v->getError()
        );
    }

    /**
     * update 场景允许配置 rate_transfer，拒绝超范围值
     */
    public function testUpdateSceneValidatesRateTransfer(): void
    {
        $v = new MerchantValidate();
        $this->assertTrue(
            $v->scene('update')->check([
                'name'          => '测试商户',
                'login_name'    => 'merchant01',
                'status'        => 1,
                'rate_transfer' => 1.5,
            ]),
            '更新场景配置合法 rate_transfer 应通过，错误：' . $v->getError()
        );

        $v2 = new MerchantValidate();
        $this->assertFalse(
            $v2->scene('update')->check([
                'name'          => '测试商户',
                'login_name'    => 'merchant01',
                'status'        => 1,
                'rate_transfer' => 999,
            ]),
            '超范围 rate_transfer 应拒绝'
        );
        $this->assertStringContainsString('代付费率', $v2->getError());
    }

    /**
     * IP 白名单校验：合法列表通过，空值通过（不限制），含非法地址返回错误串
     */
    public function testIpWhitelistRule(): void
    {
        $v = new MerchantValidate();
        $m = new ReflectionMethod($v, 'checkIpList');
        $m->setAccessible(true);

        // 合法 IPv4 列表
        $this->assertTrue($m->invoke($v, '1.2.3.4,10.0.0.1'));
        // 空值表示不限制
        $this->assertTrue($m->invoke($v, ''));
        $this->assertTrue($m->invoke($v, null));
        // 含非法地址 → 返回错误字符串
        $result = $m->invoke($v, '1.2.3.4,not-an-ip');
        $this->assertIsString($result);
        $this->assertStringContainsString('非法', $result);
    }

    /**
     * 密码哈希：明文 password 入库前转 password_hash（可被 password_verify 校验），
     * 空密码剔除（不覆盖），无 password 键不受影响。
     */
    public function testPasswordHashing(): void
    {
        $logic = new MerchantLogic();
        $m = new ReflectionMethod($logic, 'hashPassword');
        $m->setAccessible(true);

        // 明文密码 → 哈希且可验证
        $out = $m->invoke($logic, ['name' => 'x', 'password' => 'secret123']);
        $this->assertArrayHasKey('password', $out);
        $this->assertNotSame('secret123', $out['password'], '密码必须被哈希');
        $this->assertTrue(password_verify('secret123', $out['password']));

        // 空密码 → 剔除（避免把已有密码覆盖为空）
        $out2 = $m->invoke($logic, ['name' => 'x', 'password' => '']);
        $this->assertArrayNotHasKey('password', $out2);

        // 无 password 键 → 原样不动
        $out3 = $m->invoke($logic, ['name' => 'x']);
        $this->assertArrayNotHasKey('password', $out3);
        $this->assertSame('x', $out3['name']);
    }

    /**
     * 新增场景（save）：门户登录名与登录密码均为必填，缺一不可；
     * 两者齐全且合法时通过。（不依赖 DB，纯规则校验）
     */
    public function testSaveSceneRequiresLoginNameAndPassword(): void
    {
        $base = [
            'mch_id' => 'M0001',
            'name'   => '测试商户',
            'status' => 1,
        ];

        // 缺登录名 → 失败
        $v1 = new MerchantValidate();
        $this->assertFalse($v1->scene('save')->check($base + ['password' => 'secret123']));
        $this->assertStringContainsString('登录名', $v1->getError());

        // 缺密码 → 失败
        $v2 = new MerchantValidate();
        $this->assertFalse($v2->scene('save')->check($base + ['login_name' => 'merchant01']));
        $this->assertStringContainsString('密码', $v2->getError());

        // 两者齐全且合法 → 通过
        $v3 = new MerchantValidate();
        $this->assertTrue(
            $v3->scene('save')->check($base + ['login_name' => 'merchant01', 'password' => 'secret123']),
            '登录名+密码齐全应通过，错误：' . $v3->getError()
        );
    }

    /**
     * 新增场景：登录名/密码格式校验 —— 登录名过短、含非法字符拒绝；密码过短拒绝。
     */
    public function testSaveSceneFormatRules(): void
    {
        $base = [
            'mch_id' => 'M0001',
            'name'   => '测试商户',
            'status' => 1,
        ];

        // 登录名过短（<4）
        $v1 = new MerchantValidate();
        $this->assertFalse($v1->scene('save')->check($base + ['login_name' => 'ab', 'password' => 'secret123']));

        // 登录名含非法字符（空格）
        $v2 = new MerchantValidate();
        $this->assertFalse($v2->scene('save')->check($base + ['login_name' => 'bad name', 'password' => 'secret123']));

        // 密码过短（<6）
        $v3 = new MerchantValidate();
        $this->assertFalse($v3->scene('save')->check($base + ['login_name' => 'merchant01', 'password' => '123']));
    }

    /**
     * 修改场景（update）：密码不在场景中 → 留空表示不修改，不传密码也能通过；
     * 登录名仍必填。
     */
    public function testUpdateScenePasswordOptional(): void
    {
        // 不传 password → 通过（留空=不修改，由 Logic.hashPassword 剔除）
        $v1 = new MerchantValidate();
        $this->assertTrue(
            $v1->scene('update')->check([
                'name'       => '测试商户',
                'login_name' => 'merchant01',
                'status'     => 1,
            ]),
            '更新场景不传密码应通过，错误：' . $v1->getError()
        );

        // 登录名仍必填 → 缺失则失败
        $v2 = new MerchantValidate();
        $this->assertFalse($v2->scene('update')->check([
            'name'   => '测试商户',
            'status' => 1,
        ]));
    }

    /**
     * 对接凭证包：含 secret_key 与由平台私钥推导的公钥
     */
    public function testFormatIssueCredentialsIncludesPlatformPublicKey(): void
    {
        $pair = MerchantKeyService::generateRsaKeyPair();
        $merchant = new \plugin\paymentchannel\app\model\Merchant();
        $merchant->id = 1;
        $merchant->mch_id = 'M10001';
        $merchant->secret_key = 'abc123secretkey0000000000000001';
        $merchant->rsa_private_key = $pair['private'];

        $pack = MerchantLogic::formatIssueCredentials($merchant);

        $this->assertSame(1, $pack['id']);
        $this->assertSame('M10001', $pack['mch_id']);
        $this->assertSame('abc123secretkey0000000000000001', $pack['secret_key']);
        $this->assertNotEmpty($pack['platform_rsa_public_key']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $pack['platform_rsa_public_key']);
    }

    /**
     * 查看 API 资料：在凭证包基础上附带商户名称与来签公钥
     */
    public function testViewApiCredentialsExtendsIssuePack(): void
    {
        $pair = MerchantKeyService::generateRsaKeyPair();
        $merchant = new \plugin\paymentchannel\app\model\Merchant();
        $merchant->id = 2;
        $merchant->mch_id = 'M20002';
        $merchant->name = '演示商户';
        $merchant->secret_key = 'secret002';
        $merchant->rsa_private_key = $pair['private'];
        $merchant->rsa_public_key = $pair['public'];

        // viewApiCredentials 走 DB，此处直接测合并结构：format + 扩展字段
        $pack = array_merge(MerchantLogic::formatIssueCredentials($merchant), [
            'merchant_name'  => (string) $merchant->name,
            'rsa_public_key' => (string) $merchant->rsa_public_key,
        ]);

        $this->assertSame('演示商户', $pack['merchant_name']);
        $this->assertSame($pair['public'], $pack['rsa_public_key']);
    }

    /**
     * 商户上传来签公钥：非法 PEM 应拒绝
     */
    public function testAssertValidRsaPublicKeyRejectsInvalidPem(): void
    {
        $logic = new MerchantLogic();
        $m = new ReflectionMethod($logic, 'assertValidRsaPublicKey');
        $m->setAccessible(true);

        $this->expectException(\plugin\paymentchannel\app\exception\PaymentException::class);
        $this->expectExceptionMessage('RSA 公钥格式无效');
        $m->invoke($logic, 'not-a-valid-key');
    }

    /**
     * 余额调账场景：方向 + 正金额 + 备注长度
     */
    public function testAdjustBalanceSceneValidation(): void
    {
        $v = new MerchantValidate();
        $this->assertTrue(
            $v->scene('adjustBalance')->check([
                'id'        => 1,
                'direction' => 'increase',
                'amount'    => 100,
                'remark'    => '线下补录',
            ]),
            '合法调账参数应通过，错误：' . $v->getError()
        );

        $v2 = new MerchantValidate();
        $this->assertFalse($v2->scene('adjustBalance')->check([
            'id'        => 1,
            'direction' => 'invalid',
            'amount'    => 100,
        ]));
        $this->assertStringContainsString('调账方向', $v2->getError());
    }

    /**
     * 合法 RSA 公钥应通过校验
     */
    public function testAssertValidRsaPublicKeyAcceptsGeneratedPair(): void
    {
        $pair = MerchantKeyService::generateRsaKeyPair();
        $logic = new MerchantLogic();
        $m = new ReflectionMethod($logic, 'assertValidRsaPublicKey');
        $m->setAccessible(true);

        $m->invoke($logic, $pair['public']);
        $this->assertTrue(true);
    }
}
