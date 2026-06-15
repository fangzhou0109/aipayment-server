<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：KBZPay（缅甸，NPAY/易支付协议）代付适配器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer\adapters;

use plugin\paymentchannel\app\model\ChannelLog;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\transfer\AbstractTransferAdapter;
use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\dto\CreateTransferResult;
use plugin\paymentchannel\service\transfer\dto\TransferStatusResult;

/**
 * KBZPay（缅甸，NPAY/易支付协议）代付适配器
 *
 * 对接 NPAY Payment Platform 的缅甸 KBZPay 代付（Payout）接口（如 `https://prod.mmkgw.xyz/`）。
 * 以「商户」身份调用上游 `api.php?act=payout` 向 KBZPay 手机号打款：字段映射 → 易支付 MD5
 * 签名 → HTTP 表单提交 → 解析受理结果。
 *
 * 关键差异（与代收 / 通用代付不同，务必注意）：
 *  - **成功码为 code=0**（代收下单是 code=1，切勿套用）；
 *  - 金额单位为 **MMK「元」**，最多 2 位小数，**不换算成分**；
 *  - 收款标的是 **KBZPay 手机号**（payee_account），取自 {@see CreateTransferRequest::$accountNo}；
 *  - 平台代付单号作为上游 **out_biz_no**（6~32 位）。
 *
 * 易支付 MD5 签名规则（与代收下单完全一致，已对上游探测验证）：剔除 sign/sign_type 与空值
 * → 参数名 ksort 升序 → "k=v&" 拼接去尾 "&" → 末尾**直接追加密钥**（无 "key=" 前缀）→
 * md5 取 32 位小写。**不在表单中携带 key 字段**（携带会被上游计入验签导致签名失败）。
 *
 * 通道表（sa_pay_channel）配置约定：
 *  - transfer_adapter = 'kbzpay_transfer'
 *  - gateway_url      = 上游网关基址，如 `https://prod.mmkgw.xyz/`；
 *  - upstream_mch_id  = NPAY 商户 ID（pid）；
 *  - upstream_key     = NPAY 商户 32 位 MD5 密钥（key）。
 *
 * 接入前置条件：需先在上游商户后台**开启代付开关并配置 IP 白名单**，否则上游返回 -2。
 *
 * 注意：NPAY 文档未公开代付异步回调字段与独立代付查单接口，{@see self::parseTransferNotify()}
 * 按易支付通用约定做兼容解析、{@see self::queryTransfer()} 以「处理中」兜底（依赖回调），
 * 待真实联调后按上游实际报文校准。
 */
class KbzPayTransferAdapter extends AbstractTransferAdapter
{
    /** 上游代付受理成功码（payout：code=0 成功） */
    private const SUCCESS_CODE = '0';

    /** 代付「成功」状态集合（回调 / 查单，兼容多种写法） */
    private const SUCCESS_STATES = ['PAY_SUCCESS', 'SUCCESS', 'success', '1'];

    /** 代付「失败」状态集合 */
    private const FAILED_STATES = ['PAY_FAIL', 'FAIL', 'fail', 'FAILED', 'failed', '2', '-1'];

    /**
     * 向上游（KBZPay）发起代付（api.php?act=payout）
     *
     * @param CreateTransferRequest $request 代付入参（accountNo=KBZPay 手机号）
     * @return CreateTransferResult
     */
    public function createTransfer(CreateTransferRequest $request): CreateTransferResult
    {
        // 1) 组装代付参数：平台单号作为 out_biz_no，金额为 MMK「元」保留 2 位小数（不换分）
        $params = [
            'pid'           => $this->credential->upstreamMchId,
            'payee_account' => $request->accountNo,
            'money'         => AmountHelper::format($request->amount, 2),
            'out_biz_no'    => $request->transferNo,
        ];
        if ($request->notifyUrl !== '') {
            $params['notify_url'] = $request->notifyUrl;
        }
        // 2) 易支付 MD5 签名（口径与下单一致，不携带 key 字段）
        $params['sign'] = $this->kbzSign($params);
        $params['sign_type'] = 'MD5';

        // 3) 提交到 api.php?act=payout 并落日志
        $url = $this->endpoint('api.php') . '?act=payout';
        $raw = $this->httpPost($url, $params, ChannelLog::TYPE_TRANSFER, $request->transferNo);
        $resp = $this->decodeJson($raw);

        // 4) 解析：code=0 受理成功，orderid 为 KBZ 打款订单号
        if ((string) ($resp['code'] ?? '') !== self::SUCCESS_CODE) {
            $msg = (string) ($resp['msg'] ?? $resp['message'] ?? '上游代付受理失败');
            return CreateTransferResult::fail($msg, $raw);
        }
        $upstreamNo = (string) ($resp['orderid'] ?? $resp['trade_no'] ?? '');

        return CreateTransferResult::ok($upstreamNo, $raw);
    }

    /**
     * 解析上游代付异步回调（易支付通用约定，待联调校准）
     *
     * @param array $payload 回调参数
     * @return TransferStatusResult
     */
    public function parseTransferNotify(array $payload): TransferStatusResult
    {
        // 平台代付单号 = 下单时传给上游的 out_biz_no（兼容 biz_no / out_trade_no 写法）
        $transferNo = (string) ($payload['out_biz_no'] ?? $payload['biz_no'] ?? $payload['out_trade_no'] ?? '');
        $upstreamNo = (string) ($payload['orderid'] ?? $payload['trade_no'] ?? '');
        $amount = AmountHelper::format((string) ($payload['money'] ?? '0'));
        $raw = $this->encode($payload);

        $state = (string) ($payload['trade_status'] ?? $payload['status'] ?? '');
        if (in_array($state, self::SUCCESS_STATES, true)) {
            return TransferStatusResult::success($transferNo, $amount, $upstreamNo, $raw);
        }
        if (in_array($state, self::FAILED_STATES, true)) {
            return TransferStatusResult::failed($transferNo, '上游代付失败:' . $state, $amount, $upstreamNo, $raw);
        }
        // 未知状态按处理中，避免误判终结代付单
        return TransferStatusResult::processing($transferNo, $amount, $upstreamNo, $raw);
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
     * 主动查代付结果
     *
     * NPAY 文档未提供独立的代付查单接口，故以「处理中」兜底——代付最终状态以上游异步回调
     * 为准。待上游确认查单接口后再补全。
     *
     * @param string $transferNo 平台代付单号
     * @param string $upstreamNo 上游代付订单号
     * @return TransferStatusResult
     */
    public function queryTransfer(string $transferNo, string $upstreamNo = ''): TransferStatusResult
    {
        return TransferStatusResult::processing($transferNo, '0.0000', $upstreamNo);
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
     * 拼接上游端点地址：把 gateway_url 归一为基址后追加目标文件
     *
     * 兼容 gateway_url 填写为基址（含/不含尾斜杠）或直接填到 submit.php/mapi.php/api.php。
     *
     * @param string $file 目标文件（api.php）
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
