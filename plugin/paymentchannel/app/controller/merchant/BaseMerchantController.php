<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户业务控制器基类（/mapi 专用）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Merchant;
use plugin\saiadmin\basic\OpenController;
use support\Request;

/**
 * 商户门户业务控制器基类
 *
 * 所有 /mapi 业务控制器继承本类，统一从「MerchantAuth 中间件透传的请求头」解析商户身份。
 *
 * 安全核心：**商户ID 一律取自 token（请求头 merchant），绝不信任请求参数**，
 * 从根上杜绝越权访问他人数据（商户只能查/操作自己的订单、提现、银行卡等）。
 */
class BaseMerchantController extends OpenController
{
    /**
     * 从中间件透传的请求头解析商户ID（纯函数，便于单测）
     *
     * @param mixed $header MerchantAuth 写入的请求头 `merchant`（JWT extend）
     * @return int 商户ID
     * @throws PaymentException 身份缺失/非法
     */
    public static function resolveMerchantId(mixed $header): int
    {
        if (is_array($header) && isset($header['id'])) {
            $id = (int) $header['id'];
            if ($id > 0) {
                return $id;
            }
        }
        throw new PaymentException('商户身份无效，请重新登录');
    }

    /**
     * 取当前登录商户ID（从 token，不信任 params）
     *
     * @param Request $request 请求
     * @return int
     * @throws PaymentException
     */
    protected function merchantId(Request $request): int
    {
        return self::resolveMerchantId($request->header('merchant'));
    }

    /**
     * 加载当前商户上下文（供 WithdrawLogic/RechargeLogic 等需要费率的逻辑使用）
     *
     * 仅返回业务所需安全字段（费率/余额等），**不含** secret_key/password。
     *
     * @param Request $request 请求
     * @return array
     * @throws PaymentException 商户不存在
     */
    protected function loadMerchant(Request $request): array
    {
        $id = $this->merchantId($request);
        $m = Merchant::where('id', $id)->find();
        if (!$m) {
            throw new PaymentException('商户不存在');
        }
        // 直接属性访问取值（$hidden 仅影响序列化，不影响属性读取）
        return [
            'id'             => (int) $m->id,
            'mch_id'         => (string) $m->mch_id,
            'name'           => (string) $m->name,
            'rate'           => (string) $m->rate,
            'rate_transfer'  => (string) $m->rate_transfer,
            'balance'        => (string) $m->balance,
            'balance_freeze' => (string) $m->balance_freeze,
            'single_min'     => (string) $m->single_min,
            'single_max'     => (string) $m->single_max,
            'auto_disbursement_threshold' => (string) $m->auto_disbursement_threshold,
        ];
    }
}
