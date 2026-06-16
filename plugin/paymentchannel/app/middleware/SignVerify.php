<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户网关签名校验中间件
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\middleware;

use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\service\SignService;
use support\Response;
use Webman\Http\Request;
use Webman\MiddlewareInterface;

/**
 * 商户网关签名校验中间件（/pay/* 专用）
 *
 * 职责：把不可信的外部请求挡在业务之外——
 *  1) 取 mch_id 加载商户；商户不存在/停用 → 拒绝；
 *  2) 校验时间窗口（防重放）；
 *  3) 按 sign_type 用商户密钥/公钥验签（防伪造篡改）；
 *  4) 通过后把「下游需要的商户字段」挂到请求头 pay_merchant，供控制器复用（沿用 saiadmin
 *     CheckLogin 用 setHeader 透传上下文的既有约定）。
 *
 * 失败一律返回统一响应体 {code:400, message}（HTTP 200），并短路后续中间件与控制器。
 * 纯校验逻辑抽到 {@see self::verifyMerchant()}（不依赖 DB/请求），便于单测。
 */
class SignVerify implements MiddlewareInterface
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
        $params = $request->post();
        $mchId = (string) ($params['mch_id'] ?? '');
        if ($mchId === '') {
            return $this->reject('缺少商户号 mch_id');
        }

        // 加载商户（secret_key/rsa_public_key 虽在序列化时隐藏，但属性可直接访问）
        $merchant = Merchant::where('mch_id', $mchId)->find();
        if (!$merchant) {
            return $this->reject('商户不存在');
        }

        // 组装校验所需的纯数组（含密钥/公钥），交给可单测的纯函数判定
        $merchantData = [
            'status'         => (int) $merchant->status,
            'secret_key'     => (string) $merchant->secret_key,
            'rsa_public_key' => (string) $merchant->rsa_public_key,
        ];
        $error = self::verifyMerchant($merchantData, $params);
        if ($error !== null) {
            return $this->reject($error);
        }

        // 透传给控制器的商户上下文（不含 secret_key，避免下游无谓持有密钥）
        $request->setHeader('pay_merchant', [
            'id'                  => (int) $merchant->id,
            'mch_id'              => (string) $merchant->mch_id,
            'name'                => (string) $merchant->name,
            'rate'                => (string) $merchant->rate,
            'rate_transfer'       => (string) $merchant->rate_transfer,
            'single_min'          => (string) $merchant->single_min,
            'single_max'          => (string) $merchant->single_max,
            'status'              => (int) $merchant->status,
            'ip_whitelist'        => (string) $merchant->ip_whitelist,
            'ip_whitelist_status' => (int) $merchant->ip_whitelist_status,
        ]);

        return $handler($request);
    }

    /**
     * 纯校验：商户状态 + 时间窗口 + 签名
     *
     * @param array $merchant 商户数据（status/secret_key/rsa_public_key）
     * @param array $params 请求参数（含 sign/sign_type/time）
     * @return string|null null=通过；否则返回错误信息
     */
    public static function verifyMerchant(array $merchant, array $params): ?string
    {
        if ((int) ($merchant['status'] ?? 0) !== 1) {
            return '商户已停用';
        }
        if ((string) ($params['sign'] ?? '') === '') {
            return '缺少签名 sign';
        }
        // 时间窗口校验（防重放）：time 必填且偏差不超过默认窗口
        if (!SignService::checkTime($params['time'] ?? 0)) {
            return '请求时间超出允许范围';
        }

        $signType = (int) ($params['sign_type'] ?? SignService::SIGN_TYPE_MD5);
        $secretKey = (string) ($merchant['secret_key'] ?? '');
        $publicKey = (string) ($merchant['rsa_public_key'] ?? '');

        $ok = SignService::verify(
            $params,
            $secretKey,
            $signType,
            $publicKey !== '' ? $publicKey : null,
        );
        return $ok ? null : '签名校验失败';
    }

    /**
     * 返回统一失败响应（短路后续处理）
     *
     * @param string $message 失败原因
     * @return Response
     */
    private function reject(string $message): Response
    {
        return json(['code' => 400, 'message' => $message]);
    }
}
