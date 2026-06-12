<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游通道凭证（适配器配置）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\dto;

use plugin\paymentchannel\service\SignService;

/**
 * 上游通道凭证 / 配置
 *
 * 把 sa_pay_channel 一行记录里「适配器需要用到的字段」收敛成一个不可变值对象，
 * 注入到适配器实例中。如此适配器**不依赖** Channel 模型与 DB，纯逻辑、易单测。
 *
 * 字段语义：
 *  - $gatewayUrl       上游下单/查单网关地址；
 *  - $upstreamMchId    上游分配给平台的商户号；
 *  - $upstreamKey      上游 MD5 签名密钥（拼串用）；
 *  - $upstreamPublicKey  上游 RSA 公钥（验「上游来签」用，如回调验签）；
 *  - $upstreamPrivateKey 上游 RSA 私钥（平台对上游「发起请求」签名用）；
 *  - $signType         签名方式（MD5 / RSA），默认 MD5；
 *  - $extra            适配器自定义配置（如独立查单地址、产品编码等），各适配器自取。
 */
final class ChannelCredential
{
    /**
     * @param int    $channelId          通道ID（仅用于日志归属，可为 0）
     * @param string $gatewayUrl         上游网关地址
     * @param string $upstreamMchId      上游商户号
     * @param string $upstreamKey        上游 MD5 密钥
     * @param string $upstreamPublicKey  上游 RSA 公钥
     * @param string $upstreamPrivateKey 上游 RSA 私钥
     * @param int    $signType           签名类型（SignService::SIGN_TYPE_*）
     * @param array  $extra              适配器自定义配置
     */
    public function __construct(
        public readonly int $channelId = 0,
        public readonly string $gatewayUrl = '',
        public readonly string $upstreamMchId = '',
        public readonly string $upstreamKey = '',
        public readonly string $upstreamPublicKey = '',
        public readonly string $upstreamPrivateKey = '',
        public readonly int $signType = SignService::SIGN_TYPE_MD5,
        public readonly array $extra = [],
    ) {
    }

    /**
     * 从通道数组（sa_pay_channel 一行）构造凭证
     *
     * 兼容 Model::toArray() 或查询数组；缺字段以空串/默认值兜底。
     *
     * @param array $channel 通道数据
     * @return self
     */
    public static function fromArray(array $channel): self
    {
        // sign_type 可放在通道扩展里；当前通道表无该列，默认 MD5
        $signType = (int) ($channel['sign_type'] ?? SignService::SIGN_TYPE_MD5);
        $extra = $channel['extra'] ?? [];
        if (is_string($extra)) {
            // extra 若以 JSON 串存储则解析为数组，解析失败回退空数组
            $decoded = json_decode($extra, true);
            $extra = is_array($decoded) ? $decoded : [];
        }

        return new self(
            channelId: (int) ($channel['id'] ?? 0),
            gatewayUrl: (string) ($channel['gateway_url'] ?? ''),
            upstreamMchId: (string) ($channel['upstream_mch_id'] ?? ''),
            upstreamKey: (string) ($channel['upstream_key'] ?? ''),
            upstreamPublicKey: (string) ($channel['upstream_public_key'] ?? ''),
            upstreamPrivateKey: (string) ($channel['upstream_private_key'] ?? ''),
            signType: $signType,
            extra: is_array($extra) ? $extra : [],
        );
    }
}
