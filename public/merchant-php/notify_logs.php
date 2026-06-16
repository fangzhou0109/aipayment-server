<?php
/**
 * 读取 notify_url.php / transfer_notify_url.php 写入的演示日志（最近 N 条）
 *
 * 查询参数 type：order（默认，代收回调 notify.log）| transfer（代付回调 transfer_notify.log）。
 * 代付回调字段 out_biz_no/transfer_no 会映射到 order_id/order_no 列，复用同一张前端表格。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
$type = (string) ($_GET['type'] ?? 'order') === 'transfer' ? 'transfer' : 'order';
$logFile = __DIR__ . '/logs/' . ($type === 'transfer' ? 'transfer_notify.log' : 'notify.log');

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

    // 代付回调用 out_biz_no/transfer_no，映射到 order_id/order_no 复用前端列
    $items[] = [
        'header'   => trim($header),
        'sign_ok'  => $signOk,
        'order_id' => (string) ($payload['order_id'] ?? $payload['out_biz_no'] ?? ''),
        'order_no' => (string) ($payload['order_no'] ?? $payload['transfer_no'] ?? ''),
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
    'file'  => 'logs/' . ($type === 'transfer' ? 'transfer_notify.log' : 'notify.log'),
]);
