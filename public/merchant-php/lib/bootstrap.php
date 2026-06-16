<?php
/**
 * Demo 公共引导：类库、配置、工具函数
 */
declare(strict_types=1);

require_once __DIR__ . '/PaySign.php';
require_once __DIR__ . '/HttpClient.php';

/** @var array<string,mixed>|null $CONFIG */
$CONFIG = null;

/**
 * 加载 config.php（不存在返回 null，不中断）
 */
function demo_load_config(): ?array
{
    global $CONFIG;
    if ($CONFIG !== null) {
        return $CONFIG;
    }

    $configFile = dirname(__DIR__) . '/config.php';
    if (!is_file($configFile)) {
        return null;
    }

    $CONFIG = require $configFile;

    return $CONFIG;
}

/**
 * API 脚本必须已配置
 */
function demo_require_config(): array
{
    $config = demo_load_config();
    if ($config === null) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => '请复制 config.example.php 为 config.php'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $config;
}

function demo_config(string $key, mixed $default = null): mixed
{
    $config = demo_load_config();

    return $config[$key] ?? $default;
}

/** 支付类型 pay_type 映射 */
function demo_pay_type_map(): array
{
    return [
        1 => '支付宝 PC',
        2 => '支付宝 H5',
        3 => '微信 PC',
        4 => '微信 H5',
        5 => '银联快捷',
        6 => '银联扫码',
        7 => '其他',
    ];
}

function demo_mask_secret(string $value, int $show = 4): string
{
    $value = trim($value);
    if ($value === '') {
        return '（未配置）';
    }
    if (strlen($value) <= $show * 2) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, $show) . str_repeat('*', 8) . substr($value, -$show);
}

/**
 * 控制台展示用配置（脱敏）
 */
function demo_config_preview(): ?array
{
    $config = demo_load_config();
    if ($config === null) {
        return null;
    }

    return [
        'gateway_base'            => (string) ($config['gateway_base'] ?? ''),
        'mch_id'                  => (string) ($config['mch_id'] ?? ''),
        'secret_key_masked'       => demo_mask_secret((string) ($config['secret_key'] ?? '')),
        'sign_type'               => (int) ($config['sign_type'] ?? PaySign::SIGN_TYPE_MD5),
        'notify_url'              => (string) ($config['notify_url'] ?? ''),
        'return_url'              => (string) ($config['return_url'] ?? ''),
        'transfer_notify_url'     => (string) ($config['transfer_notify_url'] ?? ''),
        'default_pay_type'        => (int) ($config['default_pay_type'] ?? 3),
        'has_platform_rsa_public' => trim((string) ($config['platform_rsa_public_key'] ?? '')) !== '',
    ];
}

function demo_sign_params(array $params): array
{
    $signType = (int) demo_config('sign_type', PaySign::SIGN_TYPE_MD5);
    $secretKey = (string) demo_config('secret_key', '');
    $privateKey = (string) demo_config('rsa_private_key', '');

    $params['sign_type'] = $signType;
    $params['sign'] = PaySign::makeSign(
        $params,
        $secretKey,
        $signType,
        $signType === PaySign::SIGN_TYPE_RSA ? $privateKey : null
    );

    return $params;
}

function demo_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function demo_is_html_gateway_error(string $raw): bool
{
    $trim = ltrim($raw);

    return $trim !== '' && ($trim[0] === '<' || str_contains($trim, '<html'));
}

function demo_gateway_misconfig_hint(string $gatewayBase): string
{
    return '网关返回 HTML 404，请检查 config.php 的 gateway_base。'
        . '当前为 [' . $gatewayBase . ']，生产环境通常须带反代前缀，'
        . '如 https://api.starfusionx.com/prod/pay（可参考商户门户「API 对接」页网关地址）。';
}

/**
 * 构建 curl 示例
 */
function demo_build_curl_example(string $url, array $params): string
{
    $parts = [];
    foreach ($params as $key => $val) {
        $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $val);
    }

    return 'curl -X POST "' . $url . '" -H "Content-Type: application/x-www-form-urlencoded" -d "' . implode('&', $parts) . '"';
}
