<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户管理验证器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\validate;

use plugin\saiadmin\basic\BaseValidate;

/**
 * 商户管理验证器
 *
 * 基于 ThinkORM 验证规则 + 自定义规则：
 *  - rate 在 save/update 场景排除（代收走 merchant_channel）；rate_transfer 可在 save/update 配置全局保底代付费率；
 *  - single_min/single_max 为商户级代收兜底限额，经操作列「单笔限额」弹窗 update 维护；
 *  - 单笔限额非负；
 *  - status 取 1/2；
 *  - ip_whitelist 为逗号分隔的 IP 列表（可空）。
 */
class MerchantValidate extends BaseValidate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        'mch_id'        => 'require|max:32',
        'name'          => 'require|max:100',
        'login_name'    => 'require|length:4,50|alphaDash',
        'password'      => 'require|length:6,32',
        'rate'          => 'float|between:0,100',
        'rate_transfer' => 'float|between:0,100',
        'single_min'    => 'egt:0',
        'single_max'    => 'egt:0',
        'status'              => 'require|in:1,2',
        'ip_whitelist'        => 'requireIf:ip_whitelist_status,1|checkIpList',
        'ip_whitelist_status' => 'in:1,2',
        'id'                  => 'require|integer|gt:0',
        'direction'           => 'require|in:increase,decrease',
        'amount'              => 'require|float|gt:0',
        'remark'              => 'max:200',
    ];

    /**
     * 错误信息
     * @var array
     */
    protected $message = [
        'mch_id.require'        => '商户号必须填写',
        'mch_id.max'            => '商户号长度不能超过 32',
        'name.require'          => '商户名称必须填写',
        'login_name.require'    => '门户登录名必须填写',
        'login_name.length'     => '门户登录名长度需在 4~50 之间',
        'login_name.alphaDash'  => '门户登录名只能含字母、数字、下划线和破折号',
        'password.require'      => '门户登录密码必须填写',
        'password.length'       => '门户登录密码长度需在 6~32 之间',
        'rate.float'            => '代收费率必须为数字',
        'rate.between'          => '代收费率需在 0~100 之间',
        'rate_transfer.float'   => '代付费率必须为数字',
        'rate_transfer.between' => '代付费率需在 0~100 之间',
        'single_min.egt'        => '单笔最小金额不能为负',
        'single_max.egt'        => '单笔最大金额不能为负',
        'status.require'        => '状态必须填写',
        'status.in'                   => '状态值非法',
        'ip_whitelist_status.in'      => 'IP 白名单开关值非法',
        'ip_whitelist.requireIf'      => '开启 IP 白名单时必须配置至少一个允许的 IP',
        'id.require'                  => '请指定商户',
        'id.integer'                  => '商户 ID 非法',
        'id.gt'                       => '请指定商户',
        'direction.require'           => '请选择调账方向',
        'direction.in'                => '调账方向非法',
        'amount.require'              => '请填写调账金额',
        'amount.float'                => '调账金额必须为数字',
        'amount.gt'                   => '调账金额必须大于 0',
        'remark.max'                  => '备注不能超过 200 字',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        // 新增：商户号必填且唯一性由 DB 唯一索引兜底；门户登录名 + 密码必填（商户门户登录用）
        // 新增：仅基础资料；费率/限额默认 0，创建后于操作列单独配置
        'save' => ['mch_id', 'name', 'login_name', 'password', 'status', 'ip_whitelist', 'ip_whitelist_status'],
        // 修改：rate 不可改；rate_transfer / single_min / single_max 可由专项弹窗提交
        'update' => ['name', 'login_name', 'rate_transfer', 'single_min', 'single_max', 'status', 'ip_whitelist', 'ip_whitelist_status'],
        'adjustBalance' => ['id', 'direction', 'amount', 'remark'],
    ];

    /**
     * 自定义规则：校验 IP 白名单为逗号分隔的合法 IP
     *
     * 允许为空（表示不限制）；非空时每个片段必须是合法 IPv4/IPv6。
     *
     * @param mixed $value 待校验值
     * @return bool|string true 通过，字符串为错误信息
     */
    protected function checkIpList($value): bool|string
    {
        if ($value === '' || $value === null) {
            return true;
        }
        $items = array_filter(array_map('trim', explode(',', (string) $value)));
        foreach ($items as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return "IP 白名单含非法地址：{$ip}";
            }
        }

        return true;
    }
}
