<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户签名/验签服务
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use InvalidArgumentException;

/**
 * 商户签名 / 验签服务
 *
 * 与参考项目 GpayDome 商户 Demo「字节级一致」的签名规则，保证存量商户无缝对接：
 *
 *  1) 组装待签串：剔除 sign / sign_type 字段后，按键名 ksort 升序，
 *     逐项以 "key=value&" 拼接，**空值字段也参与**，最后追加 "key={secret_key}"。
 *     例：client_ip=127.0.0.1&extra=&money=10000&...&key={secret_key}
 *
 *  2) MD5 模式（sign_type=1）：strtoupper(md5(待签串))。
 *
 *  3) RSA 模式（sign_type=2）：openssl_sign(待签串, OPENSSL_ALGO_SHA256) 后 base64；
 *     平台用「商户公钥」验下游来签、用「平台私钥」对回调下游签名。
 *
 *  4) 时间窗口：参数 time 为下单 Unix 秒级时间戳，与服务器时间偏差超过窗口（默认 3600s）拒单，
 *     防重放（与参考项目「时间误差超过 1 小时丢弃订单」一致）。
 *
 * 设计为无状态静态工具，便于在网关中间件、适配器、回调处理中复用，也便于纯单元测试。
 */
class SignService
{
    /** 签名类型：MD5 */
    public const SIGN_TYPE_MD5 = 1;
    /** 签名类型：RSA(SHA256) */
    public const SIGN_TYPE_RSA = 2;

    /** 时间窗口默认值（秒）：与参考项目一致为 1 小时 */
    public const DEFAULT_TIME_WINDOW = 3600;

    /** 组装待签串时需要剔除的字段（签名自身与签名类型不参与签名） */
    private const EXCLUDE_KEYS = ['sign', 'sign_type'];

    /**
     * 组装待签名原始字符串
     *
     * 规则与参考项目 sign_str() 完全一致：ksort 升序、"key=value&" 拼接（空值也拼）、
     * 末尾追加 "key={secret_key}"。
     *
     * @param array $params 业务参数（可含 sign/sign_type，将被自动剔除）
     * @param string $secretKey 商户密钥
     * @return string 待签名原始串
     */
    public static function buildSignString(array $params, string $secretKey): string
    {
        // 剔除签名相关字段，避免把上一轮签名也算进去
        foreach (self::EXCLUDE_KEYS as $key) {
            unset($params[$key]);
        }
        // 按键名升序排序（与商户端 ksort 保持一致，保证两端拼串顺序相同）
        ksort($params);

        $str = '';
        foreach ($params as $key => $val) {
            // 数组类型参数无法参与签名，按空串处理避免 "Array" 字面量污染
            if (is_array($val)) {
                $val = '';
            }
            // 布尔与 null 归一为字符串，保持与商户端 PHP 弱类型拼接行为一致
            if (is_bool($val)) {
                $val = $val ? '1' : '';
            }
            $str .= $key . '=' . ($val ?? '') . '&';
        }
        // 追加密钥段（注意：保留前面的 '&'，与参考项目一致）
        return $str . 'key=' . $secretKey;
    }

    /**
     * 生成签名
     *
     * 注意：无论 MD5 还是 RSA，待签串都用 $secretKey 拼接（与参考项目一致，RSA 模式
     * 也保留 key={secretKey} 段）；RSA 模式额外用 $rsaPrivateKey 对该串做 SHA256 签名。
     * secretKey 与 RSA 密钥是两个独立参数（对应商户表 secret_key 与 rsa_private_key）。
     *
     * @param array $params 业务参数
     * @param string $secretKey 商户 MD5 密钥（用于拼接待签串；RSA-only 商户可传空串）
     * @param int $signType 签名类型，SIGN_TYPE_MD5 | SIGN_TYPE_RSA
     * @param string|null $rsaPrivateKey RSA 私钥（PEM/裸 base64），仅 RSA 模式必填
     * @return string 签名值（MD5 为大写十六进制；RSA 为 base64）
     * @throws InvalidArgumentException 签名类型非法、或 RSA 缺私钥/私钥不可用时
     */
    public static function makeSign(array $params, string $secretKey, int $signType = self::SIGN_TYPE_MD5, ?string $rsaPrivateKey = null): string
    {
        $signStr = self::buildSignString($params, $secretKey);

        return match ($signType) {
            self::SIGN_TYPE_MD5 => strtoupper(md5($signStr)),
            self::SIGN_TYPE_RSA => self::rsaSign($signStr, (string) ($rsaPrivateKey ?? throw new InvalidArgumentException('SignService: RSA 签名缺少私钥'))),
            default => throw new InvalidArgumentException('SignService: 不支持的签名类型 [' . $signType . ']'),
        };
    }

