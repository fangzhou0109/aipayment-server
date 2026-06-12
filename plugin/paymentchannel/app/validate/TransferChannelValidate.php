<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付通道管理验证器（Phase 9.5.2）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\validate;

use plugin\paymentchannel\service\transfer\TransferAdapterRegistry;
use plugin\saiadmin\basic\BaseValidate;

/**
 * 代付通道管理验证器
 *
 * 纯代付建档不要求 adapter / pay_type；网关与上游密钥字段语义与代收通道一致（可选填）。
 */
class TransferChannelValidate extends BaseValidate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        'title'              => 'require|max:100',
        'code'               => 'require|max:64',
        'transfer_adapter'   => 'require|checkTransferAdapter',
        'rate_transfer_self' => 'require|float|between:0,100',
        'status'             => 'require|in:1,2',
        'gateway_url'        => 'max:255',
        'upstream_mch_id'  => 'max:100',
        'upstream_key'       => 'max:255',
        'sort'               => 'integer',
        'remark'             => 'max:255',
    ];

    /**
     * 错误信息
     * @var array
     */
    protected $message = [
        'title.require'                => '通道名称必须填写',
        'code.require'                 => '通道编码必须填写',
        'transfer_adapter.require'     => '代付适配器必须选择',
        'rate_transfer_self.require'   => '代付平台费率必须填写',
        'rate_transfer_self.between' => '代付平台费率需在 0~100 之间',
        'status.require'               => '状态必须填写',
        'status.in'                    => '状态值非法',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        'save'   => ['title', 'code', 'transfer_adapter', 'rate_transfer_self', 'status'],
        'update' => ['title', 'transfer_adapter', 'rate_transfer_self', 'status'],
    ];

    /**
     * 自定义规则：代付适配器须在注册表中
     *
     * @param mixed $value 适配器标识
     * @return bool|string true 通过，字符串为错误信息
     */
    protected function checkTransferAdapter($value): bool|string
    {
        if (!TransferAdapterRegistry::exists((string) $value)) {
            return "代付适配器标识非法：{$value}";
        }

        return true;
    }
}
