<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户 API 对接说明与沙箱测试逻辑
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\TestNotifyService;
use support\Request;

/**
 * 商户门户 API 对接说明与沙箱测试逻辑
 *
 * 文档元数据、签名示例生成、测试 notify 记录均限定当前 token 商户，不泄露他人数据。
 */
class IntegrationLogic
{
    /**
     * 对接文档上下文（网关地址、鉴权说明、商户 IP 白名单等）
     *
     * @param Request $request 用于推断网关基址（notify_domain 未配置时）
     * @param int $merchantId 商户 ID（token）
     * @return array
     * @throws PaymentException
     */
    public function docs(Request $request, int $merchantId): array
    {
        $merchant = $this->loadMerchantForDocs($merchantId);
        $gatewayBase = $this->resolveGatewayBaseUrl($request);
        $phpDemo = $this->resolvePhpDemoDownloadMeta($request);

        return [
            'mch_id'                  => (string) ($merchant['mch_id'] ?? ''),
            'merchant_name'           => (string) ($merchant['name'] ?? ''),
            'gateway_base_url'        => $gatewayBase,
            'submit_order_url'        => $gatewayBase . '/submitOrder',
            'query_order_url'         => $gatewayBase . '/query',
            'pay_health_url'          => $gatewayBase . '/health',
            'default_test_notify_url' => TestNotifyService::resolveDefaultNotifyUrl(),
            'sign_type_md5'           => SignService::SIGN_TYPE_MD5,
            'sign_type_rsa'           => SignService::SIGN_TYPE_RSA,
            'time_window_seconds'     => SignService::DEFAULT_TIME_WINDOW,
            'ip_whitelist'            => (string) ($merchant['ip_whitelist'] ?? ''),
            'ip_whitelist_status'     => (int) ($merchant['ip_whitelist_status'] ?? 0),
            'has_rsa_public_key'      => trim((string) ($merchant['rsa_public_key'] ?? '')) !== '',
            'php_demo_filename'       => $phpDemo['filename'],
            'php_demo_download_url'   => $phpDemo['url'],
            'php_demo_available'      => $phpDemo['available'],
        ];
    }

    /**
     * 生成带签名的网关请求示例（供商户对照联调，密钥仅在服务端参与计算）
     *
     * @param int $merchantId 商户 ID
     * @param string $action submit|query
     * @param array $params 业务字段（不含 sign/time）
     * @return array{action:string, params:array, sign_string:string, curl_example:string}
     * @throws PaymentException
     */
    public function buildSignSample(int $merchantId, string $action, array $params): array
    {
        $merchant = $this->loadMerchantForDocs($merchantId);
        $mchId = (string) ($merchant['mch_id'] ?? '');
        $secretKey = (string) ($merchant['secret_key'] ?? '');
        if ($mchId === '' || $secretKey === '') {
            throw new PaymentException('商户对接凭证未配置完整');
        }

        $signType = (int) ($params['sign_type'] ?? SignService::SIGN_TYPE_MD5);
        if (!in_array($signType, [SignService::SIGN_TYPE_MD5, SignService::SIGN_TYPE_RSA], true)) {
            $signType = SignService::SIGN_TYPE_MD5;
        }

        $payload = match ($action) {
            'submit' => $this->normalizeSubmitSampleParams($mchId, $params),
            'query'  => $this->normalizeQuerySampleParams($mchId, $params),
            default  => throw new PaymentException('不支持的签名示例类型'),
        };

        $payload['time'] = (string) ($params['time'] ?? time());
        $payload['sign_type'] = $signType;

        // RSA 来签须商户自有私钥，平台不保管；门户仅生成 MD5 示例供对照
        if ($signType === SignService::SIGN_TYPE_RSA) {
            throw new PaymentException('RSA 签名请在商户服务器使用私钥生成，门户仅支持 MD5 签名示例');
        }

        $signString = SignService::buildSignString($payload, $secretKey);
        $payload['sign'] = SignService::makeSign($payload, $secretKey, SignService::SIGN_TYPE_MD5);
        $payload['sign_type'] = SignService::SIGN_TYPE_MD5;

        $url = $this->resolveGatewayBaseUrl(null)
            . ($action === 'submit' ? '/submitOrder' : '/query');

        return [
            'action'       => $action,
            'url'          => $url,
            'params'       => $payload,
            'sign_string'  => $signString,
            'curl_example' => $this->buildCurlExample($url, $payload),
        ];
    }

