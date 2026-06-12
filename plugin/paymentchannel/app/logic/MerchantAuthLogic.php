<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户认证逻辑层（独立 JWT，/mapi 专用）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\middleware\IpWhitelist;
use plugin\paymentchannel\app\model\Merchant;
use plugin\saiadmin\app\logic\system\SystemAttachmentLogic;
use plugin\saiadmin\basic\think\BaseLogic;
use Tinywan\Jwt\JwtToken;

/**
 * 商户门户认证逻辑层
 *
 * 与平台后台账号体系**完全隔离**：商户门户签发的 JWT 在 extend 里标记 `plat=merchant`，
 * 平台后台标记 `plat=saiadmin`（见 saiadmin `SystemUserLogic::login`）。两者虽用同一 JWT 密钥，
 * 但中间件按 `plat` 字段判定来源——商户 token 无法访问 `/core/pay/*`，后台 token 无法访问 `/mapi/*`。
 *
 * 安全：
 *  - 登录失败信息统一为「登录名或密码错误」，不区分「不存在/密码错」以防用户名枚举；
 *  - 密码用 `password_verify` 校验（与 admin 录入时 `password_hash` 对应）；
 *  - 停用商户禁止登录。
 *
 * 可测试性：DB 访问（loadMerchantByLogin/loadMerchant）与 token 签发（generateToken）
 * 均抽为 protected 接缝，单测以子类重写脱离 DB / JWT。
 */
class MerchantAuthLogic extends BaseLogic
{
    /** 令牌来源标识：商户门户（与后台 saiadmin 区分，实现物理隔离） */
    public const PLAT = 'merchant';

    /**
     * 构造：注入商户模型
     */
    public function __construct()
    {
        $this->model = new Merchant();
    }

