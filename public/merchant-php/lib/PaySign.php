<?php
/**
 * 四方支付商户签名工具（与平台 SignService / GpayDome 规则一致）
 *
 * 1. 剔除 sign、sign_type
 * 2. ksort 升序，key=value& 拼接（空值也参与）
 * 3. 末尾追加 key={secret_key}
 * 4. MD5：strtoupper(md5(待签串))
 * 5. RSA：openssl_sign(SHA256) 后 base64
 */
final class PaySign
{
    public const SIGN_TYPE_MD5 = 1;
    public const SIGN_TYPE_RSA = 2;

    /**
     * 组装待签串
     */
    public static function buildSignString(array $params, string $secretKey): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);

        $str = '';
        foreach ($params as $key => $val) {
            if (is_array($val)) {
                $val = '';
            }
            if (is_bool($val)) {
                $val = $val ? '1' : '';
            }
            $str .= $key . '=' . ($val ?? '') . '&';
        }

        return $str . 'key=' . $secretKey;
    }

    /**
     * 生成签名
     */
    public static function makeSign(array $params, string $secretKey, int $signType, ?string $rsaPrivateKey = null): string
    {
        $signStr = self::buildSignString($params, $secretKey);

        if ($signType === self::SIGN_TYPE_RSA) {
            if ($rsaPrivateKey === null || trim($rsaPrivateKey) === '') {
                throw new RuntimeException('RSA 签名缺少商户私钥');
            }

            return self::rsaSign($signStr, $rsaPrivateKey);
        }

        return strtoupper(md5($signStr));
    }

    /**
     * 校验签名
     */
    public static function verify(array $params, string $secretKey, int $signType, string $sign, ?string $rsaPublicKey = null): bool
    {
        if ($sign === '') {
            return false;
        }

        $signStr = self::buildSignString($params, $secretKey);

        if ($signType === self::SIGN_TYPE_RSA) {
            if ($rsaPublicKey === null || trim($rsaPublicKey) === '') {
                return false;
            }

            return self::rsaVerify($signStr, $sign, $rsaPublicKey);
        }

        $expected = strtoupper(md5($signStr));

        return hash_equals($expected, strtoupper($sign));
    }

    private static function rsaSign(string $data, string $privateKey): string
    {
        $pem = self::wrapPrivateKey($privateKey);
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('商户 RSA 私钥无效');
        }

        $signature = '';
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('RSA 签名失败');
        }

        return base64_encode($signature);
    }

    private static function rsaVerify(string $data, string $sign, string $publicKey): bool
    {
        $pem = self::wrapPublicKey($publicKey);
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        $result = openssl_verify($data, base64_decode($sign, true) ?: '', $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    private static function wrapPrivateKey(string $key): string
    {
        $key = trim($key);
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        return "-----BEGIN RSA PRIVATE KEY-----\n"
            . wordwrap($key, 64, "\n", true)
            . "\n-----END RSA PRIVATE KEY-----";
    }

    private static function wrapPublicKey(string $key): string
    {
        $key = trim($key);
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . wordwrap($key, 64, "\n", true)
            . "\n-----END PUBLIC KEY-----";
    }
}