    /**
     * 校验签名
     *
     * @param array $params 含 sign 字段的业务参数
     * @param string $secretKey 商户 MD5 密钥（用于拼接待签串；RSA-only 商户可传空串）
     * @param int $signType 签名类型
     * @param string|null $rsaPublicKey RSA 公钥（PEM/裸 base64），仅 RSA 模式必填
     * @param string|null $sign 待校验签名；为 null 时从 $params['sign'] 取
     * @return bool 通过返回 true
     */
    public static function verify(array $params, string $secretKey, int $signType = self::SIGN_TYPE_MD5, ?string $rsaPublicKey = null, ?string $sign = null): bool
    {
        $sign = $sign ?? (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        if ($signType === self::SIGN_TYPE_MD5) {
            // MD5：本地按相同规则重算，用 hash_equals 做恒定时间比较，防时序攻击
            $expect = self::makeSign($params, $secretKey, self::SIGN_TYPE_MD5);
            return hash_equals($expect, strtoupper($sign));
        }

        if ($signType === self::SIGN_TYPE_RSA) {
            // RSA：待签串用 secretKey 拼接，再用公钥验签
            if ($rsaPublicKey === null || $rsaPublicKey === '') {
                return false;
            }
            $signStr = self::buildSignString($params, $secretKey);
            return self::rsaVerify($signStr, $sign, $rsaPublicKey);
        }

        return false;
    }

    /**
     * 校验下单时间窗口，防重放
     *
     * @param int|string $time 下单 Unix 秒级时间戳
     * @param int $window 允许的偏差秒数，默认 DEFAULT_TIME_WINDOW
     * @param int|null $now 当前时间戳（测试可注入），默认 time()
     * @return bool 在窗口内返回 true
     */
    public static function checkTime(int|string $time, int $window = self::DEFAULT_TIME_WINDOW, ?int $now = null): bool
    {
        $time = (int) $time;
        if ($time <= 0) {
            return false;
        }
        $now = $now ?? time();
        // 绝对偏差不超过窗口即视为有效（兼容上下游时钟轻微不同步）
        return abs($now - $time) <= $window;
    }

    /**
     * RSA 私钥签名（SHA256）
     *
     * 兼容「裸 base64 私钥」与「带 PEM 头尾的私钥」两种格式（与参考项目一致：
     * 裸串自动补 PKCS#1 头尾并按 64 字符换行）。
     *
     * @param string $data 待签名串
     * @param string $privateKey 私钥（PEM 或裸 base64）
     * @return string base64 签名
     * @throws InvalidArgumentException 私钥不可用时
     */
    private static function rsaSign(string $data, string $privateKey): string
    {
        $pkey = openssl_pkey_get_private(self::normalizePrivateKey($privateKey));
        if ($pkey === false) {
            throw new InvalidArgumentException('SignService: RSA 私钥不可用');
        }
        $signature = '';
        openssl_sign($data, $signature, $pkey, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    /**
     * RSA 公钥验签（SHA256）
     *
     * @param string $data 待验签串
     * @param string $sign base64 签名
     * @param string $publicKey 公钥（PEM 或裸 base64）
     * @return bool
     */
    private static function rsaVerify(string $data, string $sign, string $publicKey): bool
    {
        $pkey = openssl_pkey_get_public(self::normalizePublicKey($publicKey));
        if ($pkey === false) {
            return false;
        }
        $decoded = base64_decode($sign, true);
        if ($decoded === false) {
            return false;
        }
        // openssl_verify 返回 1 表示通过
        return openssl_verify($data, $decoded, $pkey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 归一化私钥为 PEM 格式（裸 base64 自动补 PKCS#1 头尾）
     * @param string $key 私钥
     * @return string PEM 私钥
     */
    private static function normalizePrivateKey(string $key): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }
        return "-----BEGIN RSA PRIVATE KEY-----\n"
            . wordwrap($key, 64, "\n", true)
            . "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * 归一化公钥为 PEM 格式（裸 base64 自动补头尾）
     * @param string $key 公钥
     * @return string PEM 公钥
     */
    private static function normalizePublicKey(string $key): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }
        return "-----BEGIN PUBLIC KEY-----\n"
            . wordwrap($key, 64, "\n", true)
            . "\n-----END PUBLIC KEY-----";
    }
}
