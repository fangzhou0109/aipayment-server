<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户账户/API密钥控制器（/mapi/account）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\MerchantLogic;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\service\MerchantKeyService;
use support\Request;
use support\Response;

/**
 * 商户门户账户/API密钥控制器（/mapi/account）
 *
 * 供商户查看自己的对接凭证（商户号 + MD5 签名密钥 + 自己上传的 RSA 公钥）并自助重置密钥。
 * **仅当前登录商户自己**——身份取自 token，密钥仅下发给本人（对接 API 必需）。
 */
class AccountController extends BaseMerchantController
{
    /**
     * 获取对接 API 信息（商户号 + 签名密钥 + 费率/限额等）
     * 路由：GET /mapi/account/apiInfo
     *
     * @param Request $request
     * @return Response
     */
    public function apiInfo(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $m = Merchant::where('id', $merchantId)->find();
        if (!$m) {
            return $this->fail('商户不存在');
        }
        // 直接属性访问取 secret_key（$hidden 仅影响 toArray，不影响属性读取）；
        // 仅下发给已登录的本商户，用于其服务器签名对接。
        $platformPublic = MerchantKeyService::extractPublicKeyFromPrivate((string) $m->rsa_private_key) ?? '';

        return $this->success([
            'mch_id'                  => (string) $m->mch_id,
            'name'                    => (string) $m->name,
            'secret_key'              => (string) $m->secret_key,
            'platform_rsa_public_key' => $platformPublic,
            'rsa_public_key'          => (string) $m->rsa_public_key,
            'rate'                    => (string) $m->rate,
            'rate_transfer'           => (string) $m->rate_transfer,
            'single_min'              => (string) $m->single_min,
            'single_max'              => (string) $m->single_max,
            'auto_disbursement_threshold' => (string) $m->auto_disbursement_threshold,
            'balance'                 => (string) $m->balance,
        ]);
    }

    /**
     * 设置 API 代付自动下发阈值（每商户独立）
     * 路由：POST /mapi/account/updateAutoDisburseThreshold
     *
     * 阈值 > 0 时，API 代付进单金额 <= 阈值自动下发；<=0 回落平台全局配置。
     *
     * @param Request $request
     * @return Response
     */
    public function updateAutoDisburseThreshold(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $threshold = (string) $request->post('auto_disbursement_threshold', '');

        try {
            $value = (new MerchantLogic())->updateAutoDisbursementThreshold($merchantId, $threshold);
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success(['auto_disbursement_threshold' => $value], '代付自动下发阈值已保存');
    }

    /**
     * 更新商户来签 RSA 公钥（sign_type=2 时平台验商户请求用）
     * 路由：POST /mapi/account/updateRsaPublicKey
     */
    public function updateRsaPublicKey(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        $publicKey = (string) $request->post('rsa_public_key', '');

        try {
            (new MerchantLogic())->updateMerchantRsaPublicKey($merchantId, $publicKey);
        } catch (PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success([], 'RSA 公钥已保存');
    }

    /**
     * 重置 API 签名密钥（重新下发 MD5 secret_key）
     * 路由：POST /mapi/account/resetKey
     *
     * @param Request $request
     * @return Response 新的 secret_key
     */
    public function resetKey(Request $request): Response
    {
        $merchantId = $this->merchantId($request);
        // 复用平台后台密钥重置逻辑（按 ID 重置，仅作用于本商户）
        $result = (new MerchantLogic())->resetKey($merchantId);
        return $this->success($result, '密钥已重置，请及时更新对接配置');
    }
}
