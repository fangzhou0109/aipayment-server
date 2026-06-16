<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户银行卡验证器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\validate;

use plugin\saiadmin\basic\BaseValidate;

/**
 * 商户银行卡验证器
 *
 * 规则：
 *  - 商户ID必填且为正整数（归属约束，绑卡必须明确归属商户）；
 *  - 持卡人姓名必填；
 *  - 银行卡号必填；账号 ≥12 位按银行卡走 Luhn 校验，6~11 位视为钱包/手机号账号（如 KBZPay）；
 *  - status 取 1/2。
 */
class BankCardValidate extends BaseValidate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        'merchant_id' => 'require|integer|gt:0',
        'holder_name' => 'require|max:100',
        'card_no'     => 'require|checkCardNo',
        'bank_name'   => 'max:100',
        'bank_code'   => 'max:32',
        'status'      => 'in:1,2',
    ];

    /**
     * 错误信息
     * @var array
     */
    protected $message = [
        'merchant_id.require' => '请选择归属商户',
        'merchant_id.integer' => '商户ID非法',
        'merchant_id.gt'      => '商户ID非法',
        'holder_name.require' => '持卡人姓名必须填写',
        'holder_name.max'     => '持卡人姓名过长',
        'card_no.require'     => '银行卡号必须填写',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        // 新增：必须指定归属商户
        'save'   => ['merchant_id', 'holder_name', 'card_no', 'bank_name', 'bank_code', 'status'],
        // 修改：归属商户不可改（不在场景中，防止把他人卡改到自己名下），其余可改
        'update' => ['holder_name', 'card_no', 'bank_name', 'bank_code', 'status'],
    ];

    /**
     * 自定义规则：收款账号校验（兼容银行卡号与钱包/手机号账号）
     *
     * 本平台代付收款标的有两类：
     *  - 银行卡：12~19 位数字，按 Luhn 校验防录错；
     *  - 钱包/手机号账号（如缅甸 KBZPay，09 开头约 9~11 位）：非银行卡，Luhn 不适用。
     *
     * 故按长度区分：≥12 位按银行卡走 Luhn；6~11 位视为钱包/手机号账号，仅校验纯数字与长度。
     *
     * @param mixed $value 待校验收款账号
     * @return bool|string true 通过，字符串为错误信息
     */
    protected function checkCardNo($value): bool|string
    {
        // 剔除空格等非数字字符后判断（与 Luhn 校验口径一致）
        $digits = preg_replace('/\D/', '', (string) $value);
        $len = strlen($digits);
        if ($len < 6 || $len > 19) {
            return '收款账号格式不正确（应为 6~19 位数字）';
        }
        // 12 位及以上按银行卡处理，需通过 Luhn 校验；更短的视为钱包/手机号账号（如 KBZPay），跳过 Luhn
        if ($len >= 12 && !self::luhnValid($digits)) {
            return '银行卡号校验失败（请检查卡号是否正确）';
        }
        return true;
    }

    /**
     * Luhn 算法校验银行卡号（公开静态，便于单测直接调用）
     *
     * 银行卡号为 12~19 位数字，末位为 Luhn 校验位。算法：从右往左，偶数位（含校验位左侧）
     * 翻倍后若 >9 则减 9，全部求和后能被 10 整除即合法。
     *
     * @param string $cardNo 银行卡号（允许含空格，内部剔除非数字）
     * @return bool 合法返回 true
     */
    public static function luhnValid(string $cardNo): bool
    {
        // 剔除空格等非数字字符
        $digits = preg_replace('/\D/', '', $cardNo);
        $len = strlen($digits);
        // 银行卡号长度通常 12~19 位
        if ($len < 12 || $len > 19) {
            return false;
        }

        $sum = 0;
        $alternate = false;
        // 从最右位向左遍历
        for ($i = $len - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alternate) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alternate = !$alternate;
        }

        return $sum % 10 === 0;
    }
}
