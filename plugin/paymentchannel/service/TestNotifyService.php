<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：后台测试商户异步通知接收器（闭环联调）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use plugin\paymentchannel\app\model\Merchant;
use support\Redis;
use Throwable;

/**
 * 测试商户 notify_url 接收器
 *
 * 模拟商户服务器：验签、记录最近回调、回应纯文本 SUCCESS/FAIL。
 * 供后台测试下单 + 补单时 MerchantNotifyService 投递，形成「入账 → 通知 → 收妥」闭环。
 *
 * 日志存 Redis 列表 pay:test_notify:logs（多 Worker 共享）；Redis 不可用时降级进程内缓冲。
 */
class TestNotifyService
{
    public const RESPONSE_SUCCESS = 'SUCCESS';
    public const RESPONSE_FAIL = 'FAIL';

    private const REDIS_KEY = 'pay:test_notify:logs';

    /** 进程内降级缓冲（仅 Redis 异常时） */
    private static array $memoryLogs = [];

    /**
     * 处理一次商户异步通知（POST 表单）
     *
     * @param array $payload 通知体（含 sign/sign_type）
     * @param string $clientIp 来源 IP（记录用）
     * @return array{http_code:int, response:string, sign_ok:bool, recorded:bool}
     */
    public function handle(array $payload, string $clientIp = ''): array
    {
        if (!$this->isEnabled()) {
            return [
                'http_code' => 403,
                'response'  => 'DISABLED',
                'sign_ok'   => false,
                'recorded'  => false,
            ];
        }

        $mchId = trim((string) ($payload['mch_id'] ?? ''));
        $signType = (int) ($payload['sign_type'] ?? SignService::SIGN_TYPE_MD5);
        $signOk = false;
        $signMessage = '';

        $merchant = $this->loadMerchantByMchId($mchId);
        if ($merchant === null) {
            $signMessage = '商户不存在';
        } else {
            [$signOk, $signMessage] = $this->verifyNotifySign($payload, $merchant, $signType);
        }

        $entry = [
            'id'           => $this->nextId(),
            'received_at'  => date('Y-m-d H:i:s'),
            'client_ip'    => $clientIp,
            'mch_id'       => $mchId,
            'order_no'     => (string) ($payload['order_no'] ?? ''),
            'order_id'     => (string) ($payload['order_id'] ?? ''),
            'money'        => (string) ($payload['money'] ?? ''),
            'status'       => (string) ($payload['status'] ?? ''),
            'sign_type'    => $signType,
            'sign_ok'      => $signOk,
            'sign_message' => $signMessage,
            'payload'      => $payload,
        ];
        $recorded = $this->record($entry);

        $accept = $signOk || $this->acceptInvalidSign();
        return [
            'http_code' => 200,
            'response'  => $accept ? self::RESPONSE_SUCCESS : self::RESPONSE_FAIL,
            'sign_ok'   => $signOk,
            'recorded'  => $recorded,
        ];
    }

    /**
     * 查询最近测试回调记录（新 → 旧）
     *
     * @param int $limit 条数上限
     * @param string|null $orderNo 平台订单号过滤
     * @param string|null $outTradeNo 商户订单号过滤
     * @return array<int,array>
     */
    public function listRecent(int $limit = 20, ?string $orderNo = null, ?string $outTradeNo = null): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->loadLogs($limit * 5);

        $filtered = [];
        foreach ($rows as $row) {
            if ($orderNo !== null && $orderNo !== '' && ($row['order_no'] ?? '') !== $orderNo) {
                continue;
            }
            if ($outTradeNo !== null && $outTradeNo !== '' && ($row['order_id'] ?? '') !== $outTradeNo) {
                continue;
            }
            unset($row['payload']);
            $filtered[] = $row;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * 解析后台测试下单默认 notify_url
     */
    public static function resolveDefaultNotifyUrl(): string
    {
        $configured = trim((string) config('plugin.paymentchannel.app.test_notify_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $domain = trim((string) config('plugin.paymentchannel.app.notify_domain', ''));
        if ($domain !== '') {
            return rtrim($domain, '/') . '/pay/test/notify';
        }

        return 'http://127.0.0.1:8787/pay/test/notify';
    }

    /**
     * 是否为本系统测试回调地址（用于 UI 展示测试记录入口）
     */
    public static function isTestNotifyUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $needles = [
            '/pay/test/notify',
            self::resolveDefaultNotifyUrl(),
        ];
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($url, rtrim($needle, '/'))) {
                return true;
            }
        }

        return false;
    }

