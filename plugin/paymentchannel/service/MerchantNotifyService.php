<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户异步通知服务（含失败退避重试）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use Closure;
use GuzzleHttp\Client;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\NotifyLog;
use plugin\paymentchannel\app\model\Order;
use Throwable;

/**
 * 商户异步通知服务
 *
 * 代收入账成功后，平台主动把支付结果「带签名」异步通知商户服务器（POST 表单），
 * 商户须回应纯文本 `SUCCESS` 表示已收妥；否则按退避策略重试，达上限转人工。
 *
 * 通知体字段与参考项目 GpayDome `notify_url.php` 一致：
 *   order_id(商户单号) / order_no(平台单号) / money(分) / mch_id / extra / status / time / sign / sign_type
 * 商户用自身 `secret_key`(MD5) 或平台公钥(RSA) 验签 —— 故平台用商户表 `secret_key` 拼串、
 * 用 `rsa_private_key`(平台对该商户回调签名私钥) 做 RSA 签名（见 {@see SignService}）。
 *
 * 重试设计（最终一致）：
 *  - 首次投递在入账后立即进行（dispatch）；
 *  - 失败则按退避序列安排下次重试时间，由常驻进程定时扫描 {@see retryPending} 重发；
 *  - 已签名通知体落库 `request_body`，重试时**原样重发**（避免重新签名，保证幂等可重放）；
 *  - 达最大重试次数仍失败 → status=失败（转人工）。
 *
 * 可测试性：HTTP 传输、当前时间、DB 访问全部抽为可注入闭包 / 可重写保护方法，
 * 单测注入假实现，不触发真实网络与 DB。
 */
class MerchantNotifyService
{
    /** 通知类型：代收 */
    public const BIZ_PAY = 1;

    /** 通知类型：代付（API 出款结果回调下游） */
    public const BIZ_TRANSFER = 2;

    /** 最大重试次数（不含首发）；达上限仍失败转人工 */
    public const MAX_RETRY = 6;

    /**
     * 退避序列（秒）：第 n 次重试在「上次失败后 BACKOFF[n-1] 秒」进行。
     * 6 次累计约 18 分钟，落在签名时间窗口（默认 1 小时）内，保证重发签名仍有效。
     * @var int[]
     */
    private const BACKOFF = [15, 30, 60, 120, 300, 600];

    /** 商户回应「已收妥」的约定串（大小写不敏感） */
    private const SUCCESS_FLAG = 'SUCCESS';

    /**
     * HTTP 传输闭包：fn(string $url, array $body): array{http_code:int, body:string}
     * @var Closure|null
     */
    private ?Closure $transport;

    /**
     * @param Closure|null $transport 可注入 HTTP 传输（测试用）；null=用 Guzzle 表单 POST
     */
    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * 入账成功后首次投递通知
     *
     * @param array $order 订单数据（须含 order_no/out_trade_no/mch_id/merchant_id/amount/notify_url/sign_type/extra）
     * @return bool 是否首发即成功
     */
    public function dispatch(array $order): bool
    {
        $notifyUrl = trim((string) ($order['notify_url'] ?? ''));
        $merchantId = (int) ($order['merchant_id'] ?? 0);
        // 无通知地址：直接记一条成功（无需通知），并标记订单已通知，避免无效重试
        if ($notifyUrl === '') {
            return true;
        }

        // 1) 取商户签名凭证（secret_key 拼串、rsa_private_key 供 RSA 签名）
        $secret = $this->loadMerchantSecret($merchantId);
        $secretKey = (string) ($secret['secret_key'] ?? '');
        $rsaPrivateKey = (string) ($secret['rsa_private_key'] ?? '');

        // 2) 组装并签名通知体（与商户 Demo 字段一致；money 换算为分）
        $signType = (int) ($order['sign_type'] ?? SignService::SIGN_TYPE_MD5);
        $body = [
            'order_id'  => (string) ($order['out_trade_no'] ?? ''),
            'order_no'  => (string) ($order['order_no'] ?? ''),
            'money'     => AmountHelper::format(AmountHelper::mul((string) ($order['amount'] ?? '0'), '100'), 0),
            'mch_id'    => (string) ($order['mch_id'] ?? ''),
            'extra'     => (string) ($order['extra'] ?? ''),
            'status'    => 'success',
            'time'      => (string) $this->now(),
        ];
        $body['sign'] = SignService::makeSign($body, $secretKey, $signType, $rsaPrivateKey !== '' ? $rsaPrivateKey : null);
        $body['sign_type'] = $signType;

        // 3) 落库通知日志（待通知），存已签名通知体供重试原样重发
        $logId = $this->createLog([
            'order_no'     => (string) ($order['order_no'] ?? ''),
            'merchant_id'  => $merchantId,
            'biz_type'     => self::BIZ_PAY,
            'notify_url'   => $notifyUrl,
            'request_body' => $this->encode($body),
            'retry_num'    => 0,
            'status'       => NotifyLog::STATUS_PENDING,
        ]);

        // 4) 首次投递
        return $this->attempt($logId, $notifyUrl, $body, (string) ($order['order_no'] ?? ''), 0);
    }

