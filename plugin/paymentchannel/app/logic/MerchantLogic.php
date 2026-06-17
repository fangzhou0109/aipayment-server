<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户管理逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\LedgerService;
use plugin\paymentchannel\service\MerchantKeyService;
use plugin\saiadmin\exception\ApiException;
use RuntimeException;
/**
 * 商户管理逻辑层
 *
 * 复用 PaymentBaseLogic 的 search/getList/read/destroy；重写 add/edit 以处理：
 *  - 新增时自动生成 MD5 密钥与平台 RSA 密钥对；
 *  - 余额等敏感字段禁止前端直接写入（只读），防止越权改账。
 */
class MerchantLogic extends PaymentBaseLogic
{
    /**
     * 入账服务（人工调账等须走 LedgerService，禁止直写 balance 字段）
     * @var LedgerService
     */
    private LedgerService $ledger;

    /**
     * 余额相关只读字段：绝不允许通过 add/edit 接口直接写入，
     * 余额变动只能走 LedgerService 事务记账（Phase 3.4）。
     * @var array
     */
    private array $readonlyFields = ['balance', 'balance_freeze'];

    /**
     * 商户级代收费率：Phase 9.1 起不参与运行时，编辑时剔除。
     * @var array
     */
    private array $legacyPayRateFields = ['rate'];

    /**
     * 新增时强制归零的代收费率（实际代收计费走 merchant_channel；代付费率/限额于操作列单独配置）
     * @var array
     */
    private array $zeroRateFieldsOnAdd = ['rate'];

    /**
     * @param LedgerService|null $ledger 资金服务（测试可注入）
     */
    public function __construct(?LedgerService $ledger = null)
    {
        $this->model = new Merchant();
        $this->ledger = $ledger ?? new LedgerService();
    }

    /**
     * 新增商户
     *
     * 自动补充密钥：若未显式传入则生成 MD5 secret_key 与平台 RSA 私钥；
     * 同时剔除余额只读字段，余额初始为表默认 0。
     *
     * @param array $data 商户数据
     * @return mixed 新增主键ID
     */
    public function add(array $data): mixed
    {
        $data = $this->filterReadonly($data);
        $data = $this->normalizeLegacyRatesForAdd($data);
        $data = $this->normalizeRateTransfer($data);
        $data = $this->normalizeIpWhitelist($data);
        $data = $this->hashPassword($data);

        // 未提供 MD5 密钥时自动生成
        if (empty($data['secret_key'])) {
            $data['secret_key'] = MerchantKeyService::generateSecretKey();
        }
        // 未提供平台 RSA 私钥时自动生成一对（私钥平台留存，公钥下发商户）
        if (empty($data['rsa_private_key'])) {
            $pair = MerchantKeyService::generateRsaKeyPair();
            $data['rsa_private_key'] = $pair['private'];
            // 平台公钥暂存到备注无意义，这里仅生成私钥；商户公钥由商户上传至 rsa_public_key
        }

        return parent::add($data);
    }

    /**
     * 修改商户
     *
     * 剔除余额只读字段，避免通过编辑接口篡改余额。
     *
     * @param mixed $id 主键
     * @param array $data 商户数据
     * @return mixed
     */
    public function edit($id, array $data): mixed
    {
        $data = $this->filterReadonly($data);
        $data = $this->stripLegacyPayRate($data);
        $data = $this->normalizeRateTransfer($data);
        $data = $this->normalizeSingleLimits($data);
        $data = $this->normalizeIpWhitelist($data);
        $data = $this->hashPassword($data);
        return parent::edit($id, $data);
    }

