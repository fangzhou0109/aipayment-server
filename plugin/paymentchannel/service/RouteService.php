<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：通道路由服务（金额规则解析 + 加权选通道）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

/**
 * 通道路由服务
 *
 * 负责「综合路由」核心逻辑：根据订单金额，从一条路由下绑定的多个通道中，
 * 按金额规则筛选出可用通道，再按配置策略选定一个通道：
 *   - weight（默认）：加权随机；
 *   - round_robin：同一路由下 Redis 队列公平轮询（见 RouteRoundRobinStore）。
 *
 * 金额规则（money_rule）两种格式，与参考项目 db_aspay_of_middle 一致：
 *   - 范围：'300-10000'  —— 订单金额在 [300,10000] 闭区间内即命中；
 *   - 固定池：'800+1000+2000' —— 订单金额须精确等于池中某一面额才命中（固定面额扫码）。
 *   - 空规则：视为不限金额，始终命中。
 *
 * 金额比较全程用 AmountHelper（decimal+bcmath），禁止浮点。
 * 规则匹配与通道选择抽为纯函数，便于充分单测、不依赖数据库。
 *
 * **sort / weight 语义**：
 *   - `route.sort`：代收选路时按倒序遍历综合路由（见 PayGatewayLogic::loadActiveRoutes）；
 *   - `route_channel.weight`：同综合路由内多通道分配权重；
 *   - `channel.money_rule` + `channel.sort`：无综合路由命中时的直连模式（先金额规则过滤，再 sort 加权）。
 */
class RouteService
{
    /** 加权随机（默认） */
    public const STRATEGY_WEIGHT = 'weight';
    /** Redis 队列轮询（需 route_id 作用域） */
    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    /**
     * 解析通道分配策略（非法值回退 weight）
     */
    public static function resolvePickStrategy(?string $strategy = null): string
    {
        $strategy = $strategy ?? (string) config('plugin.paymentchannel.app.route_pick_strategy', self::STRATEGY_WEIGHT);
        return in_array($strategy, [self::STRATEGY_WEIGHT, self::STRATEGY_ROUND_ROBIN], true)
            ? $strategy
            : self::STRATEGY_WEIGHT;
    }

