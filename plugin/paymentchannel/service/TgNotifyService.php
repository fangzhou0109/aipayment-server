<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：Telegram 机器人通知服务（运营事件实时推送）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use Closure;
use GuzzleHttp\Client;
use plugin\saiadmin\app\logic\system\SystemConfigLogic;
use plugin\saiadmin\utils\Arr;
use Throwable;

/**
 * Telegram 机器人通知服务
 *
 * 把支付侧关键运营事件（大额订单支付成功 / 提现事件 / 系统异常）实时推送到 TG 群，
 * 让运营第一时间感知。配置存 `sa_system_config` 的 `tg_config` 分组（机器人 token、chat_id、
 * 总开关、各事件开关、大额阈值）。
 *
 * 设计要点：
 *  - **模板渲染 {@see renderTemplate} 为纯函数**（事件 + 数据 → 文本），便于单测确定性断言。
 *  - **开关/阈值判定 {@see shouldNotify} 为纯函数**（仅依据事件、数据、配置）：总开关关 / 缺 token/chat /
 *    事件开关关 / 大额未达阈值 任一不满足即不推送。
 *  - **失败容错**：推送是「旁路告警」，绝不能影响主业务——`notify` 全程 try/catch，任何异常/网络失败
 *    都吞掉并返回 false，不向上抛。
 *  - 可测试性：配置读取 {@see loadConfig}、HTTP 传输（注入闭包）、当前时间 {@see now} 全为可注入/
 *    可重写接缝，单测不触发真实 DB / 网络。
 */
class TgNotifyService
{
    /** 事件：大额订单支付成功 */
    public const EVENT_LARGE_ORDER = 'large_order';
    /** 事件：提现（申请/成功/失败等，由调用方给出状态文案） */
    public const EVENT_WITHDRAW = 'withdraw';
    /** 事件：系统异常告警 */
    public const EVENT_EXCEPTION = 'exception';

    /** sa_system_config 分组名 */
    private const CONFIG_GROUP = 'tg_config';

    /** TG Bot API 基地址（sendMessage） */
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * HTTP 传输闭包：fn(string $url, array $body): array{http_code:int, body:string}
     * null=用 Guzzle 表单 POST；测试注入假实现。
     * @var Closure|null
     */
    private ?Closure $transport;

