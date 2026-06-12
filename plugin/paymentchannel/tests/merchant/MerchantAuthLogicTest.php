<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户认证测试（登录 / token / 越权隔离 / 密码哈希，脱离 DB/JWT）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\merchant;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\MerchantAuthLogic;
use plugin\paymentchannel\app\middleware\MerchantAuth;

/**
 * 可测试的商户认证逻辑：内存替代 DB、token 签发，脱离真实库与 JWT。
 */
class TestableMerchantAuthLogic extends MerchantAuthLogic
{
    /** 模拟商户表：login_name => row（含 password 哈希/status） */
    public array $merchants = [];
    /** 模拟商户表：id => row */
    public array $byId = [];
    /** 捕获签发 token 时的 extend，用于断言 plat=merchant */
    public array $lastExtend = [];
    /** 是否触发过改密 */
    public bool $passwordSaved = false;

    public function __construct()
    {
        // 不调用父构造（避免实例化模型）
    }

    protected function loadMerchantByLogin(string $loginName): ?array
    {
        return $this->merchants[$loginName] ?? null;
    }

    protected function loadMerchant(int $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    protected function loadMerchantIpPolicy(int $id): ?array
    {
        $m = $this->byId[$id] ?? null;
        if ($m === null) {
            return null;
        }

        return [
            'id'                  => (int) $m['id'],
            'ip_whitelist'        => (string) ($m['ip_whitelist'] ?? ''),
            'ip_whitelist_status' => (int) ($m['ip_whitelist_status'] ?? 2),
        ];
    }

    protected function generateToken(array $extend): array
    {
        $this->lastExtend = $extend;
        // 返回假 token 结构（不触发真实 JWT 配置）
        return ['token_type' => 'Bearer', 'access_token' => 'FAKE.' . ($extend['plat'] ?? '') . '.' . ($extend['id'] ?? 0)];
    }

    protected function savePassword(int $merchantId, string $hash): void
    {
        $this->passwordSaved = true;
        if (isset($this->byId[$merchantId])) {
            $this->byId[$merchantId]['password'] = $hash;
            $loginName = (string) ($this->byId[$merchantId]['login_name'] ?? '');
            if ($loginName !== '' && isset($this->merchants[$loginName])) {
                $this->merchants[$loginName]['password'] = $hash;
            }
        }
    }

    protected function uploadAvatarFile(): array
    {
        return ['url' => 'https://cdn.example.com/merchant/avatar.png'];
    }

    protected function saveAvatar(int $merchantId, string $avatar): void
    {
        if (isset($this->byId[$merchantId])) {
            $this->byId[$merchantId]['avatar'] = $avatar;
            $loginName = (string) ($this->byId[$merchantId]['login_name'] ?? '');
            if ($loginName !== '' && isset($this->merchants[$loginName])) {
                $this->merchants[$loginName]['avatar'] = $avatar;
            }
        }
    }
}

/**
 * 商户门户认证测试
 *
 * 覆盖 README 5.1 要求：登录、token 校验、越权隔离。脱离 DB / JWT / Redis。
 */
class MerchantAuthLogicTest extends TestCase
{
    /** 构造一个登录名为 m001、密码 secret123、正常状态的商户 */
    private function make(): TestableMerchantAuthLogic
    {
        $logic = new TestableMerchantAuthLogic();
        $row = [
            'id'                  => 7,
            'mch_id'              => 'M007',
            'name'                => '测试商户',
            'login_name'          => 'm001',
            'password'            => password_hash('secret123', PASSWORD_DEFAULT),
            'status'              => 1,
            'balance'             => '100.0000',
            'balance_freeze'      => '0.0000',
            'rate'                => '2.6000',
            'rate_transfer'       => '1.0000',
            'single_min'          => '0.0000',
            'single_max'          => '0.0000',
            'ip_whitelist'        => '',
            'ip_whitelist_status' => 2,
            'secret_key'          => 'sai_secret',
        ];
        $logic->merchants['m001'] = $row;
        $logic->byId[7] = $row;
        return $logic;
    }

    // ===== 登录 =====

