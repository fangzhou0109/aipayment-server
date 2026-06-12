<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户网关限流中间件（OWASP 防超频暴力）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\middleware;

use plugin\paymentchannel\service\RateLimitService;
use support\Response;
use Webman\Http\Request;
use Webman\MiddlewareInterface;

/**
 * 商户网关限流中间件（/pay/* 专用，挂在 {@see SignVerify} 之后）
 *
 * 按「商户号 + 请求路径」做固定窗口限流，防止单商户超频调用 / 暴力枚举
 * （对应 OWASP A04 不安全设计的防御纵深）。读取 SignVerify 挂到请求头的商户上下文取 mch_id。
 *
 * 安全取舍：
 *  - **失败放行**：限流是保护性控制，Redis 异常时由 {@see RateLimitService::hit} 放行，不阻断支付；
 *  - **可配置 + 可关闭**：上限/窗口/开关读自 config/app.php `rate_limit`，误配（max<=0）即不限流；
 *  - 仅限流商户网关，回调网关（/pay/notify、/pay/transferNotify）不在此组，不受影响。
 *
 * 纯键构造逻辑抽到静态 {@see self::buildKey()} 便于单测。
 */
class RateLimit implements MiddlewareInterface
{
    /** 默认每窗口最大次数（config 未配置时） */
    private const DEFAULT_MAX = 60;

    /** 默认窗口（秒） */
    private const DEFAULT_WINDOW = 60;

    /**
     * 限流服务（可注入便于测试）
     * @var RateLimitService
     */
    private RateLimitService $limiter;

    /**
     * @param RateLimitService|null $limiter 限流服务（测试可注入假计数器）
     */
    public function __construct(?RateLimitService $limiter = null)
    {
        $this->limiter = $limiter ?? new RateLimitService();
    }

    /**
     * 中间件入口
     *
     * @param Request $request 请求
     * @param callable $handler 下一环
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        $config = (array) config('plugin.paymentchannel.app.rate_limit', []);
        // 开关：默认开启；显式 enable=false 时整体跳过
        if (array_key_exists('enable', $config) && $config['enable'] === false) {
            return $handler($request);
        }

        $max = (int) ($config['max'] ?? self::DEFAULT_MAX);
        $window = (int) ($config['window'] ?? self::DEFAULT_WINDOW);

        $merchant = $request->header('pay_merchant');
        $mchId = is_array($merchant) ? (string) ($merchant['mch_id'] ?? '') : '';
        $key = self::buildKey($mchId, $request->path());

        if (!$this->limiter->hit($key, $max, $window)) {
            return json(['code' => 400, 'message' => '请求过于频繁，请稍后再试']);
        }

        return $handler($request);
    }

    /**
     * 构造限流键（纯函数）：pay:rate:{mch_id}:{path}
     *
     * @param string $mchId 商户号（空则用 unknown，避免空键聚合所有匿名请求）
     * @param string $path  请求路径
     * @return string 限流键
     */
    public static function buildKey(string $mchId, string $path): string
    {
        $mch = $mchId !== '' ? $mchId : 'unknown';
        return 'pay:rate:' . $mch . ':' . $path;
    }
}