    /**
     * 判断订单金额是否命中某条金额规则
     *
     * @param string $rule 金额规则（范围/固定池/空）
     * @param int|float|string $amount 订单金额（元）
     * @return bool 命中返回 true
     */
    public static function matchMoneyRule(string $rule, int|float|string $amount): bool
    {
        $rule = trim($rule);
        // 空规则 = 不限金额
        if ($rule === '') {
            return true;
        }

        // 范围规则：min-max（仅当形如 数字-数字 时按范围解析）
        if (preg_match('/^\s*([0-9.]+)\s*-\s*([0-9.]+)\s*$/', $rule, $m)) {
            $min = $m[1];
            $max = $m[2];
            // amount >= min 且 amount <= max
            return AmountHelper::compare($amount, $min) >= 0
                && AmountHelper::compare($amount, $max) <= 0;
        }

        // 固定池规则：v1+v2+v3，金额须精确等于其中某一面额
        $pool = array_filter(array_map('trim', explode('+', $rule)), fn ($v) => $v !== '');
        foreach ($pool as $value) {
            if (AmountHelper::compare($amount, $value) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 从候选通道中筛出命中金额规则的通道
     *
     * @param array<int,array{channel_id:int,money_rule:string,weight:int}> $candidates 候选（含规则与权重）
     * @param int|float|string $amount 订单金额
     * @return array<int,array{channel_id:int,money_rule:string,weight:int}> 命中的候选子集
     */
    public static function filterByAmount(array $candidates, int|float|string $amount): array
    {
        $hit = [];
        foreach ($candidates as $c) {
            if (self::matchMoneyRule((string) ($c['money_rule'] ?? ''), $amount)) {
                $hit[] = $c;
            }
        }
        return $hit;
    }

    /**
     * 按权重随机从候选中选一个通道 ID
     *
     * 权重 <=0 视为 1（保证每条至少可被选中）。返回 null 表示无候选。
     * 支持注入随机数（测试可控），默认用 random_int。
     *
     * @param array<int,array{channel_id:int,weight:int}> $candidates 候选
     * @param callable|null $randomizer function(int $max):int 返回 [1,$max] 的整数（测试注入）
     * @return int|null 命中的 channel_id，无候选返回 null
     */
    public static function pickByWeight(array $candidates, ?callable $randomizer = null): ?int
    {
        if (empty($candidates)) {
            return null;
        }
        // 计算权重总和（权重至少 1）
        $total = 0;
        foreach ($candidates as $c) {
            $w = (int) ($c['weight'] ?? 1);
            $total += $w > 0 ? $w : 1;
        }
        // 在 [1,total] 取随机点，落入各区间即命中
        $rand = $randomizer ? $randomizer($total) : random_int(1, $total);
        $acc = 0;
        foreach ($candidates as $c) {
            $w = (int) ($c['weight'] ?? 1);
            $acc += $w > 0 ? $w : 1;
            if ($rand <= $acc) {
                return (int) $c['channel_id'];
            }
        }
        // 理论不会到这；兜底返回最后一个
        return (int) end($candidates)['channel_id'];
    }

    /**
     * 按轮询从候选中选一个通道 ID（同 route_id 作用域内公平分配）
     *
     * @param array<int,array{channel_id:int,weight:int}> $candidates 候选
     * @param int $routeId 路由 ID
     * @param RouteRoundRobinStore|null $store 测试可注入内存存储
     * @return int|null 命中的 channel_id，无候选返回 null
     */
    public static function pickByRoundRobin(
        array $candidates,
        int $routeId,
        ?RouteRoundRobinStore $store = null,
    ): ?int {
        if (empty($candidates)) {
            return null;
        }
        $ids = array_map(static fn (array $c): int => (int) ($c['channel_id'] ?? 0), $candidates);
        $store ??= new RouteRoundRobinStore();

        return $store->pickAndRotate($routeId, $ids);
    }

    /**
     * 按配置策略从候选中选一个通道 ID
     *
     * round_robin 需 routeId>0；否则回退加权随机。
     *
     * @param array<int,array{channel_id:int,weight:int}> $candidates 候选
     * @param callable|null $randomizer 加权随机随机器（测试注入）
     * @param string|null $strategy 显式策略；null=读配置
     * @param int $routeId 路由 ID（轮询作用域）
     * @param RouteRoundRobinStore|null $roundRobinStore 轮询存储（测试注入）
     */
    public static function pick(
        array $candidates,
        ?callable $randomizer = null,
        ?string $strategy = null,
        int $routeId = 0,
        ?RouteRoundRobinStore $roundRobinStore = null,
    ): ?int {
        if (self::resolvePickStrategy($strategy) === self::STRATEGY_ROUND_ROBIN && $routeId > 0) {
            return self::pickByRoundRobin($candidates, $routeId, $roundRobinStore);
        }

        return self::pickByWeight($candidates, $randomizer);
    }

    /**
     * 路由选通道：先按金额过滤，再按策略选一个
     *
     * @param array<int,array{channel_id:int,money_rule:string,weight:int}> $candidates 路由下的通道候选
     * @param int|float|string $amount 订单金额
     * @param callable|null $randomizer 测试可注入的随机器（仅 weight 策略）
     * @param int $routeId 路由 ID（round_robin 必填）
     * @param string|null $strategy 显式策略；null=读配置 plugin.paymentchannel.app.route_pick_strategy
     * @param RouteRoundRobinStore|null $roundRobinStore 轮询存储（测试注入）
     * @return int|null 命中的 channel_id；无命中返回 null（上层据此降级或拒单）
     */
    public static function route(
        array $candidates,
        int|float|string $amount,
        ?callable $randomizer = null,
        int $routeId = 0,
        ?string $strategy = null,
        ?RouteRoundRobinStore $roundRobinStore = null,
    ): ?int {
        $hit = self::filterByAmount($candidates, $amount);

        return self::pick($hit, $randomizer, $strategy, $routeId, $roundRobinStore);
    }
}