    /**
     * 登录成功：返回 token + 商户信息，token extend 标记 plat=merchant
     */
    public function testLoginSuccess(): void
    {
        $logic = $this->make();
        $result = $logic->login('m001', 'secret123');

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('merchant', $result);
        $this->assertSame(7, $result['merchant']['id']);
        $this->assertSame('M007', $result['merchant']['mch_id']);
        // token extend 必须带 plat=merchant（隔离关键）与商户身份
        $this->assertSame(MerchantAuthLogic::PLAT, $logic->lastExtend['plat']);
        $this->assertSame(7, $logic->lastExtend['id']);
        $this->assertSame('M007', $logic->lastExtend['mch_id']);
    }

    /**
     * 密码错误 → 拒绝（统一错误信息防枚举）
     */
    public function testLoginWrongPassword(): void
    {
        $logic = $this->make();
        try {
            $logic->login('m001', 'wrong');
            $this->fail('密码错误应抛异常');
        } catch (PaymentException $e) {
            $this->assertSame('登录名或密码错误', $e->getMessage());
        }
    }

    /**
     * 登录名不存在 → 拒绝（与密码错误同样的提示，防用户名枚举）
     */
    public function testLoginUnknownAccount(): void
    {
        $logic = $this->make();
        try {
            $logic->login('nobody', 'secret123');
            $this->fail('登录名不存在应抛异常');
        } catch (PaymentException $e) {
            $this->assertSame('登录名或密码错误', $e->getMessage());
        }
    }

    /**
     * 停用商户禁止登录
     */
    public function testLoginDisabledMerchant(): void
    {
        $logic = $this->make();
        $logic->merchants['m001']['status'] = 2;
        $this->expectException(PaymentException::class);
        $logic->login('m001', 'secret123');
    }

    /**
     * 空凭证 → 拒绝
     */
    public function testLoginEmptyCredentials(): void
    {
        $logic = $this->make();
        $this->expectException(PaymentException::class);
        $logic->login('', '');
    }

    /**
     * 未设置密码的商户无法登录（password 为空哈希）
     */
    public function testLoginNoPasswordSet(): void
    {
        $logic = $this->make();
        $logic->merchants['m001']['password'] = '';
        $this->expectException(PaymentException::class);
        $logic->login('m001', 'secret123');
    }

    /**
     * 开启 IP 白名单且来源 IP 不在列表 → 拒绝登录
     */
    public function testLoginRejectsIpNotInWhitelist(): void
    {
        $logic = $this->make();
        $logic->merchants['m001']['ip_whitelist_status'] = 1;
        $logic->merchants['m001']['ip_whitelist'] = '1.2.3.4';
        $logic->byId[7] = $logic->merchants['m001'];

        try {
            $logic->login('m001', 'secret123', '9.9.9.9');
            $this->fail('非白名单 IP 应拒绝登录');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('IP 不在白名单内', $e->getMessage());
            $this->assertStringContainsString('9.9.9.9', $e->getMessage());
        }
    }

    /**
     * 开启 IP 白名单且来源 IP 命中 → 允许登录
     */
    public function testLoginAllowsIpInWhitelist(): void
    {
        $logic = $this->make();
        $logic->merchants['m001']['ip_whitelist_status'] = 1;
        $logic->merchants['m001']['ip_whitelist'] = '1.2.3.4, 5.6.7.8';
        $logic->byId[7] = $logic->merchants['m001'];

        $result = $logic->login('m001', 'secret123', '5.6.7.8');
        $this->assertSame('M007', $result['merchant']['mch_id']);
    }

    /**
     * 未开启 IP 白名单 → 任意 IP 可登录
     */
    public function testLoginAllowsWhenWhitelistDisabled(): void
    {
        $logic = $this->make();
        $logic->merchants['m001']['ip_whitelist_status'] = 2;
        $logic->merchants['m001']['ip_whitelist'] = '1.2.3.4';
        $logic->byId[7] = $logic->merchants['m001'];

        $result = $logic->login('m001', 'secret123', '9.9.9.9');
        $this->assertSame('M007', $result['merchant']['mch_id']);
    }

