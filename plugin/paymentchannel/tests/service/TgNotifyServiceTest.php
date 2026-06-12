<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：TG 机器人通知服务测试（模板/开关/阈值/容错，脱离 DB/网络）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use Closure;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\TgNotifyService;

/**
 * 可测试的 TG 通知服务：内存替代配置、注入假 HTTP 传输、固定时间，脱离 DB/网络。
 */
class TestableTgNotifyService extends TgNotifyService
{
    /** 内存配置（替代 sa_system_config） */
    public array $config = [];
    /** 捕获的发送记录：[url, body] */
    public array $sent = [];
    /** 模拟 HTTP 返回码 */
    public int $httpCode = 200;
    /** 是否在 send 内抛异常（模拟网络异常） */
    public bool $throwOnSend = false;

    public function __construct()
    {
        // 不调用父构造，直接用内存
    }

    protected function loadConfig(): array
    {
        return $this->config;
    }

    protected function send(string $url, array $body): array
    {
        if ($this->throwOnSend) {
            throw new \RuntimeException('network down');
        }
        $this->sent[] = ['url' => $url, 'body' => $body];
        return ['http_code' => $this->httpCode, 'body' => 'ok'];
    }

    protected function now(): int
    {
        // 固定时间，模板时间确定性
        return strtotime('2026-06-09 12:00:00');
    }
}

/**
 * TG 机器人通知服务测试
 *
 * 覆盖 README 6.3 要求：模板渲染、开关控制、失败容错。脱离 DB/网络。
 */
class TgNotifyServiceTest extends TestCase
{
    /** 全开配置（总开关 + 凭证齐备） */
    private function fullConfig(array $override = []): array
    {
        return array_merge([
            'enabled'      => '1',
            'bot_token'    => 'BOTTOKEN',
            'chat_id'      => '-100123',
            'large_amount' => '1000',
        ], $override);
    }

    // ===== 模板渲染 =====

    /**
     * 大额订单模板含标题/商户号/单号/金额/时间
     */
    public function testRenderLargeOrderTemplate(): void
    {
        $svc = new TestableTgNotifyService();
        $text = $svc->renderTemplate(TgNotifyService::EVENT_LARGE_ORDER, [
            'mch_id'   => 'M88',
            'order_no' => 'P20260609001',
            'amount'   => '8888.5',
        ]);

        $this->assertStringContainsString('大额订单支付成功', $text);
        $this->assertStringContainsString('M88', $text);
        $this->assertStringContainsString('P20260609001', $text);
        $this->assertStringContainsString('8888.5000 元', $text);
        $this->assertStringContainsString('2026-06-09 12:00:00', $text);
    }

    /**
     * 提现模板含状态文案与提现单号
     */
    public function testRenderWithdrawTemplate(): void
    {
        $svc = new TestableTgNotifyService();
        $text = $svc->renderTemplate(TgNotifyService::EVENT_WITHDRAW, [
            'mch_id'      => 'M9',
            'withdraw_no' => 'W202606090001',
            'amount'      => '500',
            'status_text' => '申请',
        ]);

        $this->assertStringContainsString('提现申请', $text);
        $this->assertStringContainsString('W202606090001', $text);
        $this->assertStringContainsString('500.0000 元', $text);
    }

    /**
     * 异常模板含场景与详情
     */
    public function testRenderExceptionTemplate(): void
    {
        $svc = new TestableTgNotifyService();
        $text = $svc->renderTemplate(TgNotifyService::EVENT_EXCEPTION, [
            'scene'   => '上游回调验签失败',
            'message' => 'sign mismatch',
        ]);

        $this->assertStringContainsString('系统异常告警', $text);
        $this->assertStringContainsString('上游回调验签失败', $text);
        $this->assertStringContainsString('sign mismatch', $text);
    }

