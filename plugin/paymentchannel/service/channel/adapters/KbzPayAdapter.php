<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：KBZPay（缅甸，NPAY/易支付协议）代收适配器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel\adapters;

use plugin\paymentchannel\app\model\ChannelLog;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\channel\AbstractChannelAdapter;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\CreateOrderResult;
use plugin\paymentchannel\service\channel\dto\PaymentStatusResult;

/**
 * KBZPay（缅甸，NPAY/易支付协议）代收适配器
 *
 * 对接 NPAY Payment Platform 的缅甸 KBZPay 通道（如 `https://prod.mmkgw.xyz/`）。本平台以
 * 「商户」身份调用上游：字段映射 → 易支付 MD5 签名 → mapi.php 下单 → 解析支付链接 →
 * 回调验签（trade_status=PAY_SUCCESS）→ api.php?act=order 主动查单。
 *
 * 与通用 {@see NpayAdapter} 的关键差异（专为 KBZPay 通道定制）：
 *  1) 支付方式**固定** type=kbzpay（该通道仅支持 KBZPay，且当前 sa_pay_channel 表无 extra
 *     列、无法配置 pay_type_map，故在适配器内固定，避免误发 alipay 被上游拒）；
 *  2) 异步回调「已支付」状态为 **PAY_SUCCESS**（NPAY 文档明确「只有 PAY_SUCCESS 是成功」）；
 *  3) 上游下单返回的 payurl 为**相对路径**（如 `/pay/kbzpay/xxx/?prepay_id=...`），本适配器
 *     用网关地址补全为**绝对 URL** 后返回，保证商户端可直接跳转调起 KBZPay。
 *
 * 易支付 MD5 签名规则（与上游一致，已联调验证）：剔除 sign/sign_type 与空值 → 参数名 ksort
 * 升序 → "k=v&" 拼接去尾 "&" → 末尾**直接追加密钥**（无 "key=" 前缀）→ md5 取 32 位小写。
 *
 * 通道表（sa_pay_channel）配置约定：
 *  - adapter         = 'kbzpay'
 *  - gateway_url     = 上游网关基址，如 `https://prod.mmkgw.xyz/`（含/不含尾斜杠，亦可直接
 *                      填到 mapi.php，会自动归一为基址再拼 mapi.php / api.php）；
 *  - upstream_mch_id = NPAY 商户 ID（pid，如 10001）；
 *  - upstream_key    = NPAY 商户 32 位 MD5 密钥（key）。
 *
 * 下单时本平台订单号作为上游 out_trade_no；上游回调 out_trade_no 即本平台 order_no。
 */
class KbzPayAdapter extends AbstractChannelAdapter
{
    /** 上游固定支付方式：KBZPay */
    private const PAY_TYPE = 'kbzpay';

    /** 易支付统一成功响应码（下单 / 查单） */
    private const SUCCESS_CODE = '1';

    /** 异步回调「已支付」交易状态（NPAY 文档：只有 PAY_SUCCESS 是成功） */
    private const NOTIFY_PAID = 'PAY_SUCCESS';

    /**
     * 向上游（KBZPay）发起代收下单（mapi.php，返回 JSON 支付链接）
     *
     * @param CreateOrderRequest $request 下单入参
     * @return CreateOrderResult
     */
    public function createOrder(CreateOrderRequest $request): CreateOrderResult
    {
        // 1) 组装易支付下单参数：平台订单号作为上游 out_trade_no，金额「元」保留 2 位小数，type 固定 kbzpay
        $params = [
            'pid'          => $this->credential->upstreamMchId,
            'type'         => self::PAY_TYPE,
            'out_trade_no' => $request->orderNo,
            'notify_url'   => $request->notifyUrl,
            'return_url'   => $request->returnUrl,
            'name'         => $request->subject !== '' ? $request->subject : $request->orderNo,
            'money'        => AmountHelper::format($request->amount, 2),
            'clientip'     => $request->clientIp,
            'device'       => 'pc',
        ];
        // 2) 易支付 MD5 签名
        $params['sign'] = $this->kbzSign($params);
        $params['sign_type'] = 'MD5';

        // 3) 提交到 mapi.php 并落日志
        $raw = $this->httpPost($this->endpoint('mapi.php'), $params, ChannelLog::TYPE_CREATE, $request->orderNo);
        $resp = $this->decodeJson($raw);

        // 4) 解析响应：code=1 成功，payurl 为支付链接（相对路径需补全域名）
        if ((string) ($resp['code'] ?? '') !== self::SUCCESS_CODE) {
            $msg = (string) ($resp['msg'] ?? $resp['message'] ?? '上游下单失败');
            return CreateOrderResult::fail($msg, $raw);
        }

        $payUrl = (string) ($resp['payurl'] ?? $resp['qrcode'] ?? '');
        $upstreamNo = (string) ($resp['trade_no'] ?? '');
        if ($payUrl === '') {
            return CreateOrderResult::fail('上游未返回支付链接', $raw);
        }

        return CreateOrderResult::ok($this->absoluteUrl($payUrl), $upstreamNo, $raw);
    }

