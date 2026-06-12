<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：RouteService 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\RouteRoundRobinStore;
use plugin\paymentchannel\service\RouteService;

/**
 * RouteService 通道路由测试
 *
 * 路由是综合路由的核心逻辑，需充分覆盖：金额规则匹配（范围/固定池/空/边界）、
 * 按金额过滤、加权选择（注入随机器保证确定性）、无命中降级。
 */
class RouteServiceTest extends TestCase
{
    /**
     * 范围规则：闭区间命中，区间外不命中
     */
    public function testRangeRule(): void
    {
        $this->assertTrue(RouteService::matchMoneyRule('300-10000', 300));   // 下边界
        $this->assertTrue(RouteService::matchMoneyRule('300-10000', 10000)); // 上边界
        $this->assertTrue(RouteService::matchMoneyRule('300-10000', 5000));
        $this->assertFalse(RouteService::matchMoneyRule('300-10000', 299.99));
        $this->assertFalse(RouteService::matchMoneyRule('300-10000', 10000.01));
    }

    /**
     * 固定池规则：精确等于某面额才命中
     */
    public function testFixedPoolRule(): void
    {
        $rule = '800+1000+2000+5000';
        $this->assertTrue(RouteService::matchMoneyRule($rule, 1000));
        $this->assertTrue(RouteService::matchMoneyRule($rule, 5000));
        $this->assertFalse(RouteService::matchMoneyRule($rule, 1500)); // 不在池中
        $this->assertFalse(RouteService::matchMoneyRule($rule, 999));
    }

    /**
     * 空规则：不限金额，始终命中
     */
    public function testEmptyRuleAlwaysMatch(): void
    {
        $this->assertTrue(RouteService::matchMoneyRule('', 1));
        $this->assertTrue(RouteService::matchMoneyRule('   ', 999999));
    }

    /**
     * 小数金额与小数规则（decimal 精度，借助 AmountHelper）
     */
    public function testDecimalAmount(): void
    {
        $this->assertTrue(RouteService::matchMoneyRule('0.01-100.50', '100.5'));
        $this->assertFalse(RouteService::matchMoneyRule('0.01-100.50', '100.51'));
        $this->assertTrue(RouteService::matchMoneyRule('9.9+19.9', '19.9'));
    }

    /**
     * 按金额过滤候选
     */
    public function testFilterByAmount(): void
    {
        $candidates = [
            ['channel_id' => 1, 'money_rule' => '300-1000', 'weight' => 1],
            ['channel_id' => 2, 'money_rule' => '1000+2000', 'weight' => 1],
            ['channel_id' => 3, 'money_rule' => '', 'weight' => 1], // 不限
        ];
        // 金额 500：命中 #1（范围）与 #3（不限）
        $hit = RouteService::filterByAmount($candidates, 500);
        $ids = array_column($hit, 'channel_id');
        $this->assertSame([1, 3], $ids);

        // 金额 2000：命中 #2（固定池）与 #3
        $hit2 = RouteService::filterByAmount($candidates, 2000);
        $this->assertSame([2, 3], array_column($hit2, 'channel_id'));
    }

    /**
     * 加权选择：注入随机器使结果确定
     * 权重 [2,3]，total=5；rand=1..2→#1，rand=3..5→#2
     */
    public function testPickByWeightDeterministic(): void
    {
        $candidates = [
            ['channel_id' => 10, 'weight' => 2],
            ['channel_id' => 20, 'weight' => 3],
        ];
        $this->assertSame(10, RouteService::pickByWeight($candidates, fn ($max) => 1));
        $this->assertSame(10, RouteService::pickByWeight($candidates, fn ($max) => 2));
        $this->assertSame(20, RouteService::pickByWeight($candidates, fn ($max) => 3));
        $this->assertSame(20, RouteService::pickByWeight($candidates, fn ($max) => 5));
    }

    /**
     * 权重 <=0 视为 1
     */
    public function testWeightZeroTreatedAsOne(): void
    {
        $candidates = [
            ['channel_id' => 10, 'weight' => 0],
            ['channel_id' => 20, 'weight' => 0],
        ];
        // total=2；rand=1→#1，rand=2→#2
        $this->assertSame(10, RouteService::pickByWeight($candidates, fn ($max) => 1));
        $this->assertSame(20, RouteService::pickByWeight($candidates, fn ($max) => 2));
    }

    /**
     * 无候选返回 null
     */
    public function testPickEmptyReturnsNull(): void
    {
        $this->assertNull(RouteService::pickByWeight([], fn ($max) => 1));
    }

    /**
     * route()：过滤+选择完整链路；无命中降级为 null
     */
    public function testRouteEndToEnd(): void
    {
        $candidates = [
            ['channel_id' => 1, 'money_rule' => '300-1000', 'weight' => 1],
            ['channel_id' => 2, 'money_rule' => '5000+8000', 'weight' => 1],
        ];
        // 金额 500 仅命中 #1
        $this->assertSame(1, RouteService::route($candidates, 500, fn ($max) => 1));
        // 金额 9999 无命中 → null（上层据此降级或拒单）
        $this->assertNull(RouteService::route($candidates, 9999, fn ($max) => 1));
    }

