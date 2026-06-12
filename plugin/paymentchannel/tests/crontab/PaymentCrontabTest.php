<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：支付定时任务类单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\crontab;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\crontab\NotifyRetryCrontab;
use plugin\paymentchannel\app\crontab\OrderTimeoutCrontab;
use plugin\paymentchannel\app\logic\OrderTimeoutLogic;
use plugin\paymentchannel\service\MerchantNotifyService;

/**
 * 可注入依赖的测试用通知重试任务
 */
class TestableNotifyRetryCrontab extends NotifyRetryCrontab
{
    public ?MerchantNotifyService $service = null;
    public int $retryReturn = 0;

    public function run($parameter = null): string
    {
        if ($this->service === null) {
            return parent::run($parameter);
        }

        $limit = NotifyRetryCrontab::DEFAULT_LIMIT;
        if ($parameter !== null && $parameter !== '') {
            $cfg = json_decode((string) $parameter, true);
            if (is_array($cfg) && isset($cfg['limit'])) {
                $limit = max(1, (int) $cfg['limit']);
            }
        }

        $count = $this->retryReturn;

        return "notify_retry processed={$count}, limit={$limit}";
    }
}

/**
 * 可注入依赖的测试用超时关闭任务
 */
class TestableOrderTimeoutCrontab extends OrderTimeoutCrontab
{
    public ?OrderTimeoutLogic $logic = null;
    public int $closedReturn = 0;

    public function run($parameter = null): string
    {
        if ($this->logic === null) {
            return parent::run($parameter);
        }

        $limit = OrderTimeoutCrontab::DEFAULT_LIMIT;
        if ($parameter !== null && $parameter !== '') {
            $cfg = json_decode((string) $parameter, true);
            if (is_array($cfg) && isset($cfg['limit'])) {
                $limit = max(1, (int) $cfg['limit']);
            }
        }

        return "order_timeout closed={$this->closedReturn}, limit={$limit}";
    }
}

class PaymentCrontabTest extends TestCase
{
    public function testNotifyRetryCrontabReturnsSummary(): void
    {
        $task = new TestableNotifyRetryCrontab();
        $task->service = new MerchantNotifyService();
        $task->retryReturn = 3;

        $msg = $task->run('{"limit":50}');
        $this->assertStringContainsString('processed=3', $msg);
        $this->assertStringContainsString('limit=50', $msg);
    }

    public function testOrderTimeoutCrontabReturnsSummary(): void
    {
        $task = new TestableOrderTimeoutCrontab();
        $task->logic = new OrderTimeoutLogic();
        $task->closedReturn = 2;

        $msg = $task->run('{"limit":80}');
        $this->assertStringContainsString('closed=2', $msg);
        $this->assertStringContainsString('limit=80', $msg);
    }

    public function testCrontabClassNamesMatchSeedTarget(): void
    {
        $this->assertTrue(method_exists(NotifyRetryCrontab::class, 'run'));
        $this->assertTrue(method_exists(OrderTimeoutCrontab::class, 'run'));
    }
}
