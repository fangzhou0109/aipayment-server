<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：限流服务（Redis 固定窗口计数 / OWASP 防超频暴力）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use Closure;
use Redis as PhpRedis;
use Throwable;

/**
 * 限流服务（Redis 固定窗口计数器）
 *
 * 防止商户网关被超频调用 / 暴力枚举（对应 OWASP A04 不安全设计、A07 身份认证失败的
 * 防御纵深）。对「键 + 时间窗口」计数：窗口内首次命中设过期时间，后续自增；
 * 计数超过上限则拒绝。
 *
 * 关键安全取舍 —— **失败放行（fail-open）**：限流是「保护性」而非「资金性」控制，
 * 一旦 Redis 不可用，绝不能因此阻断支付主流程，故计数异常时一律放行。
 *
 * ⚠️ 连接方式（踩坑修复）：本服务直接用 **phpredis 原生 `\Redis`** 连接，而非 webman 的
 * `support\Redis` 门面。原因：`webman/redis` v2 的 `RedisManager::connection()` 一律走
 * `Workerman\Coroutine\Pool` + `Webman\Context` 协程连接池；在「非协程」的常驻 worker 里
 * 该路径会抛异常 → 被 {@see self::hit()} 吞掉 → 限流静默失效（CLI 无事件循环反而正常，
 * 故极隐蔽）。改用原生 `\Redis` 直连，绕过协程池，在 worker 与 CLI 下均可用、计数原子可靠。
 *
 * 可测试性：计数动作抽为可注入闭包 {@see $counter}（fn(string $key, int $window): int 返回窗口内当前计数），
 * 单测注入内存计数器，不触发真实 Redis。
 */
class RateLimitService
{
    /**
     * 计数器闭包：fn(string $key, int $window): int —— 自增并返回窗口内当前计数。
     * null=用默认 phpredis 实现。
     * @var Closure|null
     */
    private ?Closure $counter;

    /**
     * 惰性持有的原生 phpredis 连接（worker 内单例复用；掉线时置 null 下次重连）。
     * @var PhpRedis|null
     */
    private ?PhpRedis $redis = null;

    /**
     * @param Closure|null $counter 计数器（测试可注入内存实现）
     */
    public function __construct(?Closure $counter = null)
    {
        $this->counter = $counter;
    }

    /**
     * 命中一次并判定是否放行
     *
     * @param string $key    限流键（如 pay:rate:{mch_id}:{path}）
     * @param int    $max    窗口内允许的最大次数
     * @param int    $window 时间窗口（秒）
     * @return bool true=放行（未超限）；false=拒绝（已超限）
     */
    public function hit(string $key, int $max, int $window): bool
    {
        // 上限非正 → 视为不限流，直接放行（避免误配把商户锁死）
        if ($max <= 0) {
            return true;
        }
        try {
            $count = $this->incr($key, $window);
        } catch (Throwable) {
            // Redis 异常：失败放行，绝不阻断支付主流程
            return true;
        }
        return $count <= $max;
    }

    /**
     * 自增计数并返回窗口内当前值（接缝：默认 phpredis 直连，测试可注入/重写）
     *
     * 用 Redis INCR 原子自增；当计数为 1（窗口内首次）时设置过期时间，实现「固定窗口」。
     * 任一 Redis 操作异常时，置空连接（下次重连）并向上抛出，由 {@see self::hit()} 失败放行。
     *
     * @param string $key    限流键
     * @param int    $window 窗口（秒）
     * @return int 当前计数
     */
    protected function incr(string $key, int $window): int
    {
        if ($this->counter !== null) {
            return (int) ($this->counter)($key, $window);
        }
        try {
            $redis = $this->redis();
            $count = (int) $redis->incr($key);
            if ($count === 1) {
                // 窗口内首次命中：设置过期时间，到期自动重置计数
                $redis->expire($key, $window);
            }
            return $count;
        } catch (Throwable $e) {
            // 连接可能已失效：置空以便下次重连；异常上抛交 hit() 失败放行
            $this->redis = null;
            throw $e;
        }
    }

    /**
     * 获取原生 phpredis 连接（worker 内惰性单例，掉线后由 {@see self::incr()} 置空重连）
     *
     * 直连 redis-server，绕过 webman/redis 的协程连接池（见类注释「踩坑修复」）。
     * 连接参数读自 config/redis.php 的 `default` 连接（host/port/password/database）。
     *
     * @return PhpRedis 可用的 phpredis 连接
     */
    protected function redis(): PhpRedis
    {
        if ($this->redis instanceof PhpRedis) {
            return $this->redis;
        }
        $conf = (array) config('redis.default', []);
        $redis = new PhpRedis();
        // 1.5s 连接超时：localhost redis 足够，避免异常时长时间挂起请求
        $redis->connect((string) ($conf['host'] ?? '127.0.0.1'), (int) ($conf['port'] ?? 6379), 1.5);
        $password = (string) ($conf['password'] ?? '');
        if ($password !== '') {
            $redis->auth($password);
        }
        $database = (int) ($conf['database'] ?? 0);
        if ($database > 0) {
            $redis->select($database);
        }
        $this->redis = $redis;
        return $redis;
    }
}
