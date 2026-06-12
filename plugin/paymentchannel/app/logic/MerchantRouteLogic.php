<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-路由授权逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\MerchantRoute;
use plugin\paymentchannel\app\model\Route;
use plugin\paymentchannel\app\validate\MerchantRouteValidate;
use plugin\saiadmin\exception\ApiException;

/**
 * 商户-路由授权逻辑层
 */
class MerchantRouteLogic extends PaymentBaseLogic
{
    public function __construct()
    {
        $this->model = new MerchantRoute();
    }

    /**
     * 按商户列出路由授权（含路由只读参考字段）
     *
     * @param int $merchantId 商户ID
     * @return array<int,array>
     */
    public function listByMerchant(int $merchantId): array
    {
        $bindings = $this->loadBindingsByMerchant($merchantId);
        $bindingMap = [];
        foreach ($bindings as $row) {
            $bindingMap[(int) ($row['route_id'] ?? 0)] = $row;
        }

        $result = [];
        foreach ($this->loadActiveRoutes() as $route) {
            $routeId = (int) ($route['id'] ?? 0);
            $result[] = $this->formatListRow($bindingMap[$routeId] ?? null, $route);
        }

        return $result;
    }

    /**
     * 批量保存商户×路由绑定（upsert）
     *
     * @param int $merchantId 商户ID
     * @param array $rows 每项含 route_id、status
     * @return array{saved:int}
     */
    public function batchBind(int $merchantId, array $rows): array
    {
        if ($merchantId <= 0) {
            throw new ApiException('商户ID无效');
        }

        $saved = 0;

        return $this->transaction(function () use ($merchantId, $rows, &$saved) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new ApiException('绑定数据格式非法');
                }

                $data = [
                    'merchant_id' => $merchantId,
                    'route_id'    => (int) ($row['route_id'] ?? 0),
                    'status'      => (int) ($row['status'] ?? MerchantRoute::STATUS_NORMAL),
                ];
                $this->validateRow($data, 'save');

                $routeId = (int) $data['route_id'];
                if ($this->loadRouteForList($routeId) === null) {
                    throw new ApiException('路由不存在：' . $routeId);
                }

                $existing = $this->findBinding($merchantId, $routeId);
                if ($existing !== null) {
                    $this->edit((int) $existing['id'], $data);
                } else {
                    $this->add($data);
                }
                $saved++;
            }

            return ['saved' => $saved];
        });
    }

    protected function validateRow(array $data, string $scene = 'save'): void
    {
        $validator = new MerchantRouteValidate();
        if (!$validator->scene($scene)->check($data)) {
            throw new ApiException($validator->getError());
        }
    }

    protected function formatListRow(?array $binding, array $route): array
    {
        return [
            'id'              => (int) ($binding['id'] ?? 0),
            'merchant_id'     => (int) ($binding['merchant_id'] ?? 0),
            'route_id'        => (int) ($route['id'] ?? 0),
            'status'          => (int) ($binding['status'] ?? MerchantRoute::STATUS_DISABLED),
            'remark'          => (string) ($binding['remark'] ?? ''),
            'route_title'     => (string) ($route['title'] ?? ''),
            'route_pay_type'  => (int) ($route['pay_type'] ?? 0),
        ];
    }

    protected function loadBindingsByMerchant(int $merchantId): array
    {
        return MerchantRoute::where('merchant_id', $merchantId)
            ->order('create_time', 'desc')
            ->select()
            ->toArray();
    }

    protected function loadActiveRoutes(): array
    {
        return Route::where('status', 1)
            ->order('sort', 'desc')
            ->order('create_time', 'desc')
            ->field('id,title,pay_type,sort,status')
            ->select()
            ->toArray();
    }

    protected function loadRouteForList(int $routeId): ?array
    {
        $row = Route::where('id', $routeId)->field('id,title,pay_type,status')->find();
        return $row ? $row->toArray() : null;
    }

    protected function findBinding(int $merchantId, int $routeId): ?array
    {
        $row = MerchantRoute::where('merchant_id', $merchantId)
            ->where('route_id', $routeId)
            ->find();

        return $row ? $row->toArray() : null;
    }
}
