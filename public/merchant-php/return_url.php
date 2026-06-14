<?php
/**
 * 支付完成同步跳转页（return_url）Demo
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <title>支付返回</title>
  <style>
    body { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; color: #333; }
    code { background: #f4f4f5; padding: 2px 6px; border-radius: 4px; }
  </style>
</head>
<body>
  <h1>支付同步返回</h1>
  <p>用户支付完成后浏览器会跳转到本页（<code>return_url</code>）。</p>
  <p>请以<strong>异步通知 notify_url</strong> 为准更新订单状态，本页仅作展示。</p>
  <?php if (!empty($_GET)) : ?>
    <h2>回传参数</h2>
    <pre><?= htmlspecialchars(json_encode($_GET, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?></pre>
  <?php endif; ?>
</body>
</html>
