<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：路由-通道关联验证器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\validate;

use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\Route;
use plugin\saiadmin\basic\BaseValidate;

/**
 * 路由-通道关联验证器
 *
 * 校验金额规则 money_rule 格式；绑定通道须与路由 pay_type 一致且具备代收能力。
 */
class RouteChannelValidate extends BaseValidate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        'route_id'   => 'require|integer|gt:0',
        'channel_id' => 'require|integer|gt:0|checkChannelRouteMatch',
        'money_rule' => 'checkMoneyRule',
        'weight'     => 'integer|egt:0',
        'status'     => 'require|in:1,2',
    ];

    /**
     * 错误信息
     * @var array
     */
    protected $message = [
        'route_id.require'   => '路由必须选择',
        'channel_id.require' => '通道必须选择',
        'status.in'          => '状态值非法',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        'save'   => ['route_id', 'channel_id', 'money_rule', 'weight', 'status'],
        'update' => ['money_rule', 'weight', 'status'],
    ];

    /**
     * 自定义规则：校验金额规则格式
     *
     * 允许：空串（不限金额）/ 'min-max' 范围 / 'v1+v2+v3' 固定池（纯数字，可含小数点）。
     *
     * @param mixed $value 金额规则
     * @return bool|string
     */
    protected function checkMoneyRule($value): bool|string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return true; // 空 = 不限金额
        }
        // 范围：数字-数字
        if (preg_match('/^[0-9.]+\s*-\s*[0-9.]+$/', $value)) {
            return true;
        }
        // 固定池：数字(+数字)*
        if (preg_match('/^[0-9.]+(\s*\+\s*[0-9.]+)*$/', $value)) {
            return true;
        }
        return '金额规则格式非法，应为范围(300-10000)或固定池(800+1000+2000)';
    }

    /**
     * 绑定通道须与路由支付类型一致，且具备代收能力
     *
     * @param mixed $value channel_id
     * @param mixed $rule 规则
     * @param array $data 待校验数据（须含 route_id）
     * @return bool|string
     */
    protected function checkChannelRouteMatch($value, $rule, array $data = []): bool|string
    {
        $routeId = (int) ($data['route_id'] ?? 0);
        $channelId = (int) $value;
        if ($routeId <= 0 || $channelId <= 0) {
            return '路由与通道必须选择';
        }

        $route = $this->loadRouteForMatch($routeId);
        if ($route === null) {
            return '路由不存在';
        }

        $channel = $this->loadChannelForMatch($channelId);
        if ($channel === null) {
            return '通道不存在';
        }

        $biz = (int) ($channel['channel_biz'] ?? Channel::BIZ_NONE);
        if (!in_array($biz, [Channel::BIZ_PAY_ONLY, Channel::BIZ_BOTH], true)) {
            return '仅能绑定具备代收能力的通道';
        }

        if ((int) ($channel['pay_type'] ?? 0) !== (int) ($route['pay_type'] ?? 0)) {
            return '通道支付类型与路由不一致，仅能绑定同支付类型的代收通道';
        }

        return true;
    }

    /**
     * 加载路由（测试可覆写）
     */
    protected function loadRouteForMatch(int $routeId): ?array
    {
        $row = Route::where('id', $routeId)->field('id,pay_type,status')->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 加载通道（测试可覆写）
     */
    protected function loadChannelForMatch(int $channelId): ?array
    {
        $row = Channel::where('id', $channelId)->field('id,pay_type,channel_biz,status')->find();

        return $row ? $row->toArray() : null;
    }
}
