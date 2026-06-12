<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户×通道日累计限额服务（Redis 固定点累加）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use Closure;
use plugin\paymentchannel\app\exception\PaymentException;
use Redis as PhpRedis;
use Throwable;

/**
 * 商户×通道日累计限额服务（Phase 9.2.3）
 *
 * Redis 键：`mc_day:{merchant_id}:{channel_id}:{Ymd}`，值为当日累计下单金额（定点整数，×10000）。
 *
 * 流程（与 PayGatewayLogic 配合）：
 *  1) {@see checkDayLimit}：选路后、事务前只读预检（累计+本次 ≤ day_limit）；
 *  2) {@see reserveDayAmount}：persistOrder 成功后原子占用（建单即占额，防刷）；
 *  3) {@see releaseDayAmount}：上游失败时回滚占用（DB 事务回滚后 Redis 补偿扣减）。
 *
 * day_limit=0 表示不限；模块可通过配置 `day_limit.enable=false` 关闭。
 *
 * 安全取舍 —— **失败关闭（fail-close）**：日限额是资金风控，Redis 异常时拒绝下单并提示稍后重试，
 * 与限流服务 fail-open 相反；仅影响日限模块，不波及其他支付逻辑。
 *
 * 连接方式同 {@see RateLimitService}：原生 phpredis 直连，绕过 webman 协程连接池。
 */
class DayLimitService
{
    /** Redis 值定点倍数（与 AmountHelper::SCALE=4 对齐） */
    private const FIXED_SCALE = 10000;

    /** 键前缀 */
    private const KEY_PREFIX = 'mc_day';

    /**
     * 是否启用（null=读配置 plugin.paymentchannel.app.day_limit.enable）
     */
    private ?bool $enabled;

    /**
     * 日期键提供者：fn(): string 返回 Ymd
     */
    private ?Closure $dateProvider;

    /**
     * 只读累计：fn(string $key): string 返回当日已占用金额（元，decimal 字符串）
     */
    private ?Closure $reader;

    /**
     * 原子占用：fn(string $key, string $amount, string $limit, int $ttl): bool
     */
    private ?Closure $reserver;

    /**
     * 释放占用：fn(string $key, string $amount): void
     */
    private ?Closure $releaser;

    /** 惰性 phpredis 连接 */
    private ?PhpRedis $redis = null;

    /**
     * @param bool|null $enabled 是否启用；null=读配置
     * @param Closure|null $dateProvider 日期键（单测可固定 Ymd）
     * @param Closure|null $reader 只读累计（单测内存）
     * @param Closure|null $reserver 原子占用（单测内存）
     * @param Closure|null $releaser 释放占用（单测内存）
     */
    public function __construct(
        ?bool $enabled = null,
        ?Closure $dateProvider = null,
        ?Closure $reader = null,
        ?Closure $reserver = null,
        ?Closure $releaser = null,
    ) {
        $this->enabled = $enabled;
        $this->dateProvider = $dateProvider;
        $this->reader = $reader;
        $this->reserver = $reserver;
        $this->releaser = $releaser;
    }

    /**
     * 构建 Redis 键
     */
    public function buildKey(int $merchantId, int $channelId, ?string $dateYmd = null): string
    {
        $date = $dateYmd ?? $this->currentDateYmd();
        return self::KEY_PREFIX . ':' . $merchantId . ':' . $channelId . ':' . $date;
    }

    /**
     * 只读预检：当日累计 + 本次金额不得超过 day_limit
     *
     * @throws PaymentException 超限或 Redis 不可用时
     */
    public function checkDayLimit(int $merchantId, int $channelId, string $dayLimit, string $amount): void
    {
        if (!$this->isEnabled() || !AmountHelper::gtZero($dayLimit)) {
            return;
        }

        $key = $this->buildKey($merchantId, $channelId);
        try {
            $current = $this->readAmount($key);
        } catch (Throwable) {
            throw new PaymentException('日限额服务暂不可用，请稍后重试');
        }

        $projected = AmountHelper::add($current, $amount);
        if (AmountHelper::compare($projected, $dayLimit) > 0) {
            throw new PaymentException('已超过通道日累计限额');
        }
    }

