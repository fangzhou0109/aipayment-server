<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：ScanTransferAdapter（真实样例）行为测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service\transfer;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;
use plugin\paymentchannel\service\transfer\adapters\ScanTransferAdapter;
use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\dto\TransferOutcome;

/**
 * ScanTransferAdapter 行为测试
 *
 * 用注入的「假 HTTP 传输」断言：代付参数已正确换算（元→分）、已签名、响应解析正确，
 * **不触发真实网络**。覆盖受理成功/失败、回调解析、验签、查单三态。
 */
class ScanTransferAdapterTest extends TestCase
{
    /** 上游 MD5 密钥 */
    private const KEY = 'scan_transfer_key';

    /**
     * 构造凭证
     */
    private function credential(): ChannelCredential
    {
        return new ChannelCredential(
            channelId: 2,
            gatewayUrl: 'https://up.example.com/transfer',
            upstreamMchId: 'UPMCH001',
            upstreamKey: self::KEY,
            signType: SignService::SIGN_TYPE_MD5,
        );
    }

    /**
     * 受理成功：金额换算为分、参数已签名且可验签、响应正确解析
     */
    public function testCreateTransferAccepted(): void
    {
        $captured = [];
        $transport = function (string $url, array $params) use (&$captured): string {
            $captured = ['url' => $url, 'params' => $params];
            return json_encode([
                'code' => 0,
                'msg' => 'ok',
                'data' => ['trade_no' => 'UPTRADE888'],
            ]);
        };
        $adapter = new ScanTransferAdapter($this->credential(), $transport, fn() => null);

        $result = $adapter->createTransfer(new CreateTransferRequest(
            transferNo: 'T20260608130000001',
            amount: '100.0000',
            accountName: '王五',
            accountNo: '6222999988887777',
            bankCode: 'ICBC',
            notifyUrl: 'https://plat.example.com/pay/transferNotify/scan',
        ));

        $this->assertTrue($result->success);
        $this->assertSame('UPTRADE888', $result->upstreamNo);

        // 提交给上游的金额应为分（100 元 → 10000）
        $this->assertSame('https://up.example.com/transfer', $captured['url']);
        $this->assertSame('10000', $captured['params']['money']);
        $this->assertSame('T20260608130000001', $captured['params']['out_trade_no']);
        $this->assertSame('王五', $captured['params']['account_name']);
        // 参数已签名且可被上游用同密钥验签
        $this->assertArrayHasKey('sign', $captured['params']);
        $this->assertTrue(SignService::verify($captured['params'], self::KEY, SignService::SIGN_TYPE_MD5));
    }

    /**
     * 受理失败：上游返回非成功码 → 结果为 fail，携带原因
     */
    public function testCreateTransferUpstreamFail(): void
    {
        $transport = fn(string $url, array $params): string => json_encode(['code' => 500, 'msg' => '余额不足']);
        $adapter = new ScanTransferAdapter($this->credential(), $transport, fn() => null);

        $result = $adapter->createTransfer(new CreateTransferRequest(
            transferNo: 'T2',
            amount: '10.0000',
            accountName: '赵六',
            accountNo: '6222000011112222',
        ));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('余额不足', $result->message);
    }

    /**
     * 回调解析：trade_status=SUCCESS → 成功，金额分→元
     */
    public function testParseNotifySuccess(): void
    {
        $adapter = new ScanTransferAdapter($this->credential(), null, fn() => null);

        $result = $adapter->parseTransferNotify([
            'out_trade_no' => 'T20260608130000005',
            'trade_no' => 'UPT55',
            'money' => '25000',
            'trade_status' => 'SUCCESS',
        ]);

        $this->assertSame(TransferOutcome::Success, $result->outcome);
        $this->assertSame('T20260608130000005', $result->transferNo);
        $this->assertSame('250.0000', $result->amount);
        $this->assertSame('UPT55', $result->upstreamNo);
    }

    /**
     * 回调解析：trade_status=FAIL → 失败
     */
    public function testParseNotifyFailed(): void
    {
        $adapter = new ScanTransferAdapter($this->credential(), null, fn() => null);

        $result = $adapter->parseTransferNotify([
            'out_trade_no' => 'T6',
            'money' => '100',
            'trade_status' => 'FAIL',
        ]);

        $this->assertSame(TransferOutcome::Failed, $result->outcome);
    }

    /**
     * 验签：正确签名通过，篡改后失败
     */
    public function testVerifyNotify(): void
    {
        $adapter = new ScanTransferAdapter($this->credential(), null, fn() => null);

        $payload = [
            'out_trade_no' => 'T20260608130000006',
            'trade_no' => 'UPT66',
            'money' => '30000',
            'trade_status' => 'SUCCESS',
        ];
        $payload['sign'] = SignService::makeSign($payload, self::KEY, SignService::SIGN_TYPE_MD5);
        $this->assertTrue($adapter->verifyNotify($payload));

        $payload['money'] = '1';
        $this->assertFalse($adapter->verifyNotify($payload));
    }

    /**
     * 查单：上游返回 SUCCESS → 成功；未知状态 → 处理中（避免误判终结）
     */
    public function testQueryTransferStates(): void
    {
        // 成功
        $okTransport = fn(string $url, array $params): string => json_encode([
            'code' => 0,
            'data' => ['trade_status' => 'SUCCESS', 'money' => '12300', 'trade_no' => 'UPTQ1'],
        ]);
        $adapter = new ScanTransferAdapter($this->credential(), $okTransport, fn() => null);
        $ok = $adapter->queryTransfer('T20260608130000007');
        $this->assertSame(TransferOutcome::Success, $ok->outcome);
        $this->assertSame('123.0000', $ok->amount);
        $this->assertSame('UPTQ1', $ok->upstreamNo);

        // 未知状态 → 处理中
        $pendingTransport = fn(string $url, array $params): string => json_encode([
            'code' => 0,
            'data' => ['trade_status' => 'DEALING', 'money' => '0'],
        ]);
        $adapter2 = new ScanTransferAdapter($this->credential(), $pendingTransport, fn() => null);
        $pending = $adapter2->queryTransfer('T20260608130000008');
        $this->assertSame(TransferOutcome::Processing, $pending->outcome);
    }
}