    /**
     * 内存轮询存储（单测不依赖 Redis）
     *
     * @return array{0:RouteRoundRobinStore,1:array<string,int[]>}
     */
    private function memoryRoundRobinStore(): array
    {
        $data = [];
        // loader/saver 均须 use (&$data)，否则 PHP 按值绑定，队列写读不同步
        $store = new RouteRoundRobinStore(
            loader: function (string $key) use (&$data): array {
                return $data[$key] ?? [];
            },
            saver: function (string $key, array $ids) use (&$data): void {
                $data[$key] = $ids;
            },
        );

        return [$store, &$data];
    }

    /**
     * 轮询：同一路由下按队列顺序循环命中 A→B→C→A
     */
    public function testRoundRobinCyclesThroughCandidates(): void
    {
        [$store] = $this->memoryRoundRobinStore();
        $candidates = [
            ['channel_id' => 10, 'weight' => 1],
            ['channel_id' => 20, 'weight' => 1],
            ['channel_id' => 30, 'weight' => 1],
        ];

        $this->assertSame(10, RouteService::pickByRoundRobin($candidates, 7, $store));
        $this->assertSame(20, RouteService::pickByRoundRobin($candidates, 7, $store));
        $this->assertSame(30, RouteService::pickByRoundRobin($candidates, 7, $store));
        $this->assertSame(10, RouteService::pickByRoundRobin($candidates, 7, $store));
    }

    /**
     * 轮询：仅参与金额过滤后的候选子集
     */
    public function testRoundRobinRespectsAmountFilter(): void
    {
        [$store] = $this->memoryRoundRobinStore();
        $candidates = [
            ['channel_id' => 10, 'money_rule' => '300-1000', 'weight' => 1],
            ['channel_id' => 20, 'money_rule' => '5000+8000', 'weight' => 1],
            ['channel_id' => 30, 'money_rule' => '', 'weight' => 1],
        ];

        // 金额 500：仅 #10 与 #30 命中；轮询在二者间交替
        $this->assertSame(10, RouteService::route(
            $candidates,
            500,
            null,
            7,
            RouteService::STRATEGY_ROUND_ROBIN,
            $store,
        ));
        $this->assertSame(30, RouteService::route(
            $candidates,
            500,
            null,
            7,
            RouteService::STRATEGY_ROUND_ROBIN,
            $store,
        ));
        $this->assertSame(10, RouteService::route(
            $candidates,
            500,
            null,
            7,
            RouteService::STRATEGY_ROUND_ROBIN,
            $store,
        ));
    }

    /**
     * 轮询：运行期新增候选会追加到队列末尾，轮到队首时才被选中
     */
    public function testRoundRobinMergesNewCandidate(): void
    {
        [$store] = $this->memoryRoundRobinStore();
        $base = [
            ['channel_id' => 10, 'weight' => 1],
            ['channel_id' => 20, 'weight' => 1],
        ];
        RouteService::pickByRoundRobin($base, 5, $store);
        RouteService::pickByRoundRobin($base, 5, $store);

        $extended = array_merge($base, [['channel_id' => 30, 'weight' => 1]]);
        // 队列 [10,20] + 新 30 入尾 → 仍先消费 10、20，再轮到 30
        $this->assertSame(10, RouteService::pickByRoundRobin($extended, 5, $store));
        $this->assertSame(20, RouteService::pickByRoundRobin($extended, 5, $store));
        $this->assertSame(30, RouteService::pickByRoundRobin($extended, 5, $store));
    }

    /**
     * 轮询：单候选恒返回该通道
     */
    public function testRoundRobinSingleCandidate(): void
    {
        [$store] = $this->memoryRoundRobinStore();
        $candidates = [['channel_id' => 42, 'weight' => 5]];

        $this->assertSame(42, RouteService::pickByRoundRobin($candidates, 1, $store));
        $this->assertSame(42, RouteService::pickByRoundRobin($candidates, 1, $store));
    }

    /**
     * 默认/显式 weight 策略仍走加权随机
     */
    public function testPickUsesWeightStrategyByDefault(): void
    {
        $candidates = [
            ['channel_id' => 10, 'weight' => 2],
            ['channel_id' => 20, 'weight' => 3],
        ];
        $this->assertSame(RouteService::STRATEGY_WEIGHT, RouteService::resolvePickStrategy(null));
        $this->assertSame(RouteService::STRATEGY_WEIGHT, RouteService::resolvePickStrategy('invalid'));
        $this->assertSame(20, RouteService::pick($candidates, fn ($max) => 5, RouteService::STRATEGY_WEIGHT, 7));
    }

    /**
     * route() 显式 round_robin 与 weight 可切换且可重复
     */
    public function testRouteStrategySwitchRepeatable(): void
    {
        [$store] = $this->memoryRoundRobinStore();
        $candidates = [
            ['channel_id' => 1, 'money_rule' => '', 'weight' => 1],
            ['channel_id' => 2, 'money_rule' => '', 'weight' => 1],
        ];

        $this->assertSame(1, RouteService::route($candidates, 100, null, 9, RouteService::STRATEGY_ROUND_ROBIN, $store));
        $this->assertSame(2, RouteService::route($candidates, 100, null, 9, RouteService::STRATEGY_ROUND_ROBIN, $store));
        $this->assertSame(1, RouteService::route($candidates, 100, fn ($max) => 1, 9, RouteService::STRATEGY_WEIGHT));
    }
}
