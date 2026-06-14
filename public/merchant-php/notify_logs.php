<?php
/**
 * 读取 notify_url.php 写入的演示日志（最近 N 条）
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
$logFile = __DIR__ . '/logs/notify.log';

if (!is_file($logFile)) {
    demo_json_response(['ok' => true, 'items' => [], 'total' => 0]);
    exit;
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$items = [];

foreach (array_reverse($lines) as $line) {
    $jsonPos = strpos($line, '{');
    if ($jsonPos === false) {
        continue;
    }
    $header = substr($line, 0, $jsonPos);
    $payload = json_decode(substr($line, $jsonPos), true);
    if (!is_array($payload)) {
        continue;
    }

    $signOk = null;
    if (preg_match('/sign_ok=(\d)/', $header, $m)) {
        $signOk = $m[1] === '1';
    }

    $items[] = [
        'header'   => trim($header),
        'sign_ok'  => $signOk,
        'order_id' => (string) ($payload['order_id'] ?? ''),
        'order_no' => (string) ($payload['order_no'] ?? ''),
        'money'    => (string) ($payload['money'] ?? ''),
        'status'   => (string) ($payload['status'] ?? ''),
        'mch_id'   => (string) ($payload['mch_id'] ?? ''),
        'payload'  => $payload,
    ];

    if (count($items) >= $limit) {
        break;
    }
}

demo_json_response([
    'ok'    => true,
    'items' => $items,
    'total' => count($items),
    'file'  => 'logs/notify.log',
]);
