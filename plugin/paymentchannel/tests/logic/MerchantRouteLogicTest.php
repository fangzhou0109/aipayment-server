<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-路由授权逻辑测试（批量绑定，脱离 DB）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\MerchantRouteLogic;
use plugin\paymentchannel\app\model\MerchantRoute;
use plugin\paymentchannel\app\validate\MerchantRouteValidate;
use plugin\saiadmin\exception\ApiException;

/**
 * 可测试逻辑：内存替代绑定表与路由表
 */
class TestableMerchantRouteLogic extends MerchantRouteLogic
{
    /** merchantId => routeId => binding row */
    public array $bindings = [];
    /** routeId => route row */
    public array $routes = [];
    public array $created = [];
    public array $updated = [];
    private int $autoId = 9000;

    public function __construct()
    {
        // 跳过父构造，避免实例化 ORM
    }

    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $closure();
    }

    protected function validateRow(array $data, string $scene = 'save'): void
    {
        $validator = new MerchantRouteValidate();
        if (!$validator->scene($scene)->check($data)) {
            throw new ApiException($validator->getError());
        }
    }

    protected function loadBindingsByMerchant(int $merchantId): array
    {
        $rows = [];
        foreach ($this->bindings[$merchantId] ?? [] as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    protected function loadActiveRoutes(): array
    {
        return array_values($this->routes);
    }

    protected function loadRouteForList(int $routeId): ?array
    {
        return $this->routes[$routeId] ?? null;
    }

    protected function findBinding(int $merchantId, int $routeId): ?array
    {
        return $this->bindings[$merchantId][$routeId] ?? null;
    }

    public function add(array $data): mixed
    {
        $id = ++$this->autoId;
        $merchantId = (int) $data['merchant_id'];
        $routeId = (int) $data['route_id'];
        $row = array_merge($data, ['id' => $id]);
        $this->bindings[$merchantId][$routeId] = $row;
        $this->created[] = $row;
        return $id;
    }

    public function edit($id, array $data): mixed
    {
        foreach ($this->bindings as $mid => &$byRoute) {
            foreach ($byRoute as $rid => &$row) {
                if ((int) $row['id'] === (int) $id) {
                    $row = array_merge($row, $data, ['id' => (int) $id]);
                    $byRoute[$rid] = $row;
                    $this->updated[] = $row;
                    return true;
                }
            }
        }
        throw new ApiException('数据不存在');
    }
}

/**
 * MerchantRouteLogic 批量绑定与列表测试
 */
class MerchantRouteLogicTest extends TestCase
{
    private function route(int $id, array $override = []): array
    {
        return array_merge([
            'id'       => $id,
            'title'    => '路由' . $id,
            'pay_type' => 3,
            'rate'     => '2.0000',
            'sort'     => 100,
            'status'   => 1,
        ], $override);
    }

    private function logic(): TestableMerchantRouteLogic
    {
        $logic = new TestableMerchantRouteLogic();
        $logic->routes = [
            10 => $this->route(10),
            20 => $this->route(20, ['title' => 'VIP路由']),
        ];
        return $logic;
    }

    /**
     * batchBind 新增绑定
     */
    public function testBatchBindCreatesNew(): void
    {
        $logic = $this->logic();
        $result = $logic->batchBind(1, [
            ['route_id' => 10, 'status' => MerchantRoute::STATUS_NORMAL],
        ]);

        $this->assertSame(1, $result['saved']);
        $this->assertCount(1, $logic->created);
        $this->assertSame(1, $logic->created[0]['merchant_id']);
        $this->assertSame(10, $logic->created[0]['route_id']);
    }

    /**
     * batchBind 更新已有绑定
     */
    public function testBatchBindUpdatesExisting(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][10] = [
            'id' => 8001, 'merchant_id' => 1, 'route_id' => 10, 'status' => MerchantRoute::STATUS_DISABLED,
        ];

        $result = $logic->batchBind(1, [
            ['route_id' => 10, 'status' => MerchantRoute::STATUS_NORMAL],
        ]);

        $this->assertSame(1, $result['saved']);
        $this->assertCount(1, $logic->updated);
        $this->assertSame(MerchantRoute::STATUS_NORMAL, $logic->updated[0]['status']);
    }

    /**
     * listByMerchant 返回全部启用路由及绑定状态
     */
    public function testListByMerchantIncludesAllActiveRoutes(): void
    {
        $logic = $this->logic();
        $logic->bindings[1][10] = [
            'id' => 8001, 'merchant_id' => 1, 'route_id' => 10, 'status' => MerchantRoute::STATUS_NORMAL,
        ];

        $list = $logic->listByMerchant(1);
        $this->assertCount(2, $list);

        $byRoute = [];
        foreach ($list as $row) {
            $byRoute[(int) $row['route_id']] = $row;
        }
        $this->assertSame(MerchantRoute::STATUS_NORMAL, $byRoute[10]['status']);
        $this->assertSame(MerchantRoute::STATUS_DISABLED, $byRoute[20]['status']);
        $this->assertSame('VIP路由', $byRoute[20]['route_title']);
    }

    /**
     * 非法路由 ID 拒绝绑定
     */
    public function testBatchBindRejectsUnknownRoute(): void
    {
        $logic = $this->logic();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('路由不存在');
        $logic->batchBind(1, [
            ['route_id' => 999, 'status' => 1],
        ]);
    }
}
