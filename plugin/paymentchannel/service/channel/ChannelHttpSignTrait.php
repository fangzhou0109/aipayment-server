<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游适配器公共能力 Trait（HTTP / 签名 / 金额换算 / 日志）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel;

use Closure;
use GuzzleHttp\Client;
use plugin\paymentchannel\app\model\ChannelLog;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\SensitiveHelper;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;
use Throwable;

/**
 * 上游适配器公共能力 Trait
 *
 * 沉淀「代收 / 代付」两类适配器共用的脏活：HTTP 请求、签名/验签、金额单位换算、交互日志。
 * 由 {@see AbstractChannelAdapter}（代收）与 {@see \plugin\paymentchannel\service\transfer\AbstractTransferAdapter}
 * （代付）复用，避免重复实现。
 *
 * 可测试性设计（关键）：
 *  - HTTP 传输与日志写入都做成**可注入闭包**（$transport / $logger）；生产环境为 null 时分别走
 *    Guzzle、写 sa_pay_channel_log；单测注入假传输/假日志，**不触发真实网络与 DB**。
 *  - 签名复用 {@see SignService}，金额换算复用 {@see AmountHelper}（禁浮点）。
 *
 * 凭证 / 密钥统一用 {@see ChannelCredential}（代收、代付共用，字段含网关地址 + 上游密钥）。
 */
trait ChannelHttpSignTrait
{
    /**
     * @param ChannelCredential $credential 上游凭证/配置（网关地址、密钥等）
     * @param Closure|null $transport 可注入 HTTP 传输：fn(string $url, array $params): string，返回响应体；null=用 Guzzle
     * @param Closure|null $logger    可注入日志写入：fn(int $type, string $bizNo, string $request, string $response): void；null=写 channel_log
     */
    public function __construct(
        protected readonly ChannelCredential $credential,
        protected readonly ?Closure $transport = null,
        protected readonly ?Closure $logger = null,
    ) {
    }

    /**
     * 上游回调成功后回应给上游的确认串，默认 success；上游不同可在子类覆盖。
     * @return string
     */
    public function successResponse(): string
    {
        return 'success';
    }

    /**
     * 用上游凭证对参数签名
     *
     * MD5 用 upstreamKey 拼串；RSA 额外用 upstreamPrivateKey 签名（平台对上游来件签名）。
     *
     * @param array $params 业务参数
     * @return string 签名值
     */
    protected function sign(array $params): string
    {
        return SignService::makeSign(
            $params,
            $this->credential->upstreamKey,
            $this->credential->signType,
            $this->credential->upstreamPrivateKey !== '' ? $this->credential->upstreamPrivateKey : null,
        );
    }

    /**
     * 校验上游来件签名
     *
     * MD5 用 upstreamKey 重算比对；RSA 用 upstreamPublicKey 验签（验上游公钥来签）。
     *
     * @param array $params 业务参数（含 sign）
     * @param string|null $sign 待校验签名；null 时取 $params['sign']
     * @return bool
     */
    protected function verifySign(array $params, ?string $sign = null): bool
    {
        return SignService::verify(
            $params,
            $this->credential->upstreamKey,
            $this->credential->signType,
            $this->credential->upstreamPublicKey !== '' ? $this->credential->upstreamPublicKey : null,
            $sign,
        );
    }

    /**
     * 发起 HTTP POST（表单），并记录交互日志（best-effort）
     *
     * 不论成功失败都会落一条 channel_log，便于排障；日志写入异常被吞掉，绝不影响主流程。
     *
     * @param string $url    目标地址
     * @param array  $params 表单参数
     * @param int    $type   交互类型（ChannelLog::TYPE_*）
     * @param string $bizNo  关联业务单号（用于日志检索）
     * @return string 上游响应体
     */
    protected function httpPost(string $url, array $params, int $type = ChannelLog::TYPE_CREATE, string $bizNo = ''): string
    {
        $response = '';
        try {
            $response = $this->transport !== null
                ? (string) ($this->transport)($url, $params)
                : $this->defaultPost($url, $params);
            return $response;
        } finally {
            // 无论是否抛异常，都把请求/响应落库，方便事后对账与排障
            $this->channelLog($type, $bizNo, $this->encode(['url' => $url, 'params' => $params]), $response);
        }
    }

    /**
     * 默认 HTTP 传输实现（Guzzle 表单 POST）
     *
     * @param string $url    目标地址
     * @param array  $params 表单参数
     * @return string 响应体
     */
    private function defaultPost(string $url, array $params): string
    {
        // http_errors=false：上游返回 4xx/5xx 也拿到响应体自行判定，不抛异常打断流程
        $client = new Client(['timeout' => 10, 'http_errors' => false]);
        $res = $client->request('POST', $url, ['form_params' => $params]);
        return (string) $res->getBody();
    }

    /**
     * 写一条上游交互日志（best-effort，永不抛出到业务流程）
     *
     * @param int    $type     交互类型
     * @param string $bizNo    业务单号
     * @param string $request  请求内容（建议 JSON）
     * @param string $response 响应内容
     */
    protected function channelLog(int $type, string $bizNo, string $request, string $response): void
    {
        try {
            // 日志脱敏（OWASP A09 安全日志）：对请求/响应中的卡号、密钥、签名等敏感字段掩码，
            // 避免明文持久化造成信息泄露。统一在此单一落库点处理，覆盖所有适配器调用。
            $request = SensitiveHelper::maskJson($request);
            $response = SensitiveHelper::maskJson($response);

            if ($this->logger !== null) {
                ($this->logger)($type, $bizNo, $request, $response);
                return;
            }
            // 生产默认：落库到 sa_pay_channel_log
            ChannelLog::create([
                'channel_id' => $this->credential->channelId ?: null,
                'biz_no' => $bizNo,
                'type' => $type,
                'request' => $request,
                'response' => $response,
            ]);
        } catch (Throwable) {
            // 日志失败绝不能影响支付主流程，吞掉异常
        }
    }

    /**
     * 元 → 分（整数字符串）。用 bcmath 换算，禁浮点。
     *
     * @param string $yuan 金额（元）
     * @return string 金额（分，整数字符串）
     */
    protected function toCents(string $yuan): string
    {
        return AmountHelper::format(AmountHelper::mul($yuan, '100'), 0);
    }

    /**
     * 分 → 元（4 位小数字符串）。用 bcmath 换算，禁浮点。
     *
     * @param int|float|string $cents 金额（分）
     * @return string 金额（元）
     */
    protected function toYuan(int|float|string $cents): string
    {
        return AmountHelper::div($cents, '100');
    }

    /**
     * JSON 编码（中文不转义、斜杠不转义），失败返回空串
     *
     * @param mixed $data 待编码数据
     * @return string
     */
    protected function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * JSON 解码为数组，失败返回空数组
     *
     * @param string $raw 原始 JSON 串
     * @return array
     */
    protected function decodeJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
