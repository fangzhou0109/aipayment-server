<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户异步通知服务测试（HTTP/DB 接缝重写，脱离依赖）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\model\NotifyLog;
use plugin\paymentchannel\service\MerchantNotifyService;
use plugin\paymentchannel\service\SignService;

/**
 * 可测试的通知服务：用内存替代 DB，捕获日志写入、发送记录、订单标记。
 */
class TestableMerchantNotifyService extends MerchantNotifyService
{
    /** 商户密钥 */
    public string $secretKey = 'merchant_notify_secret_001';
    /** 固定时间戳 */
    public int $fixedNow = 1780000000;

    /** 内存日志表：id => row */
    public array $logs = [];
    /** 自增ID */
    public int $autoId = 0;
    /** 捕获的发送记录 [['url'=>,'body'=>], ...] */
    public array $sent = [];
    /** 订单通知状态标记 [order_no => status] */
    public array $orderMarks = [];
    /** 到期待重试日志（retryPending 用） */
    public array $dueLogs = [];

    protected function now(): int
    {
        return $this->fixedNow;
    }

    protected function loadMerchantSecret(int $merchantId): array
    {
        return ['secret_key' => $this->secretKey, 'rsa_private_key' => ''];
    }

    protected function createLog(array $data): int
    {
        $id = ++$this->autoId;
        $this->logs[$id] = array_merge(['id' => $id], $data);
        return $id;
    }

    protected function updateLog(int $logId, array $patch): void
    {
        $this->logs[$logId] = array_merge($this->logs[$logId] ?? ['id' => $logId], $patch);
    }

    protected function loadDueLogs(int $now, int $limit): array
    {
        return $this->dueLogs;
    }

    protected function loadLogById(int $logId): ?array
    {
        return $this->logs[$logId] ?? null;
    }

    protected function markOrderNotified(string $orderNo, int $notifyStatus): void
    {
        $this->orderMarks[$orderNo] = $notifyStatus;
    }
}

/**
 * 商户异步通知服务测试
 *
 * 覆盖：通知体签名正确、首发成功停止重试、首发失败安排退避重试、
 * 重试达上限转人工、空通知地址直接成功、retryPending 重发到期日志。
 */
class MerchantNotifyServiceTest extends TestCase
{
    /** 标准订单 */
    private function order(array $override = []): array
    {
        return array_merge([
            'order_no'     => 'P20260608120000001',
            'out_trade_no' => 'OUT001',
            'mch_id'       => 'M001',
            'merchant_id'  => 1,
            'amount'       => '100.0000',
            'extra'        => 'ext1',
            'sign_type'    => SignService::SIGN_TYPE_MD5,
            'notify_url'   => 'https://merchant.example.com/notify',
        ], $override);
    }

    /**
     * 注入成功响应的服务（商户回 SUCCESS）
     */
    private function successService(array &$captured): TestableMerchantNotifyService
    {
        $svc = new TestableMerchantNotifyService(function (string $url, array $body) use (&$captured): array {
            $captured = ['url' => $url, 'body' => $body];
            return ['http_code' => 200, 'body' => 'SUCCESS'];
        });
        return $svc;
    }

    /**
     * 首发成功：通知体字段与签名正确、money 换算为分、日志置成功、订单标记已通知
     */
    public function testDispatchSuccess(): void
    {
        $captured = [];
        $svc = $this->successService($captured);

        $ok = $svc->dispatch($this->order());

        $this->assertTrue($ok);
        // 通知体字段
        $body = $captured['body'];
        $this->assertSame('OUT001', $body['order_id']);
        $this->assertSame('P20260608120000001', $body['order_no']);
        $this->assertSame('10000', $body['money']); // 100 元 → 10000 分
        $this->assertSame('success', $body['status']);
        $this->assertArrayHasKey('sign', $body);
        // 签名可被商户用同密钥验签
        $this->assertTrue(SignService::verify($body, $svc->secretKey, SignService::SIGN_TYPE_MD5));
        // 日志成功、订单标记已通知(1)
        $this->assertSame(NotifyLog::STATUS_SUCCESS, $svc->logs[1]['status']);
        $this->assertSame(1, $svc->orderMarks['P20260608120000001']);
    }

    /**
     * 首发失败：日志置待通知、retry_num=1、安排 next_notify_time（now+15s），订单未标记成功
     */
    public function testDispatchFailSchedulesRetry(): void
    {
        $svc = new TestableMerchantNotifyService(
            fn (string $url, array $body): array => ['http_code' => 500, 'body' => 'ERR']
        );

        $ok = $svc->dispatch($this->order());

        $this->assertFalse($ok);
        $log = $svc->logs[1];
        $this->assertSame(NotifyLog::STATUS_PENDING, $log['status']);
        $this->assertSame(1, $log['retry_num']);
        // 退避首档 15 秒
        $this->assertSame(date('Y-m-d H:i:s', $svc->fixedNow + 15), $log['next_notify_time']);
        // 订单尚未标记成功
        $this->assertArrayNotHasKey('P20260608120000001', $svc->orderMarks);
    }

    /**
     * 响应非 SUCCESS（HTTP 200 但正文错误）→ 视为失败安排重试
     */
    public function testNonSuccessBodyTreatedAsFailure(): void
    {
        $svc = new TestableMerchantNotifyService(
            fn (string $url, array $body): array => ['http_code' => 200, 'body' => 'FAIL']
        );

        $ok = $svc->dispatch($this->order());

        $this->assertFalse($ok);
        $this->assertSame(NotifyLog::STATUS_PENDING, $svc->logs[1]['status']);
        $this->assertSame(1, $svc->logs[1]['retry_num']);
    }

