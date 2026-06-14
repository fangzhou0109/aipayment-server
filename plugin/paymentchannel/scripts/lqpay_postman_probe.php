<?php
// +----------------------------------------------------------------------
// | 兼容入口 → pay_gateway_postman_probe.php
// +----------------------------------------------------------------------
// | 推荐：
// |   php plugin/paymentchannel/scripts/pay_gateway_postman_probe.php self
// |   php plugin/paymentchannel/scripts/pay_gateway_postman_probe.php upstream 14
// +----------------------------------------------------------------------

declare(strict_types=1);

$target = __DIR__ . '/pay_gateway_postman_probe.php';
if (!is_file($target)) {
    fwrite(STDERR, "缺少 pay_gateway_postman_probe.php\n");
    exit(1);
}

$args = array_slice($argv, 1);
if ($args === []) {
    $argv = [$target, 'self'];
} elseif (count($args) === 1 && ctype_digit((string) $args[0])) {
    // 旧用法：lqpay_postman_probe.php 14
    $argv = [$target, 'upstream', $args[0]];
} elseif (!in_array($args[0], ['self', 'upstream'], true) && ctype_digit((string) $args[0])) {
    $argv = [$target, 'upstream', $args[0]];
} else {
    $argv = array_merge([$target], $args);
}

require $target;
