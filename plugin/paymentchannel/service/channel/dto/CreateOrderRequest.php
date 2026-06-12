<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游下单请求 DTO
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\dto;

/**
 * 上游下单请求
 *
 * 平台侧组装好的「标准下单入参」，由下单网关（Phase 3.2）创建本地订单后构造，
 * 交给具体适配器翻译成各上游所需的报文。金额一律用「元」（decimal 字符串），
 * 由适配器在边界处按上游要求换算为分等单位，杜绝跨层浮点误差。
 */
final class CreateOrderRequest
{
    /**
     * @param string $orderNo    平台订单号（作为发给上游的商户订单号）
     * @param string $amount     订单金额（元，decimal 字符串，如 '100.0000'）
     * @param int    $payType    支付类型（1-7，含义见 sa_pay_channel.pay_type）
     * @param string $notifyUrl  平台接收上游异步回调的地址
     * @param string $returnUrl  支付完成后同步跳转地址（可空）
     * @param string $subject    商品名称
     * @param string $clientIp   用户端 IP
     * @param string $extra      商户透传参数（原样传递）
     */
    public function __construct(
        public readonly string $orderNo,
        public readonly string $amount,
        public readonly int $payType,
        public readonly string $notifyUrl,
        public readonly string $returnUrl = '',
        public readonly string $subject = '',
        public readonly string $clientIp = '',
        public readonly string $extra = '',
    ) {
    }
}