    /**
     * 代付（API 出款）结果首次投递通知下游商户
     *
     * 与代收 {@see self::dispatch()} 同机制（落库已签名通知体、失败退避重试、原样重放），
     * 通知体字段面向代付场景：商户代付单号 / 平台代付单号 / 金额(分) / 状态。
     *
     * @param array $withdraw 提现(代付)单（须含 withdraw_no/out_biz_no/mch_id/merchant_id/real_amount/notify_url）
     * @param bool $success true=出款成功，false=出款失败
     * @param string $reason 失败原因（success=false 时附带）
     * @return bool 是否首发即成功（无 notify_url 视为无需通知，返回 true）
     */
    public function dispatchTransfer(array $withdraw, bool $success, string $reason = ''): bool
    {
        $notifyUrl = trim((string) ($withdraw['notify_url'] ?? ''));
        $merchantId = (int) ($withdraw['merchant_id'] ?? 0);
        // 无通知地址：无需通知（人工提现单或商户未传 notify_url）
        if ($notifyUrl === '') {
            return true;
        }

        // 1) 取商户签名凭证（secret_key 拼串、rsa_private_key 供 RSA 签名）
        $secret = $this->loadMerchantSecret($merchantId);
        $secretKey = (string) ($secret['secret_key'] ?? '');
        $rsaPrivateKey = (string) ($secret['rsa_private_key'] ?? '');

        // 2) 组装并签名通知体（money 换算为分；status=success|fail）
        $signType = SignService::SIGN_TYPE_MD5;
        $body = [
            'out_biz_no'  => (string) ($withdraw['out_biz_no'] ?? ''),
            'transfer_no' => (string) ($withdraw['withdraw_no'] ?? ''),
            'money'       => AmountHelper::format(AmountHelper::mul((string) ($withdraw['real_amount'] ?? '0'), '100'), 0),
            'mch_id'      => (string) ($withdraw['mch_id'] ?? ''),
            'status'      => $success ? 'success' : 'fail',
            'time'        => (string) $this->now(),
        ];
        if (!$success && $reason !== '') {
            $body['reason'] = $reason;
        }
        $body['sign'] = SignService::makeSign($body, $secretKey, $signType, $rsaPrivateKey !== '' ? $rsaPrivateKey : null);
        $body['sign_type'] = $signType;

        // 3) 落库通知日志（待通知），存已签名通知体供重试原样重发
        $logId = $this->createLog([
            'order_no'     => (string) ($withdraw['withdraw_no'] ?? ''),
            'merchant_id'  => $merchantId,
            'biz_type'     => self::BIZ_TRANSFER,
            'notify_url'   => $notifyUrl,
            'request_body' => $this->encode($body),
            'retry_num'    => 0,
            'status'       => NotifyLog::STATUS_PENDING,
        ]);

        // 4) 首次投递
        return $this->attempt($logId, $notifyUrl, $body, (string) ($withdraw['withdraw_no'] ?? ''), 0);
    }