    /**
     * 测试 notify 记录（仅本商户 mch_id）
     */
    public function testNotifyRecent(string $mchId, int $limit = 20, ?string $orderNo = null, ?string $outTradeNo = null): array
    {
        $items = (new TestNotifyService())->listRecent(max(1, min(100, $limit * 5)), $orderNo, $outTradeNo);
        $filtered = [];
        foreach ($items as $row) {
            if (($row['mch_id'] ?? '') !== $mchId) {
                continue;
            }
            $filtered[] = $row;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        return [
            'items'              => $filtered,
            'default_notify_url' => TestNotifyService::resolveDefaultNotifyUrl(),
        ];
    }

    /**
     * 推断对外商户网关基址（以 /pay 结尾，含 nginx 反代前缀如 /prod/pay）
     *
     * 优先级：pay_gateway_base → notify_domain/pay → Host+api_path_prefix/pay → 本地 8787
     */
    public function resolveGatewayBaseUrl(?Request $request): string
    {
        $explicit = trim((string) config('plugin.paymentchannel.app.pay_gateway_base', ''));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        $domain = trim((string) config('plugin.paymentchannel.app.notify_domain', ''));
        if ($domain !== '') {
            return rtrim($domain, '/') . '/pay';
        }

        if ($request !== null) {
            $proto = trim((string) $request->header('x-forwarded-proto', ''));
            if ($proto === '') {
                $proto = $request->header(':scheme') ?: 'https';
            }
            $host = trim((string) $request->header('host', ''));
            if ($host !== '') {
                $prefix = trim((string) config('plugin.paymentchannel.app.api_path_prefix', ''), '/');
                $base = $proto . '://' . $host;
                if ($prefix !== '') {
                    $base .= '/' . $prefix;
                }

                return $base . '/pay';
            }
        }

        return 'http://127.0.0.1:8787/pay';
    }

    /**
     * PHP 对接 Demo 压缩包下载元数据（文件位于 server/public/，与 notify_domain 反代前缀一致）
     *
     * @return array{filename:string, url:string, available:bool}
     */
    public function resolvePhpDemoDownloadMeta(?Request $request): array
    {
        $filename = basename(trim((string) config('plugin.paymentchannel.app.php_demo_filename', 'merchant-php.zip')));
        if ($filename === '' || str_contains($filename, '..')) {
            $filename = 'merchant-php.zip';
        }

        $explicitUrl = trim((string) config('plugin.paymentchannel.app.php_demo_download_url', ''));
        if ($explicitUrl !== '') {
            $url = $explicitUrl;
        } else {
            $url = $this->resolvePublicAssetBaseUrl($request) . '/' . $filename;
        }

        $publicDir = rtrim((string) config('app.public_path', ''), DIRECTORY_SEPARATOR);
        $available = $publicDir !== '' && is_file($publicDir . DIRECTORY_SEPARATOR . $filename);

        return [
            'filename'  => $filename,
            'url'       => $url,
            'available' => $available,
        ];
    }

    /**
     * 静态资源基址（不含 /pay），与网关 notify_domain 前缀一致
     */
    protected function resolvePublicAssetBaseUrl(?Request $request): string
    {
        $domain = trim((string) config('plugin.paymentchannel.app.notify_domain', ''));
        if ($domain !== '') {
            return rtrim($domain, '/');
        }

        if ($request !== null) {
            $proto = trim((string) $request->header('x-forwarded-proto', ''));
            if ($proto === '') {
                $proto = $request->header(':scheme') ?: 'https';
            }
            $host = trim((string) $request->header('host', ''));
            if ($host !== '') {
                $prefix = trim((string) config('plugin.paymentchannel.app.api_path_prefix', ''), '/');
                $base = $proto . '://' . $host;
                if ($prefix !== '') {
                    $base .= '/' . $prefix;
                }

                return $base;
            }
        }

        return 'http://127.0.0.1:8787';
    }

    /**
     * 加载文档/签名所需商户字段（含 secret_key，仅服务端使用）
     */
    protected function loadMerchantForDocs(int $merchantId): array
    {
        $m = Merchant::where('id', $merchantId)->find();
        if (!$m) {
            throw new PaymentException('商户不存在');
        }

        return [
            'id'                  => (int) $m->id,
            'mch_id'              => (string) $m->mch_id,
            'name'                => (string) $m->name,
            'secret_key'          => (string) $m->secret_key,
            'rsa_private_key'     => (string) $m->rsa_private_key,
            'rsa_public_key'      => (string) $m->rsa_public_key,
            'ip_whitelist'        => (string) $m->ip_whitelist,
            'ip_whitelist_status' => (int) $m->ip_whitelist_status,
        ];
    }

    /**
     * 下单签名示例字段归一化
     */
    protected function normalizeSubmitSampleParams(string $mchId, array $params): array
    {
        $moneyYuan = (string) ($params['amount'] ?? '1.00');
        $moneyCents = (string) (int) bcmul($moneyYuan, '100', 0);

        return [
            'mch_id'         => $mchId,
            'pay_type'       => (string) ((int) ($params['pay_type'] ?? 3)),
            'money'          => $moneyCents,
            'order_id'       => trim((string) ($params['order_id'] ?? ('DEMO' . date('YmdHis')))),
            'notify_url'     => trim((string) ($params['notify_url'] ?? TestNotifyService::resolveDefaultNotifyUrl())),
            'return_url'     => trim((string) ($params['return_url'] ?? '')),
            'commodity_name' => trim((string) ($params['commodity_name'] ?? '对接测试商品')),
            'extra'          => trim((string) ($params['extra'] ?? '')),
            'client_ip'      => trim((string) ($params['client_ip'] ?? '127.0.0.1')),
        ];
    }

    /**
     * 查单签名示例字段归一化
     */
    protected function normalizeQuerySampleParams(string $mchId, array $params): array
    {
        $orderId = trim((string) ($params['order_id'] ?? $params['out_trade_no'] ?? ''));
        if ($orderId === '') {
            throw new PaymentException('请填写商户订单号 order_id');
        }

        return [
            'mch_id'   => $mchId,
            'order_id' => $orderId,
        ];
    }

    /**
     * 生成 curl 示例（application/x-www-form-urlencoded）
     */
    protected function buildCurlExample(string $url, array $params): string
    {
        $parts = [];
        foreach ($params as $key => $val) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $val);
        }

        return 'curl -X POST "' . $url . '" -H "Content-Type: application/x-www-form-urlencoded" -d "' . implode('&', $parts) . '"';
    }
}
