<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户认证中间件（/mapi/* 专用，独立商户 JWT）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\middleware;

use plugin\paymentchannel\app\controller\merchant\AuthController;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\MerchantAuthLogic;
use plugin\saiadmin\app\cache\ReflectionCache;
use plugin\saiadmin\exception\ApiException;
use Tinywan\Jwt\JwtToken;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 商户门户认证中间件（/mapi/* 专用）
 *
 * 与 saiadmin `CheckLogin` 对称，但**只认商户 token**：校验 JWT 后要求 extend 里
 * `plat=merchant`（{@see MerchantAuthLogic::PLAT}）。由此实现与平台后台的物理隔离——
 *  - 平台后台 token（plat=saiadmin）打到 /mapi/* → 被本中间件拒；
 *  - 商户 token（plat=merchant）打到 /core/pay/*（CheckLogin 要求 plat=saiadmin）→ 被拒。
 *
 * 通过后把商户身份挂到请求头 `merchant`，供 /mapi 控制器读取（沿用 saiadmin setHeader 约定）。
 * 纯隔离判定抽到静态 {@see self::isMerchantToken()}，便于单测，不依赖 JWT/Redis/请求。
 */
class MerchantAuth implements MiddlewareInterface
{
    /**
     * 商户认证控制器免登录动作（硬编码兜底，避免反射缓存未刷新时 captcha/login 被误拦）
     */
    private const AUTH_PUBLIC_ACTIONS = ['login', 'captcha'];

    /**
     * 中间件入口
     *
     * @param Request $request 请求
     * @param callable $handler 下一环
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        $controller = (string) ($request->controller ?? '');
        $action = (string) ($request->action ?? '');

        // 反射取控制器免登录方法白名单；认证入口再叠加硬编码兜底
        $noNeedLogin = ReflectionCache::getNoNeedLogin($controller);
        $isAuthPublic = $controller === AuthController::class
            && in_array($action, self::AUTH_PUBLIC_ACTIONS, true);
        if (!$isAuthPublic && !in_array($action, $noNeedLogin, true)) {
            try {
                $token = JwtToken::getExtend();
            } catch (\Throwable $e) {
                throw new ApiException('您的登录凭证错误或者已过期，请重新登录', 401);
            }
            // 关键隔离：必须是商户 token，否则拒绝（平台后台 token 在此被挡下）
            if (!self::isMerchantToken($token)) {
                throw new ApiException('登录凭证校验失败');
            }
            // 已登录请求同样受 IP 白名单约束（防止非白名单 IP 持有效 token 访问门户）
            try {
                (new MerchantAuthLogic())->assertIpAllowedByMerchantId((int) ($token['id'] ?? 0), $request->getRealIp());
            } catch (PaymentException $e) {
                throw new ApiException($e->getMessage(), 403);
            }

            // 透传商户身份给控制器（id/mch_id/login_name）
            $request->setHeader('check_merchant', true);
            $request->setHeader('merchant', $token);
        }
        return $handler($request);
    }

    /**
     * 纯判定：令牌是否为「商户门户」来源
     *
     * @param array $token JWT extend 数据
     * @return bool plat=merchant 返回 true
     */
    public static function isMerchantToken(array $token): bool
    {
        return ($token['plat'] ?? '') === MerchantAuthLogic::PLAT;
    }
}