    protected function isEnabled(): bool
    {
        return (bool) config('plugin.paymentchannel.app.test_notify.enable', true);
    }

    protected function acceptInvalidSign(): bool
    {
        return (bool) config('plugin.paymentchannel.app.test_notify.accept_invalid_sign', false);
    }

    protected function maxLogs(): int
    {
        return max(10, (int) config('plugin.paymentchannel.app.test_notify.max_logs', 100));
    }

    /**
     * 验签：与 MerchantNotifyService 出站签名、reference notify_url.php 入站验签对齐
     *
     * @return array{0:bool,1:string} [是否通过, 失败原因]
     */
    protected function verifyNotifySign(array $payload, array $merchant, int $signType): array
    {
        $secretKey = (string) ($merchant['secret_key'] ?? '');

        if ($signType === SignService::SIGN_TYPE_RSA) {
            $platformPublic = MerchantKeyService::extractPublicKeyFromPrivate(
                (string) ($merchant['rsa_private_key'] ?? '')
            );
            if ($platformPublic === null || $platformPublic === '') {
                return [false, '平台 RSA 私钥未配置或无效'];
            }
            $ok = SignService::verify($payload, $secretKey, $signType, $platformPublic);

            return [$ok, $ok ? '' : 'RSA 签名校验失败（请确认平台公钥与 sign_type=2）'];
        }

        if ($secretKey === '') {
            return [false, '商户 MD5 密钥未配置'];
        }
        $ok = SignService::verify($payload, $secretKey, SignService::SIGN_TYPE_MD5);

        return [$ok, $ok ? '' : 'MD5 签名校验失败'];
    }

    /**
     * 加载验签凭证（须绕过 Merchant.$hidden，toArray 会剥离 secret_key）
     *
     * @return array|null
     */
    protected function loadMerchantByMchId(string $mchId): ?array
    {
        if ($mchId === '') {
            return null;
        }
        $row = Merchant::where('mch_id', $mchId)->find();
        if ($row === null) {
            return null;
        }

        return [
            'id'              => (int) $row->id,
            'mch_id'          => (string) $row->mch_id,
            'secret_key'      => (string) $row->secret_key,
            'rsa_private_key' => (string) $row->rsa_private_key,
            'rsa_public_key'  => (string) $row->rsa_public_key,
        ];
    }

    protected function record(array $entry): bool
    {
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        try {
            Redis::lPush(self::REDIS_KEY, $json);
            Redis::lTrim(self::REDIS_KEY, 0, $this->maxLogs() - 1);

            return true;
        } catch (Throwable) {
            array_unshift(self::$memoryLogs, $entry);
            if (count(self::$memoryLogs) > $this->maxLogs()) {
                self::$memoryLogs = array_slice(self::$memoryLogs, 0, $this->maxLogs());
            }

            return true;
        }
    }

    /**
     * @return array<int,array>
     */
    protected function loadLogs(int $limit): array
    {
        try {
            $raw = Redis::lRange(self::REDIS_KEY, 0, max(0, $limit - 1));
            $rows = [];
            foreach ($raw as $item) {
                $decoded = json_decode((string) $item, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }

            return $rows;
        } catch (Throwable) {
            return array_slice(self::$memoryLogs, 0, $limit);
        }
    }

    protected function nextId(): string
    {
        return date('YmdHis') . substr((string) random_int(100000, 999999), -6);
    }
}
