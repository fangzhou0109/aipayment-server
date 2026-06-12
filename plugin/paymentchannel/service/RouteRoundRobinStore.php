<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：路由内通道轮询状态（Redis 队列，参考 reference aspid_pid_list）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use Closure;
use Redis as PhpRedis;
use Throwable;

/**
 * 路由内通道轮询状态存储（Phase 9.3.2）
 *
 * 参考 reference `payCtrl`：`{route_id}_pid_list` 维护 channel_id 队列，每次从队首取
 * 第一个落在当前候选集内的通道，选中后移到队尾实现公平轮询。
 *
 * Redis 键：`route_rr:{route_id}`，值为 JSON 数组 `[channel_id, ...]`。
 *
 * 可测试性：注入 loader/saver 闭包即可内存模拟，单测不依赖 Redis。
 * Redis 异常时 fail-open：返回候选集第一个 channel_id，不阻断支付。
 */
class RouteRoundRobinStore
{
    private const KEY_PREFIX = 'route_rr';

    /**
     * @param Closure|null $loader fn(string $key): int[] 读取轮询队列
     * @param Closure|null $saver  fn(string $key, int[] $ids): void 持久化队列
     */
    public function __construct(
        private ?Closure $loader = null,
        private ?Closure $saver = null,
    ) {
    }

    /**
     * 构建 Redis 键
     */
    public function buildKey(int $routeId): string
    {
        return self::KEY_PREFIX . ':' . $routeId;
    }

    /**
     * 从候选 channel_id 中轮询选一个，并旋转队列
     *
     * @param int $routeId 路由 ID（轮询作用域）
     * @param int[] $candidateIds 金额过滤后的候选 channel_id（保序、去重）
     * @return int|null 命中的 channel_id；无候选返回 null
     */
    public function pickAndRotate(int $routeId, array $candidateIds): ?int
    {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        if ($candidateIds === []) {
            return null;
        }
        if (count($candidateIds) === 1) {
            return $candidateIds[0];
        }

        $key = $this->buildKey($routeId);
        $candidateSet = array_fill_keys($candidateIds, true);

        try {
            $list = $this->loadList($key);
        } catch (Throwable) {
            return $candidateIds[0];
        }

        // 新候选追加到队列末尾；已不在候选集的 id 从队列剔除
        foreach ($candidateIds as $id) {
            if (!in_array($id, $list, true)) {
                $list[] = $id;
            }
        }
        $list = array_values(array_filter(
            $list,
            static fn (int $id): bool => isset($candidateSet[$id])
        ));
        if ($list === []) {
            $list = $candidateIds;
        }

        $picked = null;
        $rotated = $list;
        foreach ($list as $idx => $id) {
            if (!isset($candidateSet[$id])) {
                continue;
            }
            $picked = $id;
            $rotated = $list;
            array_splice($rotated, $idx, 1);
            $rotated[] = $picked;
            break;
        }

        if ($picked === null) {
            $picked = $candidateIds[0];
            $rotated = $this->rotateAfterPick($candidateIds, $picked);
        }

        try {
            $this->saveList($key, $rotated);
        } catch (Throwable) {
            // 已选出通道，队列写回失败不阻断主流程
        }

        return $picked;
    }

    /**
     * 将已选 id 移到队列末尾
     *
     * @param int[] $list
     */
    protected function rotateAfterPick(array $list, int $picked): array
    {
        $list = array_values($list);
        $pos = array_search($picked, $list, true);
        if ($pos !== false) {
            array_splice($list, (int) $pos, 1);
        }
        $list[] = $picked;
        return $list;
    }

    /**
     * @return int[]
     */
    protected function loadList(string $key): array
    {
        if ($this->loader !== null) {
            $raw = ($this->loader)($key);
            return is_array($raw) ? array_map('intval', $raw) : [];
        }

        $raw = $this->redis()->get($key);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * @param int[] $ids
     */
    protected function saveList(string $key, array $ids): void
    {
        $ids = array_values(array_map('intval', $ids));
        if ($this->saver !== null) {
            ($this->saver)($key, $ids);
            return;
        }
        $this->redis()->set($key, json_encode($ids, JSON_UNESCAPED_UNICODE));
    }

    /** 惰性 phpredis 连接（同 RateLimitService） */
    private ?PhpRedis $redis = null;

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

        return $this->redis;
    }
}
