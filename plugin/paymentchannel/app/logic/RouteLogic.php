<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：综合路由逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\model\Route;
use plugin\paymentchannel\app\model\RouteChannel;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\RateResolver;
use plugin\paymentchannel\service\RouteService;
use plugin\saiadmin\exception\ApiException;

/**
 * 综合路由逻辑层
 *
 * 维护路由（pay_type 对应一组通道）；并提供「按金额试算命中通道」的调试方法，
 * 供后台调试接口验证路由规则配置是否符合预期。
 *
 * Phase 9.1.6：试算可带 merchantId，走与生产一致的授权过滤 + RateResolver 费率预览。
 */
class RouteLogic extends PaymentBaseLogic
{
    private ?RateResolver $rateResolver = null;

    /**
     * 构造函数：注入路由模型
     */
    public function __construct(?RateResolver $rateResolver = null)
    {
        $this->model = new Route();
        $this->rateResolver = $rateResolver;
    }

    /**
     * 新增路由：代收不再使用路由费率，固定写入 0
     */
    public function add(array $data): mixed
    {
        $data['rate'] = '0.0000';

        return parent::add($data);
    }

    /**
     * 修改路由：忽略 rate 字段；变更 pay_type 时须与已绑定通道一致
     */
    public function edit($id, array $data): mixed
    {
        unset($data['rate']);

        if (isset($data['pay_type'])) {
            $this->guardPayTypeChange((int) $id, (int) $data['pay_type']);
        }

        return parent::edit($id, $data);
    }

    /**
     * 支付类型变更前校验：已有绑定通道须全部与新 pay_type 一致
     */
    protected function guardPayTypeChange(int $routeId, int $newPayType): void
    {
        $route = Route::where('id', $routeId)->field('id,pay_type')->find();
        if ($route === null) {
            return;
        }
        if ((int) $route['pay_type'] === $newPayType) {
            return;
        }

        $channelIds = RouteChannel::where('route_id', $routeId)->column('channel_id');
        if ($channelIds === []) {
            return;
        }

        $mismatch = Channel::whereIn('id', $channelIds)
            ->where('pay_type', '<>', $newPayType)
            ->count();
        if ($mismatch > 0) {
            throw new ApiException('路由下已绑定其他支付类型的通道，请先调整通道绑定或保持原支付类型');
        }
    }

    /**
     * 按金额试算命中通道与费率（调试用，对齐生产选路语义）
     *
     * @param int $routeId 路由ID
     * @param int|float|string $amount 订单金额（元）
     * @param int $merchantId 商户ID；0=不按商户授权过滤（仅路由规则试算）
     * @return array{
     *   hit:bool,
     *   channel_id:?int,
     *   channel_title:string,
     *   resolved_rate:?string,
     *   fee_preview:?string,
     *   real_amount_preview:?string,
     *   profitable:?bool,
     *   message:?string,
     *   merchant_id:?int
     * }
     */
    public function previewRoute(int $routeId, int|float|string $amount, int $merchantId = 0): array
    {
        $amountStr = AmountHelper::format((string) $amount);

        // 指定商户时须先过授权白名单（与 PayGatewayLogic 一致）
        $authorizedIds = [];
        if ($merchantId > 0) {
            $authorizedIds = $this->loadAuthorizedChannelIds($merchantId);
            if ($authorizedIds === []) {
                return $this->previewMiss('商户未配置可用支付通道', $merchantId);
            }
        }

        $candidates = $this->loadRouteChannelCandidates($routeId);
        if ($merchantId > 0) {
            $authSet = array_fill_keys($authorizedIds, true);
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $row): bool => isset($authSet[(int) ($row['channel_id'] ?? 0)])
            ));
        }

        if ($candidates === []) {
            return $this->previewMiss(
                $merchantId > 0 ? '授权通道与路由候选无交集' : '路由下无启用通道',
                $merchantId
            );
        }

        $channelId = RouteService::route($candidates, $amountStr, null, $routeId);
        if ($channelId === null) {
            return $this->previewMiss('未命中任何通道', $merchantId);
        }

        $channel = $this->loadActiveChannel((int) $channelId);
        if ($channel === null) {
            return $this->previewMiss('通道不存在或已停用', $merchantId);
        }

        $rateResolver = $this->getRateResolver();
        try {
            $resolvedRate = $rateResolver->resolvePayRate($merchantId, (int) $channelId, $routeId);
        } catch (PaymentException $e) {
            return [
                'hit'                 => false,
                'channel_id'          => (int) $channelId,
                'channel_title'       => (string) ($channel['title'] ?? ''),
                'resolved_rate'       => null,
                'fee_preview'         => null,
                'real_amount_preview' => null,
                'profitable'          => null,
                'message'             => $e->getMessage(),
                'merchant_id'         => $merchantId > 0 ? $merchantId : null,
            ];
        }

        $feePreview = AmountHelper::fee($amountStr, $resolvedRate);
        $upstream = AmountHelper::format((string) ($channel['rate'] ?? '0'));
        $profitable = AmountHelper::compare($resolvedRate, $upstream) > 0;

        return [
            'hit'                 => true,
            'channel_id'          => (int) $channelId,
            'channel_title'       => (string) ($channel['title'] ?? ''),
            'resolved_rate'       => $resolvedRate,
            'fee_preview'         => $feePreview,
            'real_amount_preview' => AmountHelper::sub($amountStr, $feePreview),
            'profitable'          => $profitable,
            'message'             => $profitable ? null : '平台费率须大于上游成本',
            'merchant_id'         => $merchantId > 0 ? $merchantId : null,
        ];
    }

    /**
     * 未命中时的统一返回结构
     */
    protected function previewMiss(string $message, int $merchantId = 0): array
    {
        return [
            'hit'                 => false,
            'channel_id'          => null,
            'channel_title'       => '',
            'resolved_rate'       => null,
            'fee_preview'         => null,
            'real_amount_preview' => null,
            'profitable'          => null,
            'message'             => $message,
            'merchant_id'         => $merchantId > 0 ? $merchantId : null,
        ];
    }

    protected function getRateResolver(): RateResolver
    {
        return $this->rateResolver ??= new RateResolver();
    }

    /**
     * 加载商户已授权代收通道 ID 集合
     *
     * @param int $merchantId 商户ID
     * @return int[]
     */
    protected function loadAuthorizedChannelIds(int $merchantId): array
    {
        $ids = MerchantChannel::where('merchant_id', $merchantId)
            ->where('status', MerchantChannel::STATUS_NORMAL)
            ->column('channel_id');

        return array_map('intval', $ids);
    }

    /**
     * 加载路由下启用的通道候选
     *
     * @param int $routeId 路由ID
     * @return array<int,array>
     */
    protected function loadRouteChannelCandidates(int $routeId): array
    {
        return RouteChannel::where('route_id', $routeId)
            ->where('status', 1)
            ->field('channel_id, money_rule, weight')
            ->select()
            ->toArray();
    }

    /**
     * 加载启用中的通道
     *
     * @param int $channelId 通道ID
     * @return array|null
     */
    protected function loadActiveChannel(int $channelId): ?array
    {
        $row = Channel::where('id', $channelId)->where('status', 1)->find();
        return $row ? $row->toArray() : null;
    }
}
