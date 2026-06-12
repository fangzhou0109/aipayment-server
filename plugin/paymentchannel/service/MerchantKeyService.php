<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户密钥生成服务
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use RuntimeException;

/**
 * 商户密钥生成服务
 *
 * 集中生成商户对接所需的两类密钥，抽成无状态服务便于复用与单测：
 *  - MD5 secret_key：32 位十六进制随机串（对应商户表 secret_key）；
 *  - RSA 2048 密钥对：平台为该商户保管「平台私钥」（回调签名）+ 下发「平台公钥」给商户验签；
 *    商户上传自己的公钥用于平台验商户来签（rsa_public_key 字段另存）。
 */
class MerchantKeyService
{
    /**
     * 生成 MD5 签名密钥（32 位十六进制）
     *
     * 用 random_bytes 强随机源，避免可预测密钥被伪造签名。
     *
     * @return string 32 位小写十六进制字符串
     */
    public static function generateSecretKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 生成 RSA 2048 密钥对
     *
     * @return array{private:string,public:string} PEM 格式的私钥与公钥
     * @throws RuntimeException openssl 生成失败时
     */
    public static function generateRsaKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            throw new RuntimeException('MerchantKeyService: RSA 密钥对生成失败');
        }
        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);
        if ($details === false || empty($details['key'])) {
            throw new RuntimeException('MerchantKeyService: RSA 公钥提取失败');
        }
        return [
            'private' => $privateKey,
            'public'  => $details['key'],
        ];
    }

    /**
     * 从平台 RSA 私钥（商户表 rsa_private_key）提取对应公钥
     *
     * 商户收平台异步通知时用此公钥验签（与 reference GpayDome notify_url.php 一致），
     * 勿与 rsa_public_key（商户来签公钥）混淆。
     *
     * @param string $privateKey PEM 或裸 base64 私钥
     * @return string|null PEM 公钥；私钥无效时 null
     */
    public static function extractPublicKeyFromPrivate(string $privateKey): ?string
    {
        $privateKey = trim($privateKey);
        if ($privateKey === '') {
            return null;
        }

        $pem = str_contains($privateKey, 'BEGIN')
            ? $privateKey
            : "-----BEGIN RSA PRIVATE KEY-----\n"
                . wordwrap($privateKey, 64, "\n", true)
                . "\n-----END RSA PRIVATE KEY-----";
        $res = openssl_pkey_get_private($pem);
        if ($res === false) {
            return null;
        }

        $details = openssl_pkey_get_details($res);
        if ($details === false || empty($details['key'])) {
            return null;
        }

        return (string) $details['key'];
    }
}