    /**
     * 平台后台人工重发：原样重放已签名 request_body（待通知/失败均可触发）
     *
     * @param int $logId 通知日志ID
     * @return array{success:bool, message:string}
     */
    public function resendManual(int $logId): array
    {
        $row = $this->loadLogById($logId);
        if ($row === null) {
            return ['success' => false, 'message' => '通知日志不存在'];
        }
        $status = (int) ($row['status'] ?? 0);
        if ($status === NotifyLog::STATUS_SUCCESS) {
            return ['success' => true, 'message' => '通知已成功，无需重发'];
        }

        $body = $this->decode((string) ($row['request_body'] ?? ''));
        if ($body === []) {
            return ['success' => false, 'message' => '通知体损坏或为空，无法重发'];
        }

        $notifyUrl = trim((string) ($row['notify_url'] ?? ''));
        if ($notifyUrl === '') {
            return ['success' => false, 'message' => '通知地址为空，无法重发'];
        }

        $retryNum = (int) ($row['retry_num'] ?? 0);
        // 已转人工失败：重置为待通知并给一次完整重试机会（不计入自动扫描的 next_notify_time）
        if ($status === NotifyLog::STATUS_FAILED) {
            $retryNum = self::MAX_RETRY - 1;
            $this->updateLog($logId, [
                'status'           => NotifyLog::STATUS_PENDING,
                'next_notify_time' => null,
            ]);
        }

        $ok = $this->attempt(
            $logId,
            $notifyUrl,
            $body,
            (string) ($row['order_no'] ?? ''),
            $retryNum,
        );

        return [
            'success' => $ok,
            'message' => $ok ? '重发成功，商户已回应 SUCCESS' : '重发失败，请查看响应详情；待通知记录将按退避策略继续自动重试',
        ];
    }

    /**
     * 扫描到期的待通知日志并重发（供常驻进程定时调用）
     *
     * @param int $limit 单批处理上限
     * @return int 本批处理条数
     */
    public function retryPending(int $limit = 100): int
    {
        $logs = $this->loadDueLogs($this->now(), $limit);
        $count = 0;
        foreach ($logs as $log) {
            $body = $this->decode((string) ($log['request_body'] ?? ''));
            if (empty($body)) {
                // 通知体损坏无法重发，置失败转人工
                $this->updateLog((int) $log['id'], ['status' => NotifyLog::STATUS_FAILED]);
                continue;
            }
            $this->attempt(
                (int) $log['id'],
                (string) ($log['notify_url'] ?? ''),
                $body,
                (string) ($log['order_no'] ?? ''),
                (int) ($log['retry_num'] ?? 0),
            );
            $count++;
        }
        return $count;
    }

    /**
     * 单次投递尝试：发请求并据结果更新日志/订单或安排下次重试
     *
     * @param int $logId 通知日志ID
     * @param string $url 通知地址
     * @param array $body 已签名通知体
     * @param string $orderNo 平台订单号
     * @param int $retryNum 当前已重试次数
     * @return bool 本次是否成功
     */
    protected function attempt(int $logId, string $url, array $body, string $orderNo, int $retryNum): bool
    {
        $result = $this->send($url, $body);
        $httpCode = (int) ($result['http_code'] ?? 0);
        $respBody = (string) ($result['body'] ?? '');

        // 成功判定：HTTP 200 且响应正文（去空白、大小写不敏感）等于 SUCCESS
        if ($httpCode === 200 && strtoupper(trim($respBody)) === self::SUCCESS_FLAG) {
            $this->updateLog($logId, [
                'status'        => NotifyLog::STATUS_SUCCESS,
                'response_body' => $respBody,
                'http_code'     => $httpCode,
                'retry_num'     => $retryNum,
            ]);
            // 订单通知状态置成功（1）
            $this->markOrderNotified($orderNo, 1);
            return true;
        }

        // 失败：达上限转人工，否则安排下次重试
        $nextRetry = $retryNum + 1;
        if ($nextRetry > self::MAX_RETRY) {
            $this->updateLog($logId, [
                'status'        => NotifyLog::STATUS_FAILED,
                'response_body' => $respBody,
                'http_code'     => $httpCode,
                'retry_num'     => $retryNum,
            ]);
            // 订单通知状态置失败（2），便于后台筛选人工处理
            $this->markOrderNotified($orderNo, 2);
            return false;
        }

        // nextRetry 落在 1..MAX_RETRY，对应 BACKOFF 下标 0..MAX_RETRY-1 必然存在
        $delay = self::BACKOFF[$nextRetry - 1];
        $this->updateLog($logId, [
            'status'           => NotifyLog::STATUS_PENDING,
            'response_body'    => $respBody,
            'http_code'        => $httpCode,
            'retry_num'        => $nextRetry,
            'next_notify_time' => date('Y-m-d H:i:s', $this->now() + (int) $delay),
        ]);
        return false;
    }

