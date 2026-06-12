<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户网关 IP 白名单中间件
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\middleware;

use support\Response;
use Webman\Http\Request;
use Webman\MiddlewareInterface;

/**
 * 商户网关 IP 白名单中间件（/pay/* 专用）
 *
 * 在 {@see SignVerify} 之后运行：读取其挂到请求头的商户上下文，当商户开启 IP 白名单
 * （ip_whitelist_status=1）时，校验真实来源 IP 是否在白名单内，不在则拒绝。
 *
 * 约定：白名单为逗号分隔的 IP 列表。开启（status=1）时须配置至少一个 IP，否则拒绝（严格模式）。
 * 纯判定逻辑抽到 {@see self::isAllowed()} / {@see self::parseIpList()}，便于单测。
 */
class IpWhitelist implements MiddlewareInterface
{
    /**
     * 中间件入口
     *
     * @param Request $request 请求
     * @param callable $handler 下一环
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        $merchant = $request->header('pay_merchant');
        // 正常流程下 SignVerify 已挂载商户上下文；缺失说明未通过前置校验
        if (!is_array($merchant)) {
            return json(['code' => 400, 'message' => '未通过身份校验']);
        }

        // 未开启白名单 → 直接放行（默认 2=关闭）
        if ((int) ($merchant['ip_whitelist_status'] ?? 2) === 1) {
            $clientIp = $request->getRealIp();
            if (!self::isAllowed((string) ($merchant['ip_whitelist'] ?? ''), $clientIp, true)) {
                $message = $clientIp === ''
                    ? '无法识别请求来源 IP'
                    : (self::parseIpList((string) ($merchant['ip_whitelist'] ?? '')) === []
                        ? '已开启 IP 白名单但未配置允许的 IP'
                        : 'IP 不在白名单内：' . $clientIp);
                return json(['code' => 400, 'message' => $message]);
            }
        }

        return $handler($request);
    }

    /**
     * 解析逗号分隔的 IP 白名单
     *
     * @param string $whitelist 原始白名单字符串
     * @return list<string>
     */
    public static function parseIpList(string $whitelist): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $whitelist)),
            static fn (string $v): bool => $v !== ''
        ));
    }

    /**
     * 纯判定：IP 是否在白名单内
     *
     * @param string $whitelist 逗号分隔的 IP 列表
     * @param string $ip 待校验 IP
     * @param bool $rejectEmptyWhitelist 为 true 时，白名单为空则拒绝（门户/网关开启白名单后的严格模式）
     * @return bool 允许返回 true
     */
    public static function isAllowed(string $whitelist, string $ip, bool $rejectEmptyWhitelist = false): bool
    {
        $items = self::parseIpList($whitelist);
        if ($items === []) {
            return !$rejectEmptyWhitelist;
        }

        return in_array($ip, $items, true);
    }
}