    /**
     * 大小写不敏感：商户回 "success" 也判成功
     */
    public function testSuccessCaseInsensitive(): void
    {
        $captured = [];
        $svc = new TestableMerchantNotifyService(function (string $url, array $body): array {
            return ['http_code' => 200, 'body' => " success \n"];
        });
        $this->assertTrue($svc->dispatch($this->order()));
        $this->assertSame(NotifyLog::STATUS_SUCCESS, $svc->logs[1]['status']);
    }

    /**
     * 空通知地址：直接成功（无需通知），不发请求不写日志
     */
    public function testEmptyNotifyUrlSkips(): void
    {
        $sent = false;
        $svc = new TestableMerchantNotifyService(function () use (&$sent): array {
            $sent = true;
            return ['http_code' => 200, 'body' => 'SUCCESS'];
        });

        $this->assertTrue($svc->dispatch($this->order(['notify_url' => ''])));
        $this->assertFalse($sent);
        $this->assertCount(0, $svc->logs);
    }

    /**
     * 达最大重试上限：retry_num=MAX 时再失败 → 置失败转人工，订单标记通知失败(2)
     */
    public function testMaxRetryMarksFailed(): void
    {
        $svc = new TestableMerchantNotifyService(
            fn (string $url, array $body): array => ['http_code' => 500, 'body' => 'ERR']
        );
        // 预置一条已到 MAX_RETRY 次的待通知日志
        $svc->dueLogs = [[
            'id'           => 9,
            'order_no'     => 'P_MAX',
            'notify_url'   => 'https://merchant.example.com/notify',
            'request_body' => json_encode(['order_no' => 'P_MAX', 'sign' => 'x']),
            'retry_num'    => MerchantNotifyService::MAX_RETRY,
            'status'       => NotifyLog::STATUS_PENDING,
        ]];
        // 让 updateLog 能写入（内存表预置该行）
        $svc->logs[9] = $svc->dueLogs[0];

        $processed = $svc->retryPending();

        $this->assertSame(1, $processed);
        $this->assertSame(NotifyLog::STATUS_FAILED, $svc->logs[9]['status']);
        $this->assertSame(2, $svc->orderMarks['P_MAX']);
    }

    /**
     * retryPending：到期日志重发成功 → 置成功
     */
    public function testRetryPendingResendSuccess(): void
    {
        $svc = new TestableMerchantNotifyService(
            fn (string $url, array $body): array => ['http_code' => 200, 'body' => 'SUCCESS']
        );
        $svc->dueLogs = [[
            'id'           => 5,
            'order_no'     => 'P_RETRY',
            'notify_url'   => 'https://merchant.example.com/notify',
            'request_body' => json_encode(['order_no' => 'P_RETRY', 'money' => '10000', 'sign' => 'x']),
            'retry_num'    => 2,
            'status'       => NotifyLog::STATUS_PENDING,
        ]];
        $svc->logs[5] = $svc->dueLogs[0];

        $processed = $svc->retryPending();

        $this->assertSame(1, $processed);
        $this->assertSame(NotifyLog::STATUS_SUCCESS, $svc->logs[5]['status']);
        $this->assertSame(1, $svc->orderMarks['P_RETRY']);
    }

    /**
     * retryPending：通知体损坏（无法解码）→ 直接置失败，不发请求
     */
    public function testRetryPendingCorruptBodyMarksFailed(): void
    {
        $sent = false;
        $svc = new TestableMerchantNotifyService(function () use (&$sent): array {
            $sent = true;
            return ['http_code' => 200, 'body' => 'SUCCESS'];
        });
        $svc->dueLogs = [[
            'id'           => 7,
            'order_no'     => 'P_BAD',
            'notify_url'   => 'https://merchant.example.com/notify',
            'request_body' => 'not-json',
            'retry_num'    => 1,
            'status'       => NotifyLog::STATUS_PENDING,
        ]];
        $svc->logs[7] = $svc->dueLogs[0];

        $svc->retryPending();

        $this->assertFalse($sent);
        $this->assertSame(NotifyLog::STATUS_FAILED, $svc->logs[7]['status']);
    }

    /**
     * 人工重发：失败日志重试成功
     */
    public function testResendManualOnFailedLog(): void
    {
        $svc = new TestableMerchantNotifyService(
            fn (string $url, array $body): array => ['http_code' => 200, 'body' => 'SUCCESS']
        );
        $svc->logs[11] = [
            'id'           => 11,
            'order_no'     => 'P_MANUAL',
            'notify_url'   => 'https://merchant.example.com/notify',
            'request_body' => json_encode(['order_no' => 'P_MANUAL', 'sign' => 'x']),
            'retry_num'    => MerchantNotifyService::MAX_RETRY,
            'status'       => NotifyLog::STATUS_FAILED,
        ];

        $result = $svc->resendManual(11);

        $this->assertTrue($result['success']);
        $this->assertSame(NotifyLog::STATUS_SUCCESS, $svc->logs[11]['status']);
        $this->assertSame(1, $svc->orderMarks['P_MANUAL']);
    }

    /**
     * 人工重发：已成功无需重发
     */
    public function testResendManualSkipSuccess(): void
    {
        $svc = new TestableMerchantNotifyService();
        $svc->logs[12] = [
            'id'           => 12,
            'order_no'     => 'P_OK',
            'notify_url'   => 'https://merchant.example.com/notify',
            'request_body' => '{}',
            'retry_num'    => 0,
            'status'       => NotifyLog::STATUS_SUCCESS,
        ];

        $result = $svc->resendManual(12);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('无需重发', $result['message']);
    }
}