    /**
     * 建单成功后原子占用当日额度（并发安全）
     *
     * @throws PaymentException 超限或 Redis 不可用时
     */
    public function reserveDayAmount(int $merchantId, int $channelId, string $dayLimit, string $amount): void
    {
        if (!$this->isEnabled() || !AmountHelper::gtZero($dayLimit)) {
            return;
        }

        $key = $this->buildKey($merchantId, $channelId);
        $ttl = $this->ttlUntilEndOfDay();

        try {
            $ok = $this->atomicReserve($key, $amount, $dayLimit, $ttl);
        } catch (Throwable) {
            throw new PaymentException('日限额服务暂不可用，请稍后重试');
        }

        if (!$ok) {
            throw new PaymentException('已超过通道日累计限额');
        }
    }

    /**
     * 上游失败时释放已占用额度（与 DB 回滚配套）
     */
    public function releaseDayAmount(int $merchantId, int $channelId, string $amount): void
    {
        if (!$this->isEnabled() || !AmountHelper::gtZero($amount)) {
            return;
        }

        $key = $this->buildKey($merchantId, $channelId);
        try {
            $this->atomicRelease($key, $amount);
        } catch (Throwable) {
            // 释放失败不向上抛：订单已回滚，日限多占会在 TTL 到期后自然清零；记日志留待运维
        }
    }

    /**
     * 模块是否启用
     */
    public function isEnabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }
        return (bool) config('plugin.paymentchannel.app.day_limit.enable', true);
    }

    /**
     * 当前自然日 Ymd（可注入）
     */
    protected function currentDateYmd(): string
    {
        if ($this->dateProvider !== null) {
            return (string) ($this->dateProvider)();
        }
        return date('Ymd');
    }

    /**
     * 距当日结束的 TTL（秒），供 Redis EXPIRE
     */
    protected function ttlUntilEndOfDay(?int $now = null): int
    {
        $now ??= time();
        $tomorrow = strtotime('tomorrow', $now);
        return max(60, (int) ($tomorrow - $now));
    }

    /**
     * 读取键对应当日累计（元）
     */
    protected function readAmount(string $key): string
    {
        if ($this->reader !== null) {
            return AmountHelper::format((string) ($this->reader)($key));
        }

        $redis = $this->redis();
        $raw = $redis->get($key);
        if ($raw === false || $raw === null || $raw === '') {
            return AmountHelper::format('0');
        }
        return $this->fixedToAmount((int) $raw);
    }

    /**
     * 原子占用：INCRBY 后若超 limit 则回滚
     */
    protected function atomicReserve(string $key, string $amount, string $dayLimit, int $ttl): bool
    {
        if ($this->reserver !== null) {
            return (bool) ($this->reserver)($key, $amount, $dayLimit, $ttl);
        }

        $addFixed = $this->amountToFixed($amount);
        $limitFixed = $this->amountToFixed($dayLimit);

        $redis = $this->redis();
        $newVal = (int) $redis->incrBy($key, $addFixed);
        if ($newVal === $addFixed) {
            $redis->expire($key, $ttl);
        }
        if ($newVal > $limitFixed) {
            $redis->incrBy($key, -$addFixed);
            return false;
        }
        return true;
    }

    /**
     * 原子释放：扣减已占用
     */
    protected function atomicRelease(string $key, string $amount): void
    {
        if ($this->releaser !== null) {
            ($this->releaser)($key, $amount);
            return;
        }

        $subFixed = $this->amountToFixed($amount);
        $redis = $this->redis();
        $newVal = (int) $redis->decrBy($key, $subFixed);
        if ($newVal <= 0) {
            $redis->del($key);
        }
    }

    /**
     * 金额（元）→ Redis 定点整数
     */
    protected function amountToFixed(string $amount): int
    {
        return (int) bcmul(AmountHelper::format($amount), (string) self::FIXED_SCALE, 0);
    }

    /**
     * Redis 定点整数 → 金额（元）
     */
    protected function fixedToAmount(int $fixed): string
    {
        return AmountHelper::format(bcdiv((string) $fixed, (string) self::FIXED_SCALE, AmountHelper::SCALE));
    }

    /**
     * 原生 phpredis 连接（同 RateLimitService 踩坑修复）
     */
    protected function redis(): PhpRedis
    {
        if ($this->redis instanceof PhpRedis) {
            return $this->redis;
        }
        $conf = (array) config('redis.default', []);
        $redis = new PhpRedis();
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