    /**
     * 开启白名单但未配置 IP 列表 → 拒绝登录
     */
    public function testLoginRejectsWhenWhitelistEnabledButEmpty(): void
    {
        $logic = $this->make();
        $logic->merchants['m001']['ip_whitelist_status'] = 1;
        $logic->merchants['m001']['ip_whitelist'] = '';
        $logic->byId[7] = $logic->merchants['m001'];

        try {
            $logic->login('m001', 'secret123', '1.2.3.4');
            $this->fail('白名单开启但为空应拒绝登录');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('未配置允许的 IP', $e->getMessage());
        }
    }

    /**
     * 已登录鉴权：按商户 ID 校验白名单
     */
    public function testAssertIpAllowedByMerchantId(): void
    {
        $logic = $this->make();
        $logic->byId[7]['ip_whitelist_status'] = 1;
        $logic->byId[7]['ip_whitelist'] = '10.0.0.1';
        $logic->merchants['m001'] = $logic->byId[7];

        $this->expectException(PaymentException::class);
        $logic->assertIpAllowedByMerchantId(7, '192.168.1.1');
    }

    // ===== 资料（profile）=====

    /**
     * profile 返回安全字段，且**不含** password / secret_key
     */
    public function testProfileHidesSensitive(): void
    {
        $logic = $this->make();
        $data = $logic->profile(7);

        $this->assertNotNull($data);
        $this->assertSame('M007', $data['mch_id']);
        $this->assertSame('100.0000', $data['balance']);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('secret_key', $data);
        $this->assertArrayNotHasKey('rsa_private_key', $data);
    }

    /**
     * profile 商户不存在返回 null
     */
    public function testProfileMissingReturnsNull(): void
    {
        $logic = $this->make();
        $this->assertNull($logic->profile(999));
    }

    /**
     * 修改门户登录密码：旧密码正确后可使用新密码登录
     */
    public function testModifyPasswordSuccess(): void
    {
        $logic = $this->make();
        $logic->modifyPassword(7, 'secret123', 'newpass9');
        $this->assertTrue($logic->passwordSaved);

        try {
            $logic->login('m001', 'secret123');
            $this->fail('旧密码应无法登录');
        } catch (PaymentException $e) {
            $this->assertSame('登录名或密码错误', $e->getMessage());
        }

        $result = $logic->login('m001', 'newpass9');
        $this->assertSame('M007', $result['merchant']['mch_id']);
    }

    /**
     * 当前密码错误时拒绝改密
     */
    public function testModifyPasswordRejectsWrongOld(): void
    {
        $logic = $this->make();
        $this->expectException(PaymentException::class);
        $logic->modifyPassword(7, 'wrong', 'newpass9');
    }

    /**
     * 上传并保存头像
     */
    public function testUploadAndSetAvatar(): void
    {
        $logic = $this->make();
        $data = $logic->uploadAndSetAvatar(7);

        $this->assertSame('https://cdn.example.com/merchant/avatar.png', $data['avatar']);
        $profile = $logic->profile(7);
        $this->assertSame('https://cdn.example.com/merchant/avatar.png', $profile['avatar']);
    }

    /**
     * 清除头像
     */
    public function testUpdateAvatarClear(): void
    {
        $logic = $this->make();
        $logic->updateAvatar(7, 'https://cdn.example.com/old.png');
        $data = $logic->updateAvatar(7, '');

        $this->assertSame('', $data['avatar']);
        $this->assertSame('', $logic->profile(7)['avatar']);
    }

    // ===== 越权隔离（MerchantAuth 中间件 plat 判定）=====

    /**
     * 商户 token（plat=merchant）通过
     */
    public function testIsMerchantTokenAcceptsMerchant(): void
    {
        $this->assertTrue(MerchantAuth::isMerchantToken(['plat' => 'merchant', 'id' => 7]));
    }

    /**
     * 平台后台 token（plat=saiadmin）被拒（核心越权隔离）
     */
    public function testIsMerchantTokenRejectsSaiadmin(): void
    {
        $this->assertFalse(MerchantAuth::isMerchantToken(['plat' => 'saiadmin', 'id' => 1]));
    }

    /**
     * 缺 plat 字段被拒
     */
    public function testIsMerchantTokenRejectsMissingPlat(): void
    {
        $this->assertFalse(MerchantAuth::isMerchantToken(['id' => 7]));
    }
}