    /**
     * 平台人工调账：增加或扣减商户可用余额
     *
     * @param int $merchantId 商户ID
     * @param string $direction increase=加款 / decrease=扣款
     * @param string $amountYuan 变动金额（元，正数）
     * @param string $remark 备注（写入资金流水，便于审计）
     * @return array 调账结果 + 商户快照
     * @throws PaymentException
     */
    public function adjustBalance(int $merchantId, string $direction, string $amountYuan, string $remark = ''): array
    {
        if (!in_array($direction, ['increase', 'decrease'], true)) {
            throw new PaymentException('调账方向非法');
        }

        $amount = AmountHelper::format($amountYuan);
        if (!AmountHelper::gtZero($amount)) {
            throw new PaymentException('调账金额必须大于 0');
        }

        $merchant = Merchant::where('id', $merchantId)->find();
        if (!$merchant) {
            throw new PaymentException('商户不存在');
        }

        $signedAmount = $direction === 'decrease' ? AmountHelper::sub('0', $amount) : $amount;
        $adjustNo = 'ADJ' . date('YmdHis') . random_int(1000, 9999);
        $flowRemark = trim($remark);
        if ($flowRemark === '') {
            $flowRemark = $direction === 'increase' ? '平台人工加款' : '平台人工扣款';
        }

        $result = $this->transaction(function () use ($merchant, $signedAmount, $adjustNo, $flowRemark) {
            try {
                return $this->ledger->adjustBalance(
                    (int) $merchant->id,
                    (string) $merchant->mch_id,
                    $signedAmount,
                    $adjustNo,
                    $flowRemark,
                );
            } catch (RuntimeException $e) {
                throw new PaymentException($e->getMessage());
            }
        });

        return array_merge($result, [
            'merchant_id' => (int) $merchant->id,
            'mch_id'      => (string) $merchant->mch_id,
            'direction'   => $direction,
            'balance'     => $result['after_balance'],
        ]);
    }

    /**
     * 重置商户密钥（重新生成 MD5 secret_key 与平台 RSA 私钥）
     *
     * @param mixed $id 商户ID
     * @return array 对接凭证包（secret_key + 平台公钥，供一次性下发商户）
     */
    public function resetKey($id): array
    {
        $model = $this->read($id);
        $secretKey = MerchantKeyService::generateSecretKey();
        $pair = MerchantKeyService::generateRsaKeyPair();
        $model->save([
            'secret_key'      => $secretKey,
            'rsa_private_key' => $pair['private'],
        ]);

        return $this->issueCredentials((int) $model->id);
    }

    /**
     * 组装商户对接凭证包（创建/重置后一次性下发）
     *
     * 含 mch_id、MD5 secret_key、平台 RSA 公钥（由平台留存私钥推导，用于商户验异步通知）。
     * 不含 rsa_private_key / 商户来签公钥。
     *
     * @param int $merchantId 商户ID
     * @return array{id:int,mch_id:string,secret_key:string,platform_rsa_public_key:string}
     * @throws ApiException 商户不存在
     */
    public function issueCredentials(int $merchantId): array
    {
        $merchant = Merchant::where('id', $merchantId)->find();
        if (!$merchant) {
            throw new ApiException('商户不存在');
        }

        return self::formatIssueCredentials($merchant);
    }

    /**
     * 平台后台查看商户 API 对接资料（含商户已上传的来签公钥）
     *
     * @param int $merchantId 商户ID
     * @return array
     * @throws ApiException
     */
    public function viewApiCredentials(int $merchantId): array
    {
        $merchant = Merchant::where('id', $merchantId)->find();
        if (!$merchant) {
            throw new ApiException('商户不存在');
        }

        return array_merge(self::formatIssueCredentials($merchant), [
            'merchant_name'  => (string) $merchant->name,
            'rsa_public_key' => (string) ($merchant->rsa_public_key ?? ''),
        ]);
    }

    /**
     * 商户门户：更新商户来签 RSA 公钥（rsa_public_key）
     *
     * @param int $merchantId 商户ID
     * @param string $rsaPublicKey PEM 公钥；空串表示清除（仅用 MD5 对接）
     * @throws PaymentException 公钥格式无效
     */
    public function updateMerchantRsaPublicKey(int $merchantId, string $rsaPublicKey): void
    {
        $trimmed = trim($rsaPublicKey);
        if ($trimmed !== '') {
            $this->assertValidRsaPublicKey($trimmed);
        }

        $model = $this->read($merchantId);
        $model->save(['rsa_public_key' => $trimmed === '' ? null : $trimmed]);
    }

    /**
     * 商户门户：更新 API 代付自动下发阈值（每商户独立）
     *
     * @param int $merchantId 商户ID
     * @param string $threshold 阈值（元）；0 表示回落全局/全部转人工
     * @return string 规整后的阈值（四位小数）
     * @throws PaymentException 格式非法/负数
     */
    public function updateAutoDisbursementThreshold(int $merchantId, string $threshold): string
    {
        $trimmed = trim($threshold);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            throw new PaymentException('代付自动下发阈值格式非法（应为不小于 0 的数字）');
        }
        if (AmountHelper::compare($trimmed, '0') < 0) {
            throw new PaymentException('代付自动下发阈值不能为负数');
        }
        $value = AmountHelper::format($trimmed);

