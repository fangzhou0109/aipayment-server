<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：Mock 模拟代付适配器
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer\adapters;

use plugin\paymentchannel\app\model\ChannelLog;
use plugin\paymentchannel\service\transfer\AbstractTransferAdapter;
use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\dto\CreateTransferResult;
use plugin\paymentchannel\service\transfer\dto\TransferStatusResult;

/**
 * Mock 模拟代付适配器
 *
 * 不连真实上游，用于本地 / 灰度联调与自动化测试，跑通「代付下单 → 回调」全链路：
 *  - createTransfer：不发 HTTP，直接返回受理成功与模拟上游单号；
 *  - parseTransferNotify：按约定字段（out_trade_no/trade_no/money 分/status）翻译为统一状态；
 *  - verifyNotify：用 MD5（upstreamKey）真实验签，便于验证签名链路；
 *  - queryTransfer：恒定返回「出款成功」，方便联调补单 / 查单逻辑。
 *
 * 回调约定字段（与平台代付回调网关回放保持一致）：
 *   out_trade_no=平台代付单号, trade_no=上游单号, money=金额(分), status=success|fail, sign=MD5签名
 */
class MockTransferAdapter extends AbstractTransferAdapter
{
    /**
     * 模拟发起代付：直接返回受理成功与模拟上游单号
     *
     * @param CreateTransferRequest $request 代付入参
     * @return CreateTransferResult
     */
    public function createTransfer(CreateTransferRequest $request): CreateTransferResult
    {
        $upstreamNo = 'MOCKT' . $request->transferNo;

        // 落一条代付交互日志（注入 logger 时进内存，否则进 channel_log）
        $this->channelLog(
            ChannelLog::TYPE_TRANSFER,
            $request->transferNo,
            $this->encode($request),
            $this->encode(['accepted' => true, 'upstream_no' => $upstreamNo]),
        );

        return CreateTransferResult::ok($upstreamNo, 'mock');
    }

    /**
     * 解析模拟代付回调为统一状态
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

        // status=success 视为出款成功，其余视为失败
        $ok = (string) ($payload['status'] ?? '') === 'success';

        return $ok
            ? TransferStatusResult::success($transferNo, $amount, $upstreamNo, $raw)
            : TransferStatusResult::failed($transferNo, 'mock transfer failed', $amount, $upstreamNo, $raw);
    }

    /**
     * 校验模拟回调签名（MD5）
     *
     * @param array $payload 回调参数（含 sign）
     * @return bool
     */
    public function verifyNotify(array $payload): bool
    {
        return $this->verifySign($payload);
    }

    /**
     * 模拟查单：恒返回出款成功，便于联调补单流程
     *
     * @param string $transferNo 平台代付单号
     * @param string $upstreamNo 上游代付单号
     * @return TransferStatusResult
     */
    public function queryTransfer(string $transferNo, string $upstreamNo = ''): TransferStatusResult
    {
        return TransferStatusResult::success(
            $transferNo,
            '0.0000',
            $upstreamNo !== '' ? $upstreamNo : 'MOCKT' . $transferNo,
            'mock-query'
        );
    }
}
