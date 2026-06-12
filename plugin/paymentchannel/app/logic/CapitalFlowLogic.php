<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：资金流水逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\CapitalFlow;
use plugin\saiadmin\service\OpenSpoutWriter;
use support\Response;

/**
 * 资金流水逻辑层
 *
 * 资金流水为不可变流水账（由记账服务写入），后台**只读 + 导出**，不提供增删改。
 * 复用 PaymentBaseLogic 的 search/getList；新增 export 导出 Excel（业务/账户类型转中文）。
 */
class CapitalFlowLogic extends PaymentBaseLogic
{
    /**
     * 业务类型 → 中文（导出/展示用）
     * @var array<int,string>
     */
    public const BIZ_TYPE_TEXT = [
        CapitalFlow::BIZ_PAY_IN          => '代收入账',
        CapitalFlow::BIZ_WITHDRAW_FREEZE => '提现冻结',
        CapitalFlow::BIZ_WITHDRAW_DEDUCT => '提现扣款',
        CapitalFlow::BIZ_WITHDRAW_REFUND => '提现退款',
        CapitalFlow::BIZ_RECHARGE        => '充值',
        CapitalFlow::BIZ_FEE             => '手续费',
        CapitalFlow::BIZ_ADJUST          => '人工调整',
    ];

    /**
     * 账户类型 → 中文
     * @var array<int,string>
     */
    public const ACCOUNT_TEXT = [
        CapitalFlow::ACCOUNT_BALANCE => '可用余额',
        CapitalFlow::ACCOUNT_FREEZE  => '冻结余额',
    ];

    /**
     * 构造：注入资金流水模型
     */
    public function __construct()
    {
        $this->model = new CapitalFlow();
    }

    /**
     * 导出资金流水为 Excel
     *
     * 按当前搜索条件全量导出（业务类型/账户类型转中文枚举），复用 saiadmin OpenSpoutWriter。
     *
     * @param array $where 搜索条件（merchant_id/biz_type/biz_no...）
     * @return Response 文件下载响应
     */
    public function export(array $where = []): Response
    {
        $query = $this->search($where)->field(
            'id,flow_no,merchant_id,mch_id,biz_type,biz_no,change_type,change_amount,before_balance,after_balance,remark,create_time'
        );
        $data = $this->getAll($query);

        $fileName = '资金流水.xlsx';
        $header = ['编号', '流水号', '商户ID', '商户号', '业务类型', '业务单号', '账户类型', '变动金额(元)', '变动前(元)', '变动后(元)', '备注', '时间'];
        // 枚举值 → 中文（OpenSpoutWriter 的 filter：按列字段名映射 value→label）
        $filter = [
            'biz_type' => array_map(
                fn ($value, $label) => ['value' => $value, 'label' => $label],
                array_keys(self::BIZ_TYPE_TEXT),
                array_values(self::BIZ_TYPE_TEXT)
            ),
            'change_type' => array_map(
                fn ($value, $label) => ['value' => $value, 'label' => $label],
                array_keys(self::ACCOUNT_TEXT),
                array_values(self::ACCOUNT_TEXT)
            ),
        ];

        $writer = new OpenSpoutWriter($fileName);
        $writer->setWidth([10, 24, 10, 16, 12, 24, 12, 14, 14, 14, 24, 20]);
        $writer->setHeader($header);
        $writer->setData($data, null, $filter);
        $filePath = $writer->returnFile();
        return response()->download($filePath, urlencode($fileName));
    }
}
