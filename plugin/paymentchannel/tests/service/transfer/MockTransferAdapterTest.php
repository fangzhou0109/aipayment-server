<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：MockTransferAdapter 行为测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service\transfer;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;
use plugin\paymentchannel\service\transfer\adapters\MockTransferAdapter;
use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\dto\TransferOutcome;

/**
 * MockTransferAdapter 行为测试
 *
 * 跑通「代付下单 → 回调解析 → 验签 → 查单」全链路，验证受理、分→元换算、MD5 验签。
 */
class MockTransferAdapterTest extends TestCase
{
    /** MD5 密钥（用于构造回调签名） */
    private const KEY = 'mock_transfer_secret';

    /**
     * 构造注入了内存日志的 MockTransferAdapter
     *
     * @param array<int,array> $logSink 引用：收集日志条目
     * @return MockTransferAdapter
     */
    private function makeAdapter(array &$logSink): MockTransferAdapter
    {
        $credential = new ChannelCredential(
            channelId: 1,
            upstreamKey: self::KEY,
            signType: SignService::SIGN_TYPE_MD5,
        );
        $logger = function (int $type, string $bizNo, string $request, string $response) use (&$logSink): void {
            $logSink[] = compact('type', 'bizNo', 'request', 'response');
        };
        return new MockTransferAdapter($credential, null, $logger);
    }

    /**
     * 代付受理：返回受理成功 + 模拟上游单号，并落一条日志
     */
    public function testCreateTransferAccepted(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $request = new CreateTransferRequest(
            transferNo: 'T20260608120000001',
            amount: '100.0000',
            accountName: '张三',
            accountNo: '6222000000000000',
            notifyUrl: 'https://plat.example.com/pay/transferNotify/mock',
        );
        $result = $adapter->createTransfer($request);

        $this->assertTrue($result->success);
        $this->assertSame('MOCKTT20260608120000001', $result->upstreamNo);
        $this->assertCount(1, $logSink);
    }

    /**
     * 回调解析：分→元换算正确、状态映射成功、单号取自 out_trade_no
     */
    public function testParseNotifySuccess(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $result = $adapter->parseTransferNotify([
            'out_trade_no' => 'T20260608120000002',
            'trade_no' => 'UPT998',
            'money' => '10000',
            'status' => 'success',
        ]);

        $this->assertSame(TransferOutcome::Success, $result->outcome);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('T20260608120000002', $result->transferNo);
        $this->assertSame('UPT998', $result->upstreamNo);
        $this->assertSame('100.0000', $result->amount);
    }

    /**
     * 回调解析：非 success 视为失败
     */
    public function testParseNotifyFailed(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $result = $adapter->parseTransferNotify([
            'out_trade_no' => 'T3',
            'money' => '500',
            'status' => 'reject',
        ]);

        $this->assertSame(TransferOutcome::Failed, $result->outcome);
        $this->assertTrue($result->isFailed());
        $this->assertSame('5.0000', $result->amount);
    }

    /**
     * 验签：正确签名通过，篡改金额后拒绝
     */
    public function testVerifyNotify(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $payload = [
            'out_trade_no' => 'T20260608120000003',
            'trade_no' => 'UPT55',
            'money' => '20000',
            'status' => 'success',
        ];
        $payload['sign'] = SignService::makeSign($payload, self::KEY, SignService::SIGN_TYPE_MD5);
        $this->assertTrue($adapter->verifyNotify($payload));

        $tampered = $payload;
        $tampered['money'] = '99999';
        $this->assertFalse($adapter->verifyNotify($tampered));
    }

    /**
     * 端到端：代付受理 → 构造带签回调 → 验签 → 解析为成功
     */
    public function testEndToEnd(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $transferNo = 'T20260608120000009';
        $create = $adapter->createTransfer(new CreateTransferRequest(
            transferNo: $transferNo,
            amount: '88.8800',
            accountName: '李四',
            accountNo: '6222111122223333',
        ));
        $this->assertTrue($create->success);

        $callback = [
            'out_trade_no' => $transferNo,
            'trade_no' => $create->upstreamNo,
            'money' => '8888',
            'status' => 'success',
        ];
        $callback['sign'] = SignService::makeSign($callback, self::KEY, SignService::SIGN_TYPE_MD5);

        $this->assertTrue($adapter->verifyNotify($callback));
        $parsed = $adapter->parseTransferNotify($callback);
        $this->assertTrue($parsed->isSuccess());
        $this->assertSame($transferNo, $parsed->transferNo);
        $this->assertSame('88.8800', $parsed->amount);
        $this->assertSame('success', $adapter->successResponse());
    }

    /**
     * 查单：Mock 恒返回出款成功
     */
    public function testQueryTransferSuccess(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $result = $adapter->queryTransfer('T20260608120000010');
        $this->assertTrue($result->isSuccess());
        $this->assertSame('MOCKTT20260608120000010', $result->upstreamNo);
    }
}
