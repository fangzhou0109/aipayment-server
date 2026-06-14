<?php
/**
 * 网关连通性探测 + 配置摘要
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

demo_require_config();

$gatewayBase = rtrim((string) demo_config('gateway_base'), '/');
$healthUrl = $gatewayBase . '/health';

$result = [
    'ok'              => true,
    'gateway_base'    => $gatewayBase,
    'health_url'      => $healthUrl,
    'config'          => demo_config_preview(),
    'server_ip'       => $_SERVER['SERVER_ADDR'] ?? '',
    'client_ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    'php_version'     => PHP_VERSION,
];

try {
    $raw = HttpClient::get($healthUrl);
    if (demo_is_html_gateway_error($raw)) {
        $result['ok'] = false;
        $result['health'] = [
            'reachable' => false,
            'message'   => demo_gateway_misconfig_hint($gatewayBase),
            'raw'       => substr($raw, 0, 300),
        ];
    } else {
        $decoded = json_decode($raw, true);
        $result['health'] = [
            'reachable' => true,
            'response'  => $decoded,
            'raw'       => $raw,
        ];
    }
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['health'] = [
        'reachable' => false,
        'message'   => $e->getMessage(),
    ];
}

demo_json_response($result, $result['ok'] ? 200 : 502);