    /**
     * 解析上游异步回调（易支付 notify 字段）
     *
     * @param array $payload 回调参数（out_trade_no/trade_no/money/trade_status/sign）
     * @return PaymentStatusResult
     */
    public function parseNotify(array $payload): PaymentStatusResult
    {
        // 平台订单号 = 下单时传给上游的 out_trade_no
        $orderNo = (string) ($payload['out_trade_no'] ?? '');
        $upstreamNo = (string) ($payload['trade_no'] ?? '');
        // KBZPay 金额单位为「元（MMK）」，直接格式化为 4 位小数
        $amount = AmountHelper::format((string) ($payload['money'] ?? '0'));
        $raw = $this->encode($payload);

        if ($this->isPaidState($payload)) {
            return PaymentStatusResult::paid($orderNo, $amount, $upstreamNo, $raw);
        }
        $state = (string) ($payload['trade_status'] ?? $payload['status'] ?? '');
        return PaymentStatusResult::failed($orderNo, '上游回调未支付:' . $state, $amount, $upstreamNo, $raw);
    }

    /**
     * 校验上游回调签名（易支付 MD5 规则）
     *
     * @param array $payload 回调参数（含 sign）
     * @return bool
     */
    public function verifyNotify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        // 恒定时间比较，防时序攻击
        return hash_equals($this->kbzSign($payload), $sign);
    }

    /**
     * 主动向上游查单（api.php?act=order，回调缺失时补偿）
     *
     * 易支付查单以 pid + key 明文鉴权（GET），不另行签名；优先用平台订单号
     * （即上游侧 out_trade_no）查询，缺失时回退上游 trade_no。
     *
     * @param string $orderNo    平台订单号
     * @param string $upstreamNo 上游订单号
     * @return PaymentStatusResult
     */
    public function queryOrder(string $orderNo, string $upstreamNo = ''): PaymentStatusResult
    {
        $query = [
            'act' => 'order',
            'pid' => $this->credential->upstreamMchId,
            'key' => $this->credential->upstreamKey,
        ];
        if ($orderNo !== '') {
            $query['out_trade_no'] = $orderNo;
        } elseif ($upstreamNo !== '') {
            $query['trade_no'] = $upstreamNo;
        }

        // 查单地址：gateway_url + api.php；参数随 URL 走（GET 语义）
        $base = $this->endpoint('api.php');
        $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query);
        $raw = $this->httpPost($url, [], ChannelLog::TYPE_QUERY, $orderNo);
        $resp = $this->decodeJson($raw);

        $amount = AmountHelper::format((string) ($resp['money'] ?? '0'));
        $respUpstreamNo = (string) ($resp['trade_no'] ?? $upstreamNo);

        if ($this->isPaidState($resp)) {
            return PaymentStatusResult::paid($orderNo, $amount, $respUpstreamNo, $raw);
        }
        // status=2 视为退款/失败；其余（含未支付）按待支付处理，避免误判关单
        if ((string) ($resp['status'] ?? '') === '2') {
            return PaymentStatusResult::failed($orderNo, '上游查单失败/已退款', $amount, $respUpstreamNo, $raw);
        }
        return PaymentStatusResult::pending($orderNo, $amount, $respUpstreamNo, $raw);
    }

    /**
     * 是否为「已支付」状态（兼容 trade_status=PAY_SUCCESS 与查单 status=1 两种写法）
     *
     * @param array $payload 回调/查单报文
     * @return bool
     */
    private function isPaidState(array $payload): bool
    {
        if ((string) ($payload['trade_status'] ?? '') === self::NOTIFY_PAID) {
            return true;
        }
        return (string) ($payload['status'] ?? '') === '1';
    }

    /**
     * 易支付 MD5 签名
     *
     * 规则：剔除 sign/sign_type 与空值 → ksort 升序 → "k=v&" 拼接去尾 "&" →
     * 末尾直接追加密钥（无 "key=" 前缀）→ md5 取 32 位小写。
     *
     * @param array $params 业务参数
     * @return string 32 位小写 MD5
     */
    private function kbzSign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            // 空值字段不参与签名（易支付规则）
            if ($v === '' || $v === null || is_array($v)) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }

        return md5(implode('&', $pairs) . $this->credential->upstreamKey);
    }

    /**
     * 把上游返回的相对支付链接补全为绝对 URL
     *
     * 上游 payurl 形如 `/pay/kbzpay/xxx/?prepay_id=...`，需用网关地址的 scheme+host(+port)
     * 前缀补全，商户端才能直接跳转。已是绝对地址（http/https 开头）则原样返回。
     *
     * @param string $url 上游返回的支付链接（可能为相对路径）
     * @return string 绝对 URL
     */
    private function absoluteUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $parts = parse_url((string) $this->credential->gatewayUrl);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin . '/' . ltrim($url, '/');
    }

    /**
     * 拼接上游端点地址：把 gateway_url 归一为基址后追加目标文件
     *
     * 兼容 gateway_url 填写为基址（含/不含尾斜杠）或直接填到 submit.php/mapi.php/api.php。
     *
     * @param string $file 目标文件（mapi.php / api.php / submit.php）
     * @return string 完整端点地址
     */
    private function endpoint(string $file): string
    {
        $base = (string) $this->credential->gatewayUrl;
        // 去掉末尾可能带的已知端点文件，归一为基址
        $base = (string) preg_replace('#(submit|mapi|api)\.php/?$#i', '', $base);
        return rtrim($base, '/') . '/' . $file;
    }
}