    /**
     * HTML 特殊字符被转义，防止破坏 TG HTML parse_mode
     */
    public function testRenderEscapesHtml(): void
    {
        $svc = new TestableTgNotifyService();
        $text = $svc->renderTemplate(TgNotifyService::EVENT_EXCEPTION, [
            'scene'   => '<b>x</b>',
            'message' => 'a & b < c',
        ]);

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $text);
        $this->assertStringContainsString('a &amp; b &lt; c', $text);
    }

    // ===== 开关控制 =====

    /**
     * 总开关开 + 凭证齐备 + 达阈值 → 应推送
     */
    public function testShouldNotifyWhenEnabled(): void
    {
        $svc = new TestableTgNotifyService();
        $ok = $svc->shouldNotify(
            TgNotifyService::EVENT_LARGE_ORDER,
            ['amount' => '2000'],
            $this->fullConfig()
        );
        $this->assertTrue($ok);
    }

    /**
     * 总开关关（或未配置）→ 不推送
     */
    public function testShouldNotNotifyWhenDisabled(): void
    {
        $svc = new TestableTgNotifyService();
        // 显式关
        $this->assertFalse($svc->shouldNotify(
            TgNotifyService::EVENT_EXCEPTION,
            [],
            $this->fullConfig(['enabled' => '0'])
        ));
        // 未配置（空）默认关
        $this->assertFalse($svc->shouldNotify(
            TgNotifyService::EVENT_EXCEPTION,
            [],
            $this->fullConfig(['enabled' => ''])
        ));
    }

    /**
     * 缺 bot_token 或 chat_id → 不推送
     */
    public function testShouldNotNotifyWhenCredentialsMissing(): void
    {
        $svc = new TestableTgNotifyService();
        $this->assertFalse($svc->shouldNotify(
            TgNotifyService::EVENT_EXCEPTION,
            [],
            $this->fullConfig(['bot_token' => ''])
        ));
        $this->assertFalse($svc->shouldNotify(
            TgNotifyService::EVENT_EXCEPTION,
            [],
            $this->fullConfig(['chat_id' => ''])
        ));
    }

    /**
     * 事件级开关：默认开启，显式关闭该事件则不推（其他事件仍推）
     */
    public function testEventLevelToggle(): void
    {
        $svc = new TestableTgNotifyService();
        // 关闭提现事件
        $config = $this->fullConfig(['event_withdraw' => '0']);
        $this->assertFalse($svc->shouldNotify(TgNotifyService::EVENT_WITHDRAW, ['amount' => '1'], $config));
        // 异常事件未显式关闭 → 仍推
        $this->assertTrue($svc->shouldNotify(TgNotifyService::EVENT_EXCEPTION, [], $config));
    }

    // ===== 阈值控制 =====

    /**
     * 大额阈值：金额达阈值才推
     */
    public function testLargeAmountThreshold(): void
    {
        $svc = new TestableTgNotifyService();
        $config = $this->fullConfig(['large_amount' => '1000']);
        // 等于阈值 → 推
        $this->assertTrue($svc->shouldNotify(TgNotifyService::EVENT_LARGE_ORDER, ['amount' => '1000'], $config));
        // 高于阈值 → 推
        $this->assertTrue($svc->shouldNotify(TgNotifyService::EVENT_LARGE_ORDER, ['amount' => '1000.01'], $config));
        // 低于阈值 → 不推
        $this->assertFalse($svc->shouldNotify(TgNotifyService::EVENT_LARGE_ORDER, ['amount' => '999.99'], $config));
    }

    /**
     * 阈值只约束大额订单事件，不影响提现/异常事件
     */
    public function testThresholdOnlyAffectsLargeOrder(): void
    {
        $svc = new TestableTgNotifyService();
        $config = $this->fullConfig(['large_amount' => '100000']);
        // 提现小额仍推（不受大额阈值约束）
        $this->assertTrue($svc->shouldNotify(TgNotifyService::EVENT_WITHDRAW, ['amount' => '1'], $config));
    }

    // ===== notify 端到端（含失败容错） =====

    /**
     * notify 成功：达条件则发请求、HTTP 200 → true，且请求体含 chat_id/text
     */
    public function testNotifySuccess(): void
    {
        $svc = new TestableTgNotifyService();
        $svc->config = $this->fullConfig();
        $svc->httpCode = 200;

        $ok = $svc->notify(TgNotifyService::EVENT_LARGE_ORDER, [
            'mch_id'   => 'M1',
            'order_no' => 'P1',
            'amount'   => '5000',
        ]);

        $this->assertTrue($ok);
        $this->assertCount(1, $svc->sent);
        $this->assertStringContainsString('/botBOTTOKEN/sendMessage', $svc->sent[0]['url']);
        $this->assertSame('-100123', $svc->sent[0]['body']['chat_id']);
        $this->assertStringContainsString('大额订单支付成功', $svc->sent[0]['body']['text']);
    }

    /**
     * notify 被开关拦截：不发请求、返回 false
     */
    public function testNotifySkippedBySwitch(): void
    {
        $svc = new TestableTgNotifyService();
        $svc->config = $this->fullConfig(['enabled' => '0']);

        $ok = $svc->notify(TgNotifyService::EVENT_EXCEPTION, ['scene' => 's', 'message' => 'm']);

        $this->assertFalse($ok);
        $this->assertCount(0, $svc->sent);
    }

    /**
     * 失败容错：HTTP 非 200 → 返回 false（不抛）
     */
    public function testNotifyHttpFailureReturnsFalse(): void
    {
        $svc = new TestableTgNotifyService();
        $svc->config = $this->fullConfig();
        $svc->httpCode = 500;

        $ok = $svc->notify(TgNotifyService::EVENT_EXCEPTION, ['scene' => 's', 'message' => 'm']);

        $this->assertFalse($ok);
        $this->assertCount(1, $svc->sent); // 发了请求但失败
    }

    /**
     * 失败容错（核心）：send 抛异常 → notify 吞掉返回 false，绝不向上抛（不影响主业务）
     */
    public function testNotifyToleratesSendException(): void
    {
        $svc = new TestableTgNotifyService();
        $svc->config = $this->fullConfig();
        $svc->throwOnSend = true;

        // 不应抛出任何异常
        $ok = $svc->notify(TgNotifyService::EVENT_LARGE_ORDER, ['amount' => '5000']);

        $this->assertFalse($ok);
    }

    /**
     * 便捷方法 notifyWithdraw：组装数据并按提现事件推送
     */
    public function testNotifyWithdrawConvenience(): void
    {
        $svc = new TestableTgNotifyService();
        $svc->config = $this->fullConfig();

        $ok = $svc->notifyWithdraw(
            ['mch_id' => 'M2', 'withdraw_no' => 'W9', 'amount' => '300'],
            '成功'
        );

        $this->assertTrue($ok);
        $this->assertStringContainsString('提现成功', $svc->sent[0]['body']['text']);
        $this->assertStringContainsString('W9', $svc->sent[0]['body']['text']);
    }

    /**
     * 便捷方法 notifyException：组装场景/详情并推送
     */
    public function testNotifyExceptionConvenience(): void
    {
        $svc = new TestableTgNotifyService();
        $svc->config = $this->fullConfig();

        $ok = $svc->notifyException('入账失败', 'merchant not found');

        $this->assertTrue($ok);
        $this->assertStringContainsString('系统异常告警', $svc->sent[0]['body']['text']);
        $this->assertStringContainsString('入账失败', $svc->sent[0]['body']['text']);
    }
}
