<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游代付请求 DTO
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer\dto;

/**
 * 上游代付（出款）请求
 *
 * 平台侧组装好的「标准代付入参」，由代付/提现逻辑（Phase 4.2）创建本地代付单后构造，
 * 交给具体适配器翻译成各上游所需报文。金额一律用「元」（decimal 字符串），
 * 由适配器在边界处按上游要求换算单位，杜绝跨层浮点误差。
 */
final class CreateTransferRequest
{
    /**
     * @param string $transferNo  平台代付单号（作为发给上游的商户订单号）
     * @param string $amount      代付金额（元，decimal 字符串，如 '100.0000'）
     * @param string $accountName 收款人姓名
     * @param string $accountNo   收款账号 / 银行卡号
     * @param string $bankName    收款银行名称
     * @param string $bankCode    银行编码（部分上游必填）
     * @param string $notifyUrl   平台接收上游代付异步回调的地址
     * @param string $extra       透传备注
     */
    public function __construct(
        public readonly string $transferNo,
        public readonly string $amount,
        public readonly string $accountName,
        public readonly string $accountNo,
        public readonly string $bankName = '',
        public readonly string $bankCode = '',
        public readonly string $notifyUrl = '',
        public readonly string $extra = '',
    ) {
    }
}