        $model = $this->read($merchantId);
        // 阈值不得超过商户可用余额（避免单笔自动下发超出账户可用资金）
        $balance = AmountHelper::format((string) ($model->balance ?? '0'));
        if (AmountHelper::compare($value, $balance) > 0) {
            throw new PaymentException('代付自动下发阈值不能超过商户可用余额（' . $balance . ' 元）');
        }
        $model->save(['auto_disbursement_threshold' => $value]);
        return $value;
    }

    /**
     * 从商户模型组装下发凭证（直接读属性，绕过 $hidden）
     *
     * @param Merchant $merchant 商户模型
     * @return array{id:int,mch_id:string,secret_key:string,platform_rsa_public_key:string}
     */
    public static function formatIssueCredentials(Merchant $merchant): array
    {
        $platformPublic = MerchantKeyService::extractPublicKeyFromPrivate((string) $merchant->rsa_private_key) ?? '';

        return [
            'id'                      => (int) $merchant->id,
            'mch_id'                  => (string) $merchant->mch_id,
            'secret_key'              => (string) $merchant->secret_key,
            'platform_rsa_public_key' => $platformPublic,
        ];
    }

    /**
     * 校验 PEM RSA 公钥可被 openssl 解析
     *
     * @param string $publicKey PEM 或裸 base64
     * @throws PaymentException
     */
    private function assertValidRsaPublicKey(string $publicKey): void
    {
        $pem = str_contains($publicKey, 'BEGIN')
            ? $publicKey
            : "-----BEGIN PUBLIC KEY-----\n"
                . wordwrap($publicKey, 64, "\n", true)
                . "\n-----END PUBLIC KEY-----";

        if (openssl_pkey_get_public($pem) === false) {
            throw new PaymentException('RSA 公钥格式无效，请粘贴 PEM 格式公钥');
        }
    }

    /**
     * 过滤只读字段
     * @param array $data 原始数据
     * @return array 过滤后的数据
     */
    private function filterReadonly(array $data): array
    {
        foreach ($this->readonlyFields as $field) {
            unset($data[$field]);
        }
        return $data;
    }

    /**
     * 新增商户：商户表 rate 固定为 0（代收费率在 merchant_channel 配置）；rate_transfer 由表单传入
     */
    private function normalizeLegacyRatesForAdd(array $data): array
    {
        foreach ($this->zeroRateFieldsOnAdd as $field) {
            $data[$field] = '0.0000';
        }

        return $data;
    }

    /**
     * 编辑商户：禁止经接口改写代收费率（走 merchant_channel 维护）
     */
    private function stripLegacyPayRate(array $data): array
    {
        foreach ($this->legacyPayRateFields as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    /**
     * 格式化商户全局代付费率（提现无通道时的保底费率）
     */
    private function normalizeRateTransfer(array $data): array
    {
        if (array_key_exists('rate_transfer', $data)) {
            $data['rate_transfer'] = AmountHelper::format((string) $data['rate_transfer']);
        }

        return $data;
    }

    /**
     * 格式化商户级单笔限额（操作列「单笔限额」弹窗维护）
     */
    private function normalizeSingleLimits(array $data): array
    {
        foreach (['single_min', 'single_max'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = AmountHelper::format((string) $data[$field]);
            }
        }

        return $data;
    }

    /**
     * 规范化 IP 白名单字符串（中文逗号转英文、去空白）
     */
    private function normalizeIpWhitelist(array $data): array
    {
        if (!array_key_exists('ip_whitelist', $data)) {
            return $data;
        }
        $raw = str_replace('，', ',', (string) $data['ip_whitelist']);
        $items = array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '');
        $data['ip_whitelist'] = $items === [] ? '' : implode(',', $items);

        return $data;
    }

    /**
     * 商户门户登录密码哈希处理
     *
     * 入库前把明文 `password` 转 `password_hash`（商户门户登录用 password_verify 校验）；
     * 空密码视为「不修改」直接剔除，避免把已有密码覆盖为空。
     *
     * @param array $data 原始数据
     * @return array 处理后的数据
     */
    protected function hashPassword(array $data): array
    {
        if (array_key_exists('password', $data)) {
            $pwd = (string) $data['password'];
            if ($pwd === '') {
                // 空密码不覆盖（编辑时未改密码的常见情况）
                unset($data['password']);
            } else {
                $data['password'] = password_hash($pwd, PASSWORD_DEFAULT);
            }
        }
        return $data;
    }
}
