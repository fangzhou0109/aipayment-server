<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游通道管理验证器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\validate;

use plugin\paymentchannel\service\channel\ChannelAdapterRegistry;
use plugin\saiadmin\basic\BaseValidate;

/**
 * 上游通道管理验证器
 *
 * 校验通道编码、适配器（必须在注册表中）、支付类型、费率与状态。
 */
class ChannelValidate extends BaseValidate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        'title'     => 'require|max:100',
        'code'      => 'require|max:64',
        'adapter'   => 'require|checkAdapter',
        'pay_type'  => 'require|in:1,2,3,4,5,6,7',
        'rate'      => 'float|between:0,100',
        'rate_self'    => 'float|between:0,100',
        'money_rule'   => 'checkMoneyRule',
        'status'       => 'require|in:1,2',
    ];

    /**
     * 错误信息
     * @var array
     */
    protected $message = [
        'title.require'    => '通道名称必须填写',
        'code.require'     => '通道编码必须填写',
        'adapter.require'  => '适配器必须选择',
        'pay_type.require' => '支付类型必须选择',
        'pay_type.in'      => '支付类型值非法',
        'rate.between'     => '上游费率需在 0~100 之间',
        'rate_self.between' => '平台费率需在 0~100 之间',
        'status.require'    => '状态必须填写',
        'status.in'        => '状态值非法',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        'save'   => ['title', 'code', 'adapter', 'pay_type', 'rate', 'rate_self', 'money_rule', 'status'],
        // 修改场景不含 code（通道编码不可改，避免影响已落库订单的回调路由）
        'update' => ['title', 'adapter', 'pay_type', 'rate', 'rate_self', 'money_rule', 'status'],
    ];

    /**
     * 自定义规则：校验适配器标识在注册表中存在
     *
     * @param mixed $value 适配器标识
     * @return bool|string true 通过，字符串为错误信息
     */
    protected function checkAdapter($value): bool|string
    {
        if (!ChannelAdapterRegistry::exists((string) $value)) {
            return "适配器标识非法：{$value}";
        }
        return true;
    }

    /**
     * 金额规则格式（与 route_channel.money_rule 一致）
     *
     * @param mixed $value 金额规则
     * @return bool|string
     */
    protected function checkMoneyRule($value): bool|string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return true;
        }
        if (preg_match('/^[0-9.]+\s*-\s*[0-9.]+$/', $value)) {
            return true;
        }
        if (preg_match('/^[0-9.]+(\s*\+\s*[0-9.]+)*$/', $value)) {
            return true;
        }

        return '金额规则格式非法，应为范围(300-10000)或固定池(800+1000+2000)';
    }
}