    /**
     * @param Closure|null $transport 可注入 HTTP 传输（测试用）
     */
    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * 推送一个事件（统一入口，失败容错）
     *
     * @param string $event 事件类型（EVENT_*）
     * @param array  $data  渲染数据（字段随事件而定）
     * @return bool 是否成功推送（被开关拦截 / 失败均返回 false，且绝不抛异常）
     */
    public function notify(string $event, array $data): bool
    {
        try {
            $config = $this->loadConfig();

            // 开关 / 阈值判定：不满足直接跳过（非异常，正常返回 false）
            if (!$this->shouldNotify($event, $data, $config)) {
                return false;
            }

            $text = $this->renderTemplate($event, $data);
            $url = self::API_BASE . trim((string) ($config['bot_token'] ?? '')) . '/sendMessage';
            $result = $this->send($url, [
                'chat_id'    => trim((string) ($config['chat_id'] ?? '')),
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);

            // TG 返回 HTTP 200 视为成功
            return (int) ($result['http_code'] ?? 0) === 200;
        } catch (Throwable $e) {
            // 旁路告警绝不影响主业务：任何异常吞掉
            return false;
        }
    }

    /**
     * 便捷：大额订单支付成功推送
     *
     * @param array $order 订单数据（mch_id/order_no/amount）
     * @return bool
     */
    public function notifyLargeOrder(array $order): bool
    {
        return $this->notify(self::EVENT_LARGE_ORDER, [
            'mch_id'   => (string) ($order['mch_id'] ?? ''),
            'order_no' => (string) ($order['order_no'] ?? ''),
            'amount'   => (string) ($order['amount'] ?? '0'),
        ]);
    }

    /**
     * 便捷：提现事件推送
     *
     * @param array  $withdraw   提现数据（mch_id/withdraw_no/amount）
     * @param string $statusText 状态文案（如「申请」「成功」「失败」）
     * @return bool
     */
    public function notifyWithdraw(array $withdraw, string $statusText): bool
    {
        return $this->notify(self::EVENT_WITHDRAW, [
            'mch_id'      => (string) ($withdraw['mch_id'] ?? ''),
            'withdraw_no' => (string) ($withdraw['withdraw_no'] ?? ''),
            'amount'      => (string) ($withdraw['amount'] ?? '0'),
            'status_text' => $statusText,
        ]);
    }

    /**
     * 便捷：系统异常告警推送
     *
     * @param string $scene   异常场景（如「上游回调验签失败」）
     * @param string $message 异常详情
     * @return bool
     */
    public function notifyException(string $scene, string $message): bool
    {
        return $this->notify(self::EVENT_EXCEPTION, [
            'scene'   => $scene,
            'message' => $message,
        ]);
    }

    /**
     * 开关 / 阈值判定（纯函数）
     *
     * 依次校验：总开关 → token/chat 齐备 → 事件开关 → 大额阈值（仅大额订单）。
     *
     * @param string $event  事件类型
     * @param array  $data   渲染数据
     * @param array  $config 扁平配置（enabled/bot_token/chat_id/large_amount/event_* ）
     * @return bool 是否应推送
     */
    public function shouldNotify(string $event, array $data, array $config): bool
    {
        // 1) 总开关：默认关闭，必须显式开启（未配置即不推送，避免误发）
        if (!$this->isTruthy($config['enabled'] ?? '')) {
            return false;
        }

        // 2) 必要凭证齐备，否则无法发送
        if (trim((string) ($config['bot_token'] ?? '')) === '' || trim((string) ($config['chat_id'] ?? '')) === '') {
            return false;
        }

        // 3) 事件级开关：默认开启，仅显式关闭（'0'/'false'/'off'/'no'）才不推
        $eventFlagKey = 'event_' . $event;
        if ($this->isFalsy($config[$eventFlagKey] ?? '')) {
            return false;
        }

        // 4) 大额订单需达到阈值才推送（阈值缺省/<=0 视为 0，即任意金额都推）
        if ($event === self::EVENT_LARGE_ORDER) {
            $threshold = (string) ($config['large_amount'] ?? '0');
            $amount = (string) ($data['amount'] ?? '0');
            // amount < threshold 则不推（AmountHelper::compare 禁浮点）
            if (AmountHelper::compare($amount, $threshold) < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 渲染事件消息文本（纯函数）
     *
     * @param string $event 事件类型
     * @param array  $data  渲染数据
     * @return string 消息文本（HTML parse_mode）
     */
    public function renderTemplate(string $event, array $data): string
    {
        $time = date('Y-m-d H:i:s', $this->now());

        switch ($event) {
            case self::EVENT_LARGE_ORDER:
                return "🔔 <b>大额订单支付成功</b>\n"
                    . '商户号：' . $this->safe($data['mch_id'] ?? '') . "\n"
                    . '平台单号：' . $this->safe($data['order_no'] ?? '') . "\n"
                    . '金额：' . AmountHelper::format($data['amount'] ?? 0) . " 元\n"
                    . '时间：' . $time;

            case self::EVENT_WITHDRAW:
                return "💸 <b>提现" . $this->safe($data['status_text'] ?? '') . "</b>\n"
                    . '商户号：' . $this->safe($data['mch_id'] ?? '') . "\n"
                    . '提现单号：' . $this->safe($data['withdraw_no'] ?? '') . "\n"
                    . '金额：' . AmountHelper::format($data['amount'] ?? 0) . " 元\n"
                    . '时间：' . $time;

            case self::EVENT_EXCEPTION:
                return "⚠️ <b>系统异常告警</b>\n"
                    . '场景：' . $this->safe($data['scene'] ?? '') . "\n"
                    . '详情：' . $this->safe($data['message'] ?? '') . "\n"
                    . '时间：' . $time;

            default:
                // 未知事件：输出通用结构，避免渲染异常
                return "ℹ️ <b>支付系统通知</b>\n"
                    . '事件：' . $this->safe($event) . "\n"
                    . '时间：' . $time;
        }
    }

    // ===== 配置 / HTTP / 时间接缝：默认走真实设施，单测可重写以脱离依赖 =====

    /**
     * 读取 TG 配置（扁平 key=>value），接缝：默认从 sa_system_config 的 tg_config 分组读取
     *
     * @return array<string,string>
     */
    protected function loadConfig(): array
    {
        try {
            $group = (new SystemConfigLogic())->getGroup(self::CONFIG_GROUP) ?: [];
            $keys = ['enabled', 'bot_token', 'chat_id', 'large_amount', 'event_large_order', 'event_withdraw', 'event_exception'];
            $flat = [];
            foreach ($keys as $k) {
                $flat[$k] = (string) Arr::getConfigValue($group, $k);
            }
            return $flat;
        } catch (Throwable $e) {
            // 配置缺失/异常时返回空，shouldNotify 据空配置不推送
            return [];
        }
    }

    /**
     * 发送到 TG Bot API（表单 POST），返回 http_code 与响应正文
     *
     * @param string $url  sendMessage 完整地址
     * @param array  $body 表单参数（chat_id/text/parse_mode）
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
     * 是否显式“开”：'1'/'true'/'on'/'yes'（空值视为否）——用于默认关闭的总开关
     *
     * @param mixed $value 配置值
     * @return bool
     */
    private function isTruthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * 是否显式“关”：'0'/'false'/'off'/'no'（空值不算关）——用于默认开启的事件开关
     *
     * @param mixed $value 配置值
     * @return bool
     */
    private function isFalsy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * HTML 文本转义（防止商户名等内容破坏 TG HTML parse_mode）
     *
     * @param mixed $text 原文
     * @return string 转义后文本
     */
    private function safe(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