    /**
     * 商户登录：校验登录名/密码 → 签发独立商户 JWT
     *
     * @param string $loginName 商户门户登录名（sa_pay_merchant.login_name）
     * @param string $password 明文密码
     * @param string $clientIp 登录来源 IP（用于 IP 白名单校验，与网关 /pay/* 规则一致）
     * @return array { token: array, merchant: array } 令牌与商户基本信息
     * @throws PaymentException 参数为空 / 商户停用 / IP 不在白名单 / 登录名或密码错误
     */
    public function login(string $loginName, string $password, string $clientIp = ''): array
    {
        if ($loginName === '' || $password === '') {
            throw new PaymentException('登录名或密码不能为空');
        }

        $merchant = $this->loadMerchantByLogin($loginName);
        // 统一错误信息，避免暴露「登录名是否存在」
        if ($merchant === null) {
            throw new PaymentException('登录名或密码错误');
        }
        if ((int) ($merchant['status'] ?? 0) !== 1) {
            throw new PaymentException('商户已停用，请联系平台');
        }
        $hash = (string) ($merchant['password'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new PaymentException('登录名或密码错误');
        }

        // 门户登录与网关共用白名单策略（开启且已配置 IP 列表时校验来源 IP）
        self::assertIpAllowed($merchant, $clientIp);

        // 签发令牌：extend 携带商户身份 + plat=merchant（隔离关键）
        $token = $this->generateToken([
            'access_exp' => (int) config('plugin.saiadmin.saithink.access_exp', 8 * 3600),
            'id'         => (int) $merchant['id'],
            'mch_id'     => (string) $merchant['mch_id'],
            'login_name' => $loginName,
            'plat'       => self::PLAT,
        ]);

        return [
            'token'    => $token,
            'merchant' => [
                'id'     => (int) $merchant['id'],
                'mch_id' => (string) $merchant['mch_id'],
                'name'   => (string) ($merchant['name'] ?? ''),
                'avatar' => (string) ($merchant['avatar'] ?? ''),
            ],
        ];
    }

    /**
     * 读取当前登录商户的安全资料（供 /mapi/info）
     *
     * 仅返回商户可见字段，**绝不下发** secret_key / rsa_private_key / password。
     *
     * @param int $merchantId 商户ID（由 token 解析得到）
     * @return array|null
     */
    public function profile(int $merchantId): ?array
    {
        $m = $this->loadMerchant($merchantId);
        if ($m === null) {
            return null;
        }
        return [
            'id'                  => (int) $m['id'],
            'mch_id'              => (string) $m['mch_id'],
            'name'                => (string) ($m['name'] ?? ''),
            'avatar'              => (string) ($m['avatar'] ?? ''),
            'login_name'          => (string) ($m['login_name'] ?? ''),
            'balance'             => (string) ($m['balance'] ?? '0.0000'),
            'balance_freeze'      => (string) ($m['balance_freeze'] ?? '0.0000'),
            'rate'                => (string) ($m['rate'] ?? '0.0000'),
            'rate_transfer'       => (string) ($m['rate_transfer'] ?? '0.0000'),
            'single_min'          => (string) ($m['single_min'] ?? '0.0000'),
            'single_max'          => (string) ($m['single_max'] ?? '0.0000'),
            'ip_whitelist'        => (string) ($m['ip_whitelist'] ?? ''),
            'ip_whitelist_status' => (int) ($m['ip_whitelist_status'] ?? 2),
            'status'              => (int) ($m['status'] ?? 0),
        ];
    }

    /**
     * 修改商户门户登录密码（仅影响 login_name 登录，不影响 API secret_key）
     *
     * @param int $merchantId 商户 ID（token）
     * @param string $oldPassword 当前密码
     * @param string $newPassword 新密码
     * @throws PaymentException
     */
    public function modifyPassword(int $merchantId, string $oldPassword, string $newPassword): void
    {
        $oldPassword = trim($oldPassword);
        $newPassword = trim($newPassword);
        if ($oldPassword === '' || $newPassword === '') {
            throw new PaymentException('请填写完整密码');
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 64) {
            throw new PaymentException('新密码长度须在 6~64 位');
        }
        if ($oldPassword === $newPassword) {
            throw new PaymentException('新密码不能与当前密码相同');
        }

        $merchant = $this->loadMerchant($merchantId);
        if ($merchant === null) {
            throw new PaymentException('商户不存在');
        }

        $hash = (string) ($merchant['password'] ?? '');
        if ($hash === '' || !password_verify($oldPassword, $hash)) {
            throw new PaymentException('当前密码错误');
        }

        $this->savePassword($merchantId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    /**
     * 上传头像图片并写入商户表（门户个人中心）
     *
     * @return array{avatar:string, url:string}
     * @throws PaymentException
     */
    public function uploadAndSetAvatar(int $merchantId): array
    {
        if ($this->loadMerchant($merchantId) === null) {
            throw new PaymentException('商户不存在');
        }

        $uploaded = $this->uploadAvatarFile();
        $url = trim((string) ($uploaded['url'] ?? ''));
        if ($url === '') {
            throw new PaymentException('上传失败，未返回图片地址');
        }

        $this->updateAvatar($merchantId, $url);

        return ['avatar' => $url, 'url' => $url];
    }

    /**
     * 更新商户头像 URL（传空字符串表示清除）
     *
     * @return array{avatar:string}
     * @throws PaymentException
     */
    public function updateAvatar(int $merchantId, string $avatar): array
    {
        $avatar = trim($avatar);
        if ($avatar !== '' && !$this->isValidAvatarUrl($avatar)) {
            throw new PaymentException('头像地址无效');
        }

        if ($this->loadMerchant($merchantId) === null) {
            throw new PaymentException('商户不存在');
        }

        $this->saveAvatar($merchantId, $avatar);

        return ['avatar' => $avatar];
    }

    /**
     * 校验商户 IP 白名单（门户登录 / /mapi 鉴权 / 网关共用）
     *
     * ip_whitelist_status=1 时须配置白名单且 clientIp 在列表内（严格模式，与网关一致）。
     *
     * @param array $merchant 须含 ip_whitelist / ip_whitelist_status
     * @param string $clientIp 来源 IP
     * @throws PaymentException 不在白名单
     */
    public static function assertIpAllowed(array $merchant, string $clientIp): void
    {
        if ((int) ($merchant['ip_whitelist_status'] ?? 2) !== 1) {
            return;
        }
        $ip = trim($clientIp);
        if ($ip === '') {
            throw new PaymentException('无法识别登录来源 IP');
        }
        $whitelist = (string) ($merchant['ip_whitelist'] ?? '');
        if (!IpWhitelist::isAllowed($whitelist, $ip, true)) {
            if (IpWhitelist::parseIpList($whitelist) === []) {
                throw new PaymentException('已开启 IP 白名单但未配置允许的 IP，请联系平台运营');
            }
            throw new PaymentException('IP 不在白名单内：' . $ip);
        }
    }

    /**
     * 按商户 ID 加载白名单策略并校验（/mapi 鉴权中间件用）
     *
     * @throws PaymentException 商户不存在或 IP 不在白名单
     */
    public function assertIpAllowedByMerchantId(int $merchantId, string $clientIp): void
    {
        $merchant = $this->loadMerchantIpPolicy($merchantId);
        if ($merchant === null) {
            throw new PaymentException('商户不存在');
        }
        self::assertIpAllowed($merchant, $clientIp);
    }

    // ===== 接缝：DB 访问 / token 签发，默认走真实实现，单测可在子类重写 =====

    /**
     * 按登录名加载商户（含 password/status，用于校验）
     *
     * 注意：Merchant 模型隐藏了 password，`toArray()` 会丢失它，
     * 故用 `getData()` 取原始行（含隐藏字段）以便校验密码。
     *
     * @param string $loginName 登录名
     * @return array|null 不存在返回 null
     */
    protected function loadMerchantByLogin(string $loginName): ?array
    {
        // 显式拉取白名单字段，避免 ORM 缓存/隐藏字段导致登录时读不到策略
        $m = Merchant::where('login_name', $loginName)
            ->field('id,mch_id,name,avatar,password,status,login_name,ip_whitelist,ip_whitelist_status')
            ->find();

        return $m ? $m->getData() : null;
    }

    /**
     * 按ID加载商户
     *
     * @param int $id 商户ID
     * @return array|null
     */
    protected function loadMerchant(int $id): ?array
    {
        $m = Merchant::where('id', $id)->find();
        return $m ? $m->getData() : null;
    }

    /**
     * 仅加载 IP 白名单相关字段（中间件轻量查询）
     *
     * @param int $id 商户 ID
     * @return array|null
     */
    protected function loadMerchantIpPolicy(int $id): ?array
    {
        $m = Merchant::where('id', $id)->field('id,ip_whitelist,ip_whitelist_status')->find();

        return $m ? $m->getData() : null;
    }

    /**
     * 签发 JWT（封装 JwtToken，便于单测替换）
     *
     * @param array $extend 令牌扩展数据（含 id/plat 等）
     * @return array token 结构（access_token/refresh_token/expires_in...）
     */
    protected function generateToken(array $extend): array
    {
        return JwtToken::generateToken($extend);
    }

    /**
     * 持久化门户登录密码哈希
     */
    protected function savePassword(int $merchantId, string $hash): void
    {
        Merchant::where('id', $merchantId)->update(['password' => $hash]);
    }

    /**
     * 上传头像文件（复用 saiadmin 附件存储）
     *
     * @return array<string, mixed>
     */
    protected function uploadAvatarFile(): array
    {
        return (new SystemAttachmentLogic())->uploadBase('image');
    }

    /**
     * 持久化头像 URL
     */
    protected function saveAvatar(int $merchantId, string $avatar): void
    {
        Merchant::where('id', $merchantId)->update(['avatar' => $avatar !== '' ? $avatar : null]);
    }

    /**
     * 头像 URL 基本校验（长度 + 协议）
     */
    protected function isValidAvatarUrl(string $avatar): bool
    {
        if (strlen($avatar) > 500) {
            return false;
        }

        return (bool) preg_match('#^https?://#i', $avatar);
    }
}
