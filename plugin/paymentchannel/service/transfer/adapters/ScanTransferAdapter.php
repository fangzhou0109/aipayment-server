<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：通用银行卡代付适配器（真实样例）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer\adapters;

use plugin\paymentchannel\app\model\ChannelLog;
use plugin\paymentchannel\service\transfer\AbstractTransferAdapter;
use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\dto\CreateTransferResult;
use plugin\paymentchannel\service\transfer\dto\TransferStatusResult;

/**
 * 通用银行卡代付适配器（真实样例）
 *
 * 对接一类「主流代付上游」的典型协议，作为接入真实代付上游的范本，演示如何在适配器内完成：
 * 字段映射 → 金额换算（元→分）→ 签名 → HTTP 表单提交 → 解析响应。
 *
 * 约定的上游协议（样例）：
 *  - 代付：POST 表单到 gatewayUrl，参数 mch_id/out_trade_no/money(分)/account_name/account_no/
 *    bank_code/notify_url/...，MD5 签名；响应 JSON：{ code: 0|200, msg, data: { trade_no } }
 *    （受理成功表示进入处理中，最终成败以回调为准；兼容平铺 trade_no）。
 *  - 回调：上游 POST 表单 mch_id/out_trade_no/trade_no/money(分)/trade_status/sign；
 *    trade_status=SUCCESS 视为出款成功，FAIL 视为失败。
 *  - 查单：POST 表单到 extra['query_url']（缺省用 gatewayUrl），返回同回调的 trade_status。
 *
 * 真实接入新代付上游时，复制本类改字段映射即可，核心业务零改动。
 */
class ScanTransferAdapter extends AbstractTransferAdapter
{
    /** 上游判定为受理成功的响应码集合（兼容多种写法） */
    private const SUCCESS_CODES = ['0', '200', 'success', 'SUCCESS'];

    /** 上游判定为出款成功的交易状态集合 */
    private const SUCCESS_STATES = ['SUCCESS', 'success', '1', '2'];

    /** 上游判定为出款失败的交易状态集合 */
    private const FAILED_STATES = ['FAIL', 'fail', 'FAILED', 'failed', '3'];

    /**
     * 向上游发起代付
     *
     * @param CreateTransferRequest $request 代付入参
     * @return CreateTransferResult
     */
    public function createTransfer(CreateTransferRequest $request): CreateTransferResult
    {
        // 1) 组装上游代付参数：平台单号作为上游侧商户订单号，金额换算为分
        $params = [
            'mch_id' => $this->credential->upstreamMchId,
            'out_trade_no' => $request->transferNo,
            'money' => $this->toCents($request->amount),
            'account_name' => $request->accountName,
            'account_no' => $request->accountNo,
            'bank_name' => $request->bankName,
            'bank_code' => $request->bankCode,
            'notify_url' => $request->notifyUrl,
            'extra' => $request->extra,
            'time' => (string) time(),
        ];
        // 2) 签名（MD5/RSA 由凭证 signType 决定）
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = $this->credential->signType;

        // 3) 提交并落日志（交互类型=代付）
        $raw = $this->httpPost($this->credential->gatewayUrl, $params, ChannelLog::TYPE_TRANSFER, $request->transferNo);
        $resp = $this->decodeJson($raw);

        // 4) 解析响应：兼容 data 嵌套与平铺两种结构
        $code = (string) ($resp['code'] ?? '');
        if (!in_array($code, self::SUCCESS_CODES, true)) {
            $msg = (string) ($resp['msg'] ?? $resp['message'] ?? '上游代付受理失败');
            return CreateTransferResult::fail($msg, $raw);
        }
        $data = is_array($resp['data'] ?? null) ? $resp['data'] : $resp;
        $upstreamNo = (string) ($data['trade_no'] ?? $data['order_no'] ?? '');

        return CreateTransferResult::ok($upstreamNo, $raw);
    }

    /**
     * 解析上游代付回调
     *
     * @param array $payload 回调参数
     * @return TransferStatusResult
     */
    public function parseTransferNotify(array $payload): TransferStatusResult
    {
        $transferNo = (string) ($payload['out_trade_no'] ?? '');
        $upstreamNo = (string) ($payload['trade_no'] ?? '');
        $amount = $this->toYuan((string) ($payload['money'] ?? '0'));
        $raw = $this->encode($payload);

        $state = (string) ($payload['trade_status'] ?? $payload['status'] ?? '');
        if (in_array($state, self::SUCCESS_STATES, true)) {
            return TransferStatusResult::success($transferNo, $amount, $upstreamNo, $raw);
        }
        if (in_array($state, self::FAILED_STATES, true)) {
            return TransferStatusResult::failed($transferNo, '上游代付失败:' . $state, $amount, $upstreamNo, $raw);
        }
        // 未知状态按处理中（避免误判终结代付单）
        return TransferStatusResult::processing($transferNo, $amount, $upstreamNo, $raw);
    }

    /**
     * 校验上游回调签名
     *
     * @param array $payload 回调参数（含 sign）
     * @return bool
     */
    public function verifyNotify(array $payload): bool
    {
        return $this->verifySign($payload);
    }

    /**
     * 主动向上游查代付结果
     *
     * @param string $transferNo 平台代付单号
     * @param string $upstreamNo 上游代付单号
     * @return TransferStatusResult
     */
    public function queryTransfer(string $transferNo, string $upstreamNo = ''): TransferStatusResult
    {
        $params = [
            'mch_id' => $this->credential->upstreamMchId,
            'out_trade_no' => $transferNo,
            'trade_no' => $upstreamNo,
            'time' => (string) time(),
        ];
        $params['sign'] = $this->sign($params);

        // 查单地址可在 extra.query_url 单独配置，缺省复用网关地址
        $queryUrl = (string) ($this->credential->extra['query_url'] ?? $this->credential->gatewayUrl);
        $raw = $this->httpPost($queryUrl, $params, ChannelLog::TYPE_QUERY, $transferNo);
        $resp = $this->decodeJson($raw);

        $data = is_array($resp['data'] ?? null) ? $resp['data'] : $resp;
        $state = (string) ($data['trade_status'] ?? $data['status'] ?? '');
        $amount = $this->toYuan((string) ($data['money'] ?? '0'));
        $respUpstreamNo = (string) ($data['trade_no'] ?? $upstreamNo);

        if (in_array($state, self::SUCCESS_STATES, true)) {
            return TransferStatusResult::success($transferNo, $amount, $respUpstreamNo, $raw);
        }
        if (in_array($state, self::FAILED_STATES, true)) {
            return TransferStatusResult::failed($transferNo, '上游查单失败:' . $state, $amount, $respUpstreamNo, $raw);
        }
        return TransferStatusResult::processing($transferNo, $amount, $respUpstreamNo, $raw);
    }
}
