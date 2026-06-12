<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：业务单号生成器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use InvalidArgumentException;
use support\Redis;
use Throwable;

/**
 * 业务单号生成器
 *
 * 生成规则（定长 24 位，纯数字 + 1 位字母前缀，可读、可按时间排序、并发唯一）：
 *
 *     [前缀 1][日期时间 17][机器位 2][序列 4]
 *      P       20260608123059123  07        0001
 *
 *  - 前缀：业务类型，P=代收 / T=代付 / W=提现 / R=充值；
 *  - 日期时间：YmdHis + 3 位毫秒（17 位），保证趋势递增、便于按时间排序与分库分表；
 *  - 机器位：节点编号 00-99（多机部署防撞，单机默认取配置或 0）；
 *  - 序列：同一毫秒内的 Redis 原子自增序列，4 位循环（0000-9999）。
 *
 * 唯一性保证：依赖「毫秒时间戳 + 机器位 + 同毫秒 Redis 自增序列」三元组。
 * 自增 key 以「前缀+毫秒」为粒度，并设置 2 秒过期自动回收，避免 key 膨胀。
 *
 * 可测试性：真实自增走 support\Redis（INCR），但允许注入自定义「序列提供者」闭包，
 * 单元测试时用内存计数器替代，无需连接 Redis。
 */
class OrderNoGenerator
{
    /** 业务前缀：代收订单 */
    public const TYPE_PAY = 'P';
    /** 业务前缀：代付出款 */
    public const TYPE_TRANSFER = 'T';
    /** 业务前缀：商户提现 */
    public const TYPE_WITHDRAW = 'W';
    /** 业务前缀：充值 */
    public const TYPE_RECHARGE = 'R';

    /** 合法前缀白名单 */
    private const ALLOWED_TYPES = [
        self::TYPE_PAY,
        self::TYPE_TRANSFER,
        self::TYPE_WITHDRAW,
        self::TYPE_RECHARGE,
    ];

    /** 序列号位数（4 位，单毫秒内最多 1 万个） */
    private const SEQ_LENGTH = 4;

    /** 序列循环上限（10^SEQ_LENGTH） */
    private const SEQ_MOD = 10000;

    /**
     * 序列提供者（可注入，便于测试）
     * 签名：function(string $bucketKey): int —— 返回该 key 的当前自增值
     * @var callable|null
     */
    private $sequenceProvider;

    /**
     * 机器/节点编号（00-99），多机部署时由配置区分以防撞号
     * @var int
     */
    private int $machineId;

    /**
     * @param int $machineId 机器位编号，默认读取配置 plugin.paymentchannel.app.machine_id，回落 0
     * @param callable|null $sequenceProvider 自定义序列提供者（测试注入），为 null 时用 Redis
     */
    public function __construct(int $machineId = 0, ?callable $sequenceProvider = null)
    {
        // 机器位限定 0-99，超出取模，保证恒为 2 位
        $this->machineId = abs($machineId) % 100;
        $this->sequenceProvider = $sequenceProvider;
    }

    /**
     * 生成代收订单号
     * @return string 24 位单号
     */
    public function pay(): string
    {
        return $this->generate(self::TYPE_PAY);
    }

    /**
     * 生成代付单号
     * @return string 24 位单号
     */
    public function transfer(): string
    {
        return $this->generate(self::TYPE_TRANSFER);
    }

    /**
     * 生成提现单号
     * @return string 24 位单号
     */
    public function withdraw(): string
    {
        return $this->generate(self::TYPE_WITHDRAW);
    }

    /**
     * 生成充值单号
     * @return string 24 位单号
     */
    public function recharge(): string
    {
        return $this->generate(self::TYPE_RECHARGE);
    }

    /**
     * 按业务前缀生成单号
     *
     * @param string $type 业务前缀，取 TYPE_* 常量之一
     * @return string 定长 24 位业务单号
     * @throws InvalidArgumentException 当前缀非法时
     */
    public function generate(string $type): string
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('OrderNoGenerator: 非法业务前缀 [' . $type . ']');
        }

        // 1) 毫秒级时间串：YmdHis(14) + 毫秒(3) = 17 位
        [$datePart, $msBucket] = $this->datetimeParts();

        // 2) 同「前缀+毫秒」粒度的自增序列，循环取 4 位
        $bucketKey = 'pay:orderno:' . $type . $msBucket;
        $seq = $this->nextSequence($bucketKey) % self::SEQ_MOD;

        // 3) 拼装：前缀 + 时间(17) + 机器位(2) + 序列(4)
        return $type
            . $datePart
            . str_pad((string) $this->machineId, 2, '0', STR_PAD_LEFT)
            . str_pad((string) $seq, self::SEQ_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * 取当前时间的「展示用 17 位串」与「自增桶标识」
     *
     * 抽成 protected 便于测试子类覆盖固定时间。
     *
     * @return array{0:string,1:string} [YmdHis+毫秒(17位), 毫秒桶标识]
     */
    protected function datetimeParts(): array
    {
        // microtime(true) 取毫秒；用 sprintf 保证 3 位毫秒补零
        $now = microtime(true);
        $ms = (int) (($now - (int) $now) * 1000);
        $datePart = date('YmdHis', (int) $now) . str_pad((string) $ms, 3, '0', STR_PAD_LEFT);
        // 桶标识与展示串一致即可（同一毫秒共享一个自增计数）
        return [$datePart, $datePart];
    }

    /**
     * 取下一个自增序列值
     *
     * 优先使用注入的序列提供者（测试）；否则走 Redis INCR 并对 key 设置 2 秒过期。
     * Redis 不可用时降级为随机数兜底，避免阻塞下单主流程（牺牲严格自增但仍唯一性极高）。
     *
     * @param string $bucketKey 自增桶 key
     * @return int 自增值
     */
    protected function nextSequence(string $bucketKey): int
    {
        if ($this->sequenceProvider !== null) {
            return (int) ($this->sequenceProvider)($bucketKey);
        }

        try {
            $seq = (int) Redis::incr($bucketKey);
            if ($seq === 1) {
                // 首次创建该毫秒桶，设 2 秒过期自动回收（毫秒桶生命周期极短）
                Redis::expire($bucketKey, 2);
            }
            return $seq;
        } catch (Throwable $e) {
            // Redis 异常兜底：用随机序列，保证主流程不被阻断
            return random_int(1, self::SEQ_MOD - 1);
        }
    }
}