    // ===== HTTP / 时间 / DB 接缝：默认走真实设施，单测可重写以脱离依赖 =====

    /**
     * 发送通知（表单 POST），返回 http_code 与响应正文
     *
     * @param string $url 通知地址
     * @param array $body 表单参数
     * @return array{http_code:int, body:string}
     */
    protected function send(string $url, array $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $body);
        }
        try {
            $client = new Client(['timeout' => 8, 'http_errors' => false]);
            $res = $client->request('POST', $url, ['form_params' => $body]);
            return ['http_code' => $res->getStatusCode(), 'body' => (string) $res->getBody()];
        } catch (Throwable $e) {
            // 网络异常视为失败（非 200），交由重试逻辑处理
            return ['http_code' => 0, 'body' => $e->getMessage()];
        }
    }

    /**
     * 当前时间戳（可重写便于测试固定时间）
     * @return int
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * 读取商户签名凭证（secret_key + rsa_private_key）
     *
     * @param int $merchantId 商户ID
     * @return array{secret_key:string, rsa_private_key:string}
     */
    protected function loadMerchantSecret(int $merchantId): array
    {
        $merchant = Merchant::where('id', $merchantId)->find();
        return [
            'secret_key'      => $merchant ? (string) $merchant->secret_key : '',
            'rsa_private_key' => $merchant ? (string) $merchant->rsa_private_key : '',
        ];
    }

    /**
     * 按ID加载通知日志（可重写便于单测脱离 DB）
     *
     * @param int $logId 日志ID
     * @return array|null
     */
    protected function loadLogById(int $logId): ?array
    {
        $log = NotifyLog::where('id', $logId)->find();

        return $log ? $log->toArray() : null;
    }

    /**
     * 创建通知日志，返回主键ID
     *
     * @param array $data 日志数据
     * @return int
     */
    protected function createLog(array $data): int
    {
        $log = NotifyLog::create($data);
        return (int) $log->id;
    }

    /**
     * 更新通知日志
     *
     * @param int $logId 日志ID
     * @param array $patch 待更新字段
     */
    protected function updateLog(int $logId, array $patch): void
    {
        NotifyLog::where('id', $logId)->update($patch);
    }

    /**
     * 加载到期的待通知日志（status=待通知 且 next_notify_time<=now，含首发失败安排的重试）
     *
     * @param int $now 当前时间戳
     * @param int $limit 上限
     * @return array<int,array>
     */
    protected function loadDueLogs(int $now, int $limit): array
    {
        return NotifyLog::where('status', NotifyLog::STATUS_PENDING)
            ->whereNotNull('next_notify_time')
            ->where('next_notify_time', '<=', date('Y-m-d H:i:s', $now))
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 标记订单通知状态（1 已通知 / 2 通知失败）
     *
     * @param string $orderNo 平台订单号
     * @param int $notifyStatus 通知状态
     */
    protected function markOrderNotified(string $orderNo, int $notifyStatus): void
    {
        if ($orderNo === '') {
            return;
        }
        Order::where('order_no', $orderNo)->update(['notify_status' => $notifyStatus]);
    }

    /**
     * JSON 编码（中文/斜杠不转义），失败返回空串
     * @param mixed $data 数据
     * @return string
     */
    protected function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * JSON 解码为数组，失败返回空数组
     * @param string $raw 原始串
     * @return array
     */
    protected function decode(string $raw): array
    {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
