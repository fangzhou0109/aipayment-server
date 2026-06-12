<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：敏感信息脱敏工具（日志脱敏 / OWASP A09 安全日志）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

/**
 * 敏感信息脱敏工具（纯函数）
 *
 * 用于「日志脱敏」——上游交互日志（sa_pay_channel_log）、排障日志等在落库 / 输出前，
 * 把银行卡号、密钥、密码、签名等敏感字段做掩码，避免明文持久化造成信息泄露
 * （对应 OWASP A09:2021 安全日志记录与监控失败、A02 加密失败的纵深防御）。
 *
 * 全部为纯静态方法、无副作用，便于单测；按「字段名归一化」（小写 + 去下划线）匹配，
 * 兼容 `card_no`/`cardNo`/`accountNo`/`account_no` 等多种命名。
 */
class SensitiveHelper
{
    /**
     * 卡号类字段（归一化后）：掩码保留前 6 后 4，便于核对又不泄露完整卡号。
     * @var string[]
     */
    private const CARD_KEYS = ['cardno', 'accountno', 'bankcardno', 'bankcard'];

    /**
     * 密钥/口令类字段（归一化后）：重度掩码（仅留少量首尾字符）。
     * @var string[]
     */
    private const SECRET_KEYS = [
        'secretkey', 'password', 'pwd', 'upstreamkey', 'upstreamprivatekey',
        'rsaprivatekey', 'privatekey', 'apikey', 'sign', 'token',
    ];

    /**
     * 归一化字段名：转小写并去掉下划线，统一 `card_no`/`cardNo`/`CARD_NO` 等写法。
     *
     * @param string $key 原字段名
     * @return string 归一化后的字段名
     */
    private static function normalizeKey(string $key): string
    {
        return str_replace('_', '', strtolower($key));
    }

    /**
     * 递归脱敏数组：命中卡号/密钥字段则掩码其值，其余原样保留，嵌套数组深度遍历。
     *
     * @param array $data 原始数据
     * @return array 脱敏后的新数组（不改原数组）
     */
    public static function mask(array $data): array
    {
        $masked = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // 嵌套结构深度脱敏
                $masked[$key] = self::mask($value);
                continue;
            }

            $norm = is_string($key) ? self::normalizeKey($key) : '';
            if (in_array($norm, self::CARD_KEYS, true)) {
                $masked[$key] = self::maskCardNo((string) $value);
            } elseif (in_array($norm, self::SECRET_KEYS, true)) {
                $masked[$key] = self::maskSecret((string) $value);
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }

    /**
     * 脱敏 JSON 串：解码为数组后脱敏再编码；非 JSON / 非数组则原样返回。
     *
     * 供日志持久化点（channelLog）统一调用，覆盖所有适配器的请求/响应落库。
     *
     * @param string $json 原始 JSON 串
     * @return string 脱敏后的 JSON 串（解析失败原样返回）
     */
    public static function maskJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }
        return json_encode(self::mask($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }

    /**
     * 卡号掩码：保留前 6 后 4，中间以 `*` 覆盖（不足 11 位时整体重度掩码）。
     *
     * @param string $cardNo 卡号
     * @return string 掩码后的卡号
     */
    public static function maskCardNo(string $cardNo): string
    {
        $digits = preg_replace('/\D/', '', $cardNo);
        $len = strlen((string) $digits);
        if ($len < 11) {
            // 太短无法安全保留前 6 后 4，整体重度掩码
            return self::maskSecret($cardNo);
        }
        return substr($digits, 0, 6) . str_repeat('*', $len - 10) . substr($digits, -4);
    }

    /**
     * 密钥/口令掩码：保留首尾各 2 位（长度 < 6 则整体掩码），中间固定 `****`。
     *
     * @param string $secret 密钥/口令
     * @return string 掩码后的串
     */
    public static function maskSecret(string $secret): string
    {
        $len = strlen($secret);
        if ($len === 0) {
            return '';
        }
        if ($len <= 6) {
            return '****';
        }
        return substr($secret, 0, 2) . '****' . substr($secret, -2);
    }
}
