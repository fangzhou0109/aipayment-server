<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户提现逻辑层（状态机：申请→冻结→审核→下发→回调）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use Closure;
use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\BankCard;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\model\NotifyLog;
use plugin\paymentchannel\app\model\Withdraw;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\RateResolver;
use plugin\paymentchannel\service\LedgerService;
use plugin\paymentchannel\service\MerchantNotifyService;
use plugin\paymentchannel\service\OrderNoGenerator;
use plugin\paymentchannel\service\transfer\dto\CreateTransferRequest;
use plugin\paymentchannel\service\transfer\TransferAdapterFactory;
use plugin\paymentchannel\service\transfer\TransferAdapterInterface;
use plugin\paymentchannel\service\transfer\TransferAdapterRegistry;
use Throwable;

/**
 * 商户提现逻辑层
 *
 * 完整提现状态机与资金流（全程 capital_flow 双账户记账，账实一致）：
 *   申请 apply        ：可用余额→冻结余额，建单 status=待审核(0)
 *   审核 audit        ：拒绝→退款 status=审核拒绝(-1)；pass→线下打款完成扣冻结 status=成功(3)；disburse→代付下发
 *   下发 disburse     ：status=代付中(2)，调代付适配器；受理失败→退款 status=代付失败(-2)
 *   回调 confirmXxx   ：成功→冻结扣减 status=成功(3)；失败→退款解冻 status=代付失败(-2)
 *
 * 资金安全：
 *  - 冻结/扣减/退款均在事务内调 {@see LedgerService}，余额与流水同事务，禁浮点；
 *  - 状态机 + 行锁保证每个状态转移仅发生一次（幂等）；LedgerService 幂等键兜底。
 *
 * 可测试性：LedgerService 与「代付适配器工厂」可注入；DB 访问抽为 protected 接缝，
 * 单测以子类重写 + 注入假实现，全分支脱离 DB / 网络。
 */
class WithdrawLogic extends PaymentBaseLogic
{
    /**
     * 入账/资金服务
     * @var LedgerService
     */
    private LedgerService $ledger;

    /**
     * 代付适配器工厂：fn(): TransferAdapterInterface（封装代付通道与凭证解析）
     * 为 null 时下发回退到「按配置 transfer_channel_code 解析通道」的生产默认实现。
     * @var Closure|null
     */
    private ?Closure $transferAdapterFactory;

    /**
     * 代付费率解析器（可注入，单测用 TestableRateResolver）
     */
    private ?RateResolver $rateResolver = null;

    /**
     * @param LedgerService|null $ledger 资金服务（测试可注入）
     * @param Closure|null $transferAdapterFactory 代付适配器工厂（测试可注入假适配器）；null=按配置解析代付通道
     * @param RateResolver|null $rateResolver 代付费率解析器（测试可注入）
     */
    public function __construct(
        ?LedgerService $ledger = null,
        ?Closure $transferAdapterFactory = null,
        ?RateResolver $rateResolver = null,
    ) {
        $this->model = new Withdraw();
        $this->ledger = $ledger ?? new LedgerService();
        $this->transferAdapterFactory = $transferAdapterFactory;
        $this->rateResolver = $rateResolver;
    }

    /**
     * 商户申请提现（供商户门户 /mapi 调用，Phase 5；此处为核心逻辑）
     *
     * @param array $merchant 商户上下文（id/mch_id/rate_transfer；算费优先商户×通道链，无通道时回落商户全局代付费率）
     * @param array $params { amount(元), bank_card_id }
     * @return array 提现单摘要
     * @throws PaymentException 参数非法 / 余额不足 / 银行卡非法
     */
    public function apply(array $merchant, array $params): array
    {
        $merchantId = (int) ($merchant['id'] ?? 0);
        $mchId = (string) ($merchant['mch_id'] ?? '');
        $amount = AmountHelper::format((string) ($params['amount'] ?? '0'));
        $bankCardId = (int) ($params['bank_card_id'] ?? 0);

        if (!AmountHelper::gtZero($amount)) {
            throw new PaymentException('提现金额必须大于 0');
        }
        // 校验银行卡属于本商户（防越权提现到他人卡）
        $bankCard = $this->loadBankCard($bankCardId);
        if ($bankCard === null || (int) $bankCard['merchant_id'] !== $merchantId) {
            throw new PaymentException('收款银行卡非法');
        }
        if ((int) ($bankCard['status'] ?? BankCard::STATUS_NORMAL) !== BankCard::STATUS_NORMAL) {
            throw new PaymentException('收款银行卡已停用');
        }

        // 手续费：有代付通道时走商户×通道费率链；无通道或链均为 0 时回落 merchant.rate_transfer（全局保底）
        $merchantGlobalRate = AmountHelper::format((string) ($merchant['rate_transfer'] ?? '0'));
        $defaultChannelId = $this->resolveDefaultTransferChannelId($merchantId);
        $rate = $this->getRateResolver()->resolveTransferRateForApply(
            $merchantId,
            $defaultChannelId,
            $merchantGlobalRate
        );
        $fee = AmountHelper::fee($amount, $rate);
        $realAmount = AmountHelper::sub($amount, $fee);
        $withdrawNo = (new OrderNoGenerator())->withdraw();

        // 申请时固化银行卡信息，后续改卡/解绑不影响本单展示与代付收款账户
        $bankSnapshot = $this->buildBankCardSnapshot($bankCard);

        try {
            return $this->transaction(function () use (
                $merchantId,
                $mchId,
                $withdrawNo,
                $amount,
                $fee,
                $realAmount,
                $bankCardId,
                $bankSnapshot
            ) {
                // 1) 冻结可用余额（余额不足在此抛异常，整体回滚）
                $this->ledger->freezeWithdraw($merchantId, $mchId, $withdrawNo, $amount);
                // 2) 建提现单（待审核 + 银行卡快照）
                $this->createWithdraw(array_merge([
                    'withdraw_no'  => $withdrawNo,
                    'merchant_id'  => $merchantId,
                    'mch_id'       => $mchId,
                    'bank_card_id' => $bankCardId,
                    'amount'       => $amount,
                    'fee'          => $fee,
                    'real_amount'  => $realAmount,
                    'status'       => Withdraw::STATUS_PENDING,
                ], $bankSnapshot));
                return [
                    'withdraw_no' => $withdrawNo,
                    'amount'      => $amount,
                    'fee'         => $fee,
                    'real_amount' => $realAmount,
                    'status'      => Withdraw::STATUS_PENDING,
                ];
            });
        } catch (PaymentException $e) {
            throw $e;
        } catch (Throwable $e) {
            // 余额不足等资金异常统一转业务异常
            throw new PaymentException($e->getMessage());
        }
    }

    /**
     * 商户服务端 API 代付下单（供 /pay/transfer 网关调用，验签后进入）
     *
     * 与人工提现 {@see self::apply()} 复用同一套资金状态机与代付下发链路，差异：
     *  - 携带商户代付单号 out_biz_no（幂等键，同商户内唯一；重复请求返回既有单据状态，不重复出款）；
     *  - 落库 source=API代付、notify_url（出款成功/失败异步回调下游）；
     *  - 按金额阈值（config transfer_api.auto_threshold）决定自动放款或转后台人工审核。
     *
     * @param array $merchant 商户上下文（id/mch_id/rate_transfer）
     * @param array $params { out_biz_no, money(分), bank_card_id, notify_url? }
     * @return array 代付单摘要（含 status/status_text，供网关回包下游）
     * @throws PaymentException 参数非法 / 余额不足 / 银行卡非法
     */
    public function createByApi(array $merchant, array $params): array
    {
        $merchantId = (int) ($merchant['id'] ?? 0);
        $mchId = (string) ($merchant['mch_id'] ?? '');
        $outBizNo = trim((string) ($params['out_biz_no'] ?? ''));
        $moneyCents = trim((string) ($params['money'] ?? ''));
        $bankCardId = (int) ($params['bank_card_id'] ?? 0);
        $notifyUrl = trim((string) ($params['notify_url'] ?? ''));

        if ($outBizNo === '') {
            throw new PaymentException('缺少商户代付单号 out_biz_no');
        }
        // 金额与代收下单对齐：money 单位为分，须正整数
        if ($moneyCents === '' || !ctype_digit($moneyCents) || $moneyCents === '0') {
            throw new PaymentException('代付金额 money 非法（应为正整数，单位分）');
        }
        $amount = AmountHelper::div($moneyCents, '100');

        // 幂等：同商户 + out_biz_no 已存在 → 返回既有单据状态，不重复出款
        $existing = $this->loadWithdrawByOutBizNo($merchantId, $outBizNo);
        if ($existing !== null) {
            return $this->buildApiResult($existing);
        }

        // 收款银行卡校验（属本商户 + 启用）
        $bankCard = $this->loadBankCard($bankCardId);
        if ($bankCard === null || (int) $bankCard['merchant_id'] !== $merchantId) {
            throw new PaymentException('收款银行卡非法');
        }
        if ((int) ($bankCard['status'] ?? BankCard::STATUS_NORMAL) !== BankCard::STATUS_NORMAL) {
            throw new PaymentException('收款银行卡已停用');
        }

        // 手续费：商户×通道费率链，回落 merchant.rate_transfer（与人工提现一致）
        $merchantGlobalRate = AmountHelper::format((string) ($merchant['rate_transfer'] ?? '0'));
        $defaultChannelId = $this->resolveDefaultTransferChannelId($merchantId);
        $rate = $this->getRateResolver()->resolveTransferRateForApply(
            $merchantId,
            $defaultChannelId,
            $merchantGlobalRate
        );
        $fee = AmountHelper::fee($amount, $rate);
        $realAmount = AmountHelper::sub($amount, $fee);
        if (!AmountHelper::gtZero($realAmount)) {
            throw new PaymentException('代付金额不足以扣除手续费');
        }
        $withdrawNo = (new OrderNoGenerator())->withdraw();
        $bankSnapshot = $this->buildBankCardSnapshot($bankCard);

        try {
            $withdrawId = $this->transaction(function () use (
                $merchantId,
                $mchId,
                $withdrawNo,
                $outBizNo,
                $notifyUrl,
                $amount,
                $fee,
                $realAmount,
                $bankCardId,
                $bankSnapshot
            ) {
                // 1) 冻结可用余额（余额不足在此抛异常，整体回滚）
                $this->ledger->freezeWithdraw($merchantId, $mchId, $withdrawNo, $amount);
                // 2) 建代付单（待审核 + 银行卡快照 + API 来源 + 下游回调地址）
                return $this->createWithdraw(array_merge([
                    'withdraw_no'  => $withdrawNo,
                    'out_biz_no'   => $outBizNo,
                    'merchant_id'  => $merchantId,
                    'mch_id'       => $mchId,
                    'source'       => Withdraw::SOURCE_API_TRANSFER,
                    'bank_card_id' => $bankCardId,
                    'notify_url'   => $notifyUrl,
                    'amount'       => $amount,
                    'fee'          => $fee,
                    'real_amount'  => $realAmount,
                    'status'       => Withdraw::STATUS_PENDING,
                ], $bankSnapshot));
            });
        } catch (PaymentException $e) {
            throw $e;
        } catch (Throwable $e) {
            // 余额不足等资金异常统一转业务异常
            throw new PaymentException($e->getMessage());
        }

        // 阈值判定：<=阈值自动放款（置审核通过→立即下发）；>阈值留「待审核」转后台人工
        if ($this->shouldAutoDisburse($amount)) {
            $this->updateWithdraw($withdrawId, ['status' => Withdraw::STATUS_APPROVED]);
            $withdraw = $this->loadWithdraw($withdrawId);
            if ($withdraw !== null) {
                // 下发受理失败会同步走 confirmFailed（退款+失败回调），成功则保持代付中等回调
                $this->disburse($withdraw, 0);
            }
        }

        $final = $this->loadWithdraw($withdrawId);
        return $this->buildApiResult($final ?? []);
    }

    /**
     * 商户服务端 API 代付查单（供 /pay/transferQuery 网关调用）
     *
     * 仅能查询本商户名下代付单（merchant_id + out_biz_no 强约束）。
     *
     * @param array $merchant 商户上下文（id）
     * @param array $params { out_biz_no }
     * @return array 代付单摘要（含 status/status_text）
     * @throws PaymentException 参数非法 / 单不存在
     */
    public function queryByApi(array $merchant, array $params): array
    {
        $merchantId = (int) ($merchant['id'] ?? 0);
        $outBizNo = trim((string) ($params['out_biz_no'] ?? ''));
        if ($outBizNo === '') {
            throw new PaymentException('缺少商户代付单号 out_biz_no');
        }

        $withdraw = $this->loadWithdrawByOutBizNo($merchantId, $outBizNo);
        if ($withdraw === null) {
            throw new PaymentException('代付单不存在');
        }
        return $this->buildApiResult($withdraw);
    }

    /**
     * 可选代付通道（审核「代付下发」时下拉，仅列该商户已授权代付且适配器可用的通道）
     *
     * 商户无任何 transfer_enabled 绑定时，回退展示配置 transfer_channel_code 对应通道（系统兜底，勿依赖）。
     * Phase 9.5.4：仅 channel_biz IN (2,3) 且 transfer_adapter 已配置的可进列表。
     *
     * @param int $merchantId 提现单所属商户 ID
     * @return array<int,array{id:int,title:string,code:string}>
     */
    public function transferChannelOptions(int $merchantId = 0): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        $list = [];
        $authorizedIds = $this->loadAuthorizedTransferChannelIds($merchantId);

        if ($authorizedIds !== []) {
            foreach ($this->loadEnabledChannelsOrdered($authorizedIds) as $channel) {
                if (!$this->isTransferChannelReady($channel)) {
                    continue;
                }
                $list[] = [
                    'id'    => (int) ($channel['id'] ?? 0),
                    'title' => (string) ($channel['title'] ?? ''),
                    'code'  => (string) ($channel['code'] ?? ''),
                ];
            }
            return $list;
        }

        $fallback = $this->loadTransferChannelByConfig();
        if ($fallback !== null && $this->isTransferChannelReady($fallback)) {
            $list[] = [
                'id'    => (int) ($fallback['id'] ?? 0),
                'title' => (string) ($fallback['title'] ?? ''),
                'code'  => (string) ($fallback['code'] ?? ''),
            ];
        }

        return $list;
    }

    /**
     * 商户门户手动重推「API 代付」结果通知到下游（仅本商户、仅 source=API代付）
     *
     * 适用场景：下游因网络等原因漏收平台代付结果回调，商户在门户「代付订单」页手动重推。
     * 仅终态（成功/代付失败）可重推；优先「原样重放」已存在的通知日志（含原签名体），
     * 无历史日志则按当前终态重新首发，全部委托 {@see MerchantNotifyService}。
     *
     * @param int|string $id 代付单ID
     * @param int $merchantId 当前登录商户ID（取自 token，防越权）
     * @return array{success:bool, message:string}
     * @throws PaymentException 单不存在 / 非终态 / 无通知地址
     */
    public function renotifyByMerchant(int|string $id, int $merchantId): array
    {
        $withdraw = Withdraw::where('id', $id)
            ->where('merchant_id', $merchantId)
            ->where('source', Withdraw::SOURCE_API_TRANSFER)
            ->find();
        if (!$withdraw) {
            throw new PaymentException('代付单不存在');
        }
        $data = $withdraw->toArray();

        if (trim((string) ($data['notify_url'] ?? '')) === '') {
            throw new PaymentException('该代付单未设置下游通知地址，无需通知');
        }

        $status = (int) ($data['status'] ?? 0);
        if (!in_array($status, [Withdraw::STATUS_SUCCESS, Withdraw::STATUS_PAY_FAILED], true)) {
            throw new PaymentException('代付处理中，暂无可推送的结果通知');
        }

        $service = new MerchantNotifyService();

        // 优先原样重放最近一条代付通知日志（保持与首发一致的签名体）
        $log = NotifyLog::where('order_no', (string) ($data['withdraw_no'] ?? ''))
            ->where('biz_type', NotifyLog::BIZ_TRANSFER)
            ->order('id', 'desc')
            ->find();
        if ($log) {
            return $service->resendManual((int) $log->id);
        }

        // 无历史通知日志：按当前终态重新首发
        $success = $status === Withdraw::STATUS_SUCCESS;
        $ok = $service->dispatchTransfer($data, $success, $success ? '' : (string) ($data['audit_remark'] ?? ''));
        return [
            'success' => $ok,
            'message' => $ok ? '通知已重新投递，商户已回应 SUCCESS' : '通知投递失败，将按退避策略继续自动重试',
        ];
    }

    /**
     * 平台审核提现（后台）
     *
     * action 取值：
     *  - pass     ：常规通过（财务线下已打款，扣减冻结余额，置成功终态）
     *  - disburse ：代付下发（调代付适配器，终态由回调决定）
     *  - reject   ：拒绝（解冻退款）
     *
     * @param int|string $withdrawId 提现单ID
     * @param string $action 审核动作 pass|disburse|reject
     * @param int $auditBy 审核人ID
     * @param string $remark 审核备注
     * @param int $channelId 代付通道ID（action=disburse 时必传或依赖默认配置）
     * @return array { withdraw_no, result, status }
     * @throws PaymentException 单不存在 / 状态非待审核 / 参数非法
     */
    public function audit(int|string $withdrawId, string $action, int $auditBy, string $remark = '', int $channelId = 0): array
    {
        $withdraw = $this->loadWithdraw((int) $withdrawId);
        if ($withdraw === null) {
            throw new PaymentException('提现单不存在');
        }
        if ((int) $withdraw['status'] !== Withdraw::STATUS_PENDING) {
            throw new PaymentException('提现单状态不可审核');
        }

        $action = strtolower(trim($action));
        if (!in_array($action, ['pass', 'disburse', 'reject'], true)) {
            throw new PaymentException('无效的审核操作');
        }

        $auditFields = [
            'audit_by'     => $auditBy,
            'audit_time'   => date('Y-m-d H:i:s'),
            'audit_remark' => $remark,
        ];

        // 拒绝：退款解冻 + 置审核拒绝
        if ($action === 'reject') {
            $this->transaction(function () use ($withdraw, $auditFields) {
                $this->ledger->refundWithdraw(
                    (int) $withdraw['merchant_id'],
                    (string) $withdraw['mch_id'],
                    (string) $withdraw['withdraw_no'],
                    (string) $withdraw['amount']
                );
                $this->updateWithdraw((int) $withdraw['id'], array_merge($auditFields, [
                    'status' => Withdraw::STATUS_REJECTED,
                ]));
            });
            return ['withdraw_no' => $withdraw['withdraw_no'], 'result' => 'rejected', 'status' => Withdraw::STATUS_REJECTED];
        }

        // 常规通过：财务线下已打款 → 扣减冻结余额 + 置成功终态（不触发代付）
        if ($action === 'pass') {
            $this->transaction(function () use ($withdraw, $auditFields) {
                $this->ledger->deductWithdraw(
                    (int) $withdraw['merchant_id'],
                    (string) $withdraw['mch_id'],
                    (string) $withdraw['withdraw_no'],
                    (string) $withdraw['amount']
                );
                $this->updateWithdraw((int) $withdraw['id'], array_merge($auditFields, [
                    'status' => Withdraw::STATUS_SUCCESS,
                ]));
            });
            return [
                'withdraw_no' => $withdraw['withdraw_no'],
                'result'      => 'success',
                'status'      => Withdraw::STATUS_SUCCESS,
            ];
        }

        // 代付下发：置审核通过 → 立即调代付适配器
        $this->updateWithdraw((int) $withdraw['id'], array_merge($auditFields, [
            'status' => Withdraw::STATUS_APPROVED,
        ]));
        $withdraw['status'] = Withdraw::STATUS_APPROVED;
        return $this->disburse($withdraw, $channelId);
    }

    /**
     * 对已审核通过的提现单发起代付下发（常规通过后二次操作）
     *
     * @param int|string $withdrawId 提现单ID
     * @param int $channelId 代付通道ID
     * @return array { withdraw_no, result, status }
     * @throws PaymentException
     */
    public function disburseById(int|string $withdrawId, int $channelId = 0): array
    {
        $withdraw = $this->loadWithdraw((int) $withdrawId);
        if ($withdraw === null) {
            throw new PaymentException('提现单不存在');
        }
        return $this->disburse($withdraw, $channelId);
    }

    /**
     * 下发代付（调代付适配器出款）
     *
     * @param array $withdraw 提现单数据（status 须为审核通过）
     * @param int $channelId 代付通道ID（0 则取商户默认授权通道或配置兜底）
     * @return array { withdraw_no, result, status }
     * @throws PaymentException 适配器未配置 / 状态非法 / 银行卡缺失
     */
    public function disburse(array $withdraw, int $channelId = 0): array
    {
        if ((int) $withdraw['status'] !== Withdraw::STATUS_APPROVED) {
            throw new PaymentException('提现单状态不可下发');
        }

        $bankAccount = $this->resolveWithdrawBankAccount($withdraw);
        if ($bankAccount === []) {
            throw new PaymentException('收款银行卡信息缺失');
        }

        $merchantId = (int) ($withdraw['merchant_id'] ?? 0);
        $channel = $this->resolveTransferChannel($merchantId, $channelId);
        if ($channel === null) {
            throw new PaymentException($channelId > 0 ? '代付通道未授权或不可用' : '请选择代付渠道');
        }

        // 先解析代付适配器（未配置直接报错，不改单状态）
        $adapter = $this->transferAdapterFactory !== null
            ? ($this->transferAdapterFactory)()
            : TransferAdapterFactory::makeFromChannel($channel);

        // 再置代付中（避免重复下发）
        $this->updateWithdraw((int) $withdraw['id'], ['status' => Withdraw::STATUS_PAYING]);

        $request = new CreateTransferRequest(
            transferNo: (string) $withdraw['withdraw_no'],
            amount: (string) $withdraw['real_amount'], // 到卡金额（毛额扣手续费）
            accountName: (string) ($bankAccount['account_name'] ?? ''),
            accountNo: (string) ($bankAccount['account_no'] ?? ''),
            bankName: (string) ($bankAccount['bank_name'] ?? ''),
            bankCode: (string) ($bankAccount['bank_code'] ?? ''),
            notifyUrl: $this->buildTransferNotifyUrl((string) $channel['code']),
        );

        try {
            $result = $adapter->createTransfer($request);
        } catch (Throwable $e) {
            // 受理调用异常：当作受理失败处理（退款解冻 + 代付失败）
            $this->confirmFailed((string) $withdraw['withdraw_no'], '代付受理异常:' . $e->getMessage());
            return ['withdraw_no' => $withdraw['withdraw_no'], 'result' => 'pay_failed', 'status' => Withdraw::STATUS_PAY_FAILED];
        }

        if (!$result->success) {
            // 上游拒单：退款解冻 + 代付失败
            $this->confirmFailed((string) $withdraw['withdraw_no'], '上游拒单:' . $result->message);
            return ['withdraw_no' => $withdraw['withdraw_no'], 'result' => 'pay_failed', 'status' => Withdraw::STATUS_PAY_FAILED];
        }

        // 受理成功：记录上游单号，保持代付中，等待回调
        $this->updateWithdraw((int) $withdraw['id'], ['transfer_no' => $result->upstreamNo]);
        return ['withdraw_no' => $withdraw['withdraw_no'], 'result' => 'paying', 'status' => Withdraw::STATUS_PAYING];
    }

    /**
     * 代付成功确认（代付回调成功时调用）
     *
     * @param string $withdrawNo 提现单号
     * @return bool true=本次完成确认；false=非代付中状态（幂等跳过）
     * @throws PaymentException 单不存在
     */
    public function confirmSuccess(string $withdrawNo): bool
    {
        $withdraw = $this->loadWithdrawByNo($withdrawNo);
        if ($withdraw === null) {
            throw new PaymentException('提现单不存在');
        }
        // 已成功的重复回调直接幂等返回
        if ((int) $withdraw['status'] === Withdraw::STATUS_SUCCESS) {
            return false;
        }
        if ((int) $withdraw['status'] !== Withdraw::STATUS_PAYING) {
            throw new PaymentException('提现单状态不可确认成功');
        }

        $this->transaction(function () use ($withdraw) {
            // 冻结余额扣减（钱真正出账）
            $this->ledger->deductWithdraw(
                (int) $withdraw['merchant_id'],
                (string) $withdraw['mch_id'],
                (string) $withdraw['withdraw_no'],
                (string) $withdraw['amount']
            );
            $this->updateWithdraw((int) $withdraw['id'], [
                'status'      => Withdraw::STATUS_SUCCESS,
            ]);
        });
        // API 代付：出款成功异步回调下游商户（人工提现单 source!=2 时跳过）
        $this->dispatchTransferNotify($withdraw, true);
        return true;
    }

    /**
     * 代付失败确认（审核拒绝/下发失败/回调失败时调用）：退款解冻 + 置代付失败
     *
     * @param string $withdrawNo 提现单号
     * @param string $reason 失败原因
     * @return bool true=本次完成退款；false=已终结状态（幂等跳过）
     * @throws PaymentException 单不存在
     */
    public function confirmFailed(string $withdrawNo, string $reason = ''): bool
    {
        $withdraw = $this->loadWithdrawByNo($withdrawNo);
        if ($withdraw === null) {
            throw new PaymentException('提现单不存在');
        }
        $status = (int) $withdraw['status'];
        // 仅「审核通过 / 代付中」可转失败退款；已成功/已失败幂等跳过
        if (!in_array($status, [Withdraw::STATUS_APPROVED, Withdraw::STATUS_PAYING], true)) {
            return false;
        }

        $this->transaction(function () use ($withdraw, $reason) {
            $this->ledger->refundWithdraw(
                (int) $withdraw['merchant_id'],
                (string) $withdraw['mch_id'],
                (string) $withdraw['withdraw_no'],
                (string) $withdraw['amount']
            );
            $this->updateWithdraw((int) $withdraw['id'], [
                'status'       => Withdraw::STATUS_PAY_FAILED,
                'audit_remark' => $reason !== '' ? $reason : (string) ($withdraw['audit_remark'] ?? ''),
            ]);
        });
        // API 代付：出款失败异步回调下游商户（人工提现单 source!=2 时跳过）
        $this->dispatchTransferNotify($withdraw, false, $reason);
        return true;
    }

    // ===== 接缝：DB 访问 / 适配器解析 / URL 拼接，默认实现，单测可重写 =====

    /**
     * 代付费率解析器（可注入）
     */
    protected function getRateResolver(): RateResolver
    {
        return $this->rateResolver ??= new RateResolver();
    }

    /**
     * 商户已授权代付的 channel_id 集合
     *
     * @return int[]
     */
    protected function loadAuthorizedTransferChannelIds(int $merchantId): array
    {
        return array_map('intval', MerchantChannel::where('merchant_id', $merchantId)
            ->where('transfer_enabled', MerchantChannel::TRANSFER_ENABLED)
            ->column('channel_id'));
    }

    /**
     * 解析商户默认代付通道 ID（授权列表 sort 倒序首条可用；无授权时回退配置）
     */
    protected function resolveDefaultTransferChannelId(int $merchantId): ?int
    {
        $authorizedIds = $this->loadAuthorizedTransferChannelIds($merchantId);
        if ($authorizedIds !== []) {
            foreach ($this->loadEnabledChannelsOrdered($authorizedIds) as $channel) {
                if ($this->isTransferChannelReady($channel)) {
                    return (int) ($channel['id'] ?? 0);
                }
            }
            return null;
        }

        $fallback = $this->loadTransferChannelByConfig();
        return $fallback !== null ? (int) ($fallback['id'] ?? 0) : null;
    }

    /**
     * 解析代付通道：须在商户授权集合内（有授权时）；无授权时回退配置 transfer_channel_code
     *
     * @param int $merchantId 商户 ID
     * @param int $channelId 指定通道；0=默认
     * @return array|null 通道数组（含 code/adapter/密钥等）
     */
    protected function resolveTransferChannel(int $merchantId, int $channelId = 0): ?array
    {
        $authorizedIds = $this->loadAuthorizedTransferChannelIds($merchantId);
        $targetId = $channelId > 0 ? $channelId : $this->resolveDefaultTransferChannelId($merchantId);

        if ($targetId === null || $targetId <= 0) {
            return null;
        }

        if ($authorizedIds !== [] && !in_array($targetId, $authorizedIds, true)) {
            return null;
        }

        $channel = $this->loadTransferChannelById($targetId);
        if ($channel === null || !$this->isTransferChannelReady($channel)) {
            return null;
        }

        return $channel;
    }

    /**
     * 按配置 transfer_channel_code 加载兜底代付通道
     */
    protected function loadTransferChannelByConfig(): ?array
    {
        $code = (string) config('plugin.paymentchannel.app.transfer_channel_code', '');
        if ($code === '') {
            return null;
        }

        return $this->loadTransferChannel($code);
    }

    /**
     * 按 ID 批量加载启用通道（sort 倒序）
     *
     * @param int[] $ids
     * @return array<int,array>
     */
    protected function loadEnabledChannelsOrdered(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Channel::where('status', 1)
            ->whereIn('id', $ids)
            ->whereIn('channel_biz', [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH])
            ->order('sort', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 通道是否可用于代付选路（Phase 9.5.4：只认 transfer_adapter，不回退 adapter）
     */
    protected function isTransferChannelReady(array $channel): bool
    {
        if (!$this->isTransferCapableBiz((int) ($channel['channel_biz'] ?? Channel::BIZ_NONE))) {
            return false;
        }

        $adapterCode = trim((string) ($channel['transfer_adapter'] ?? ''));

        return $adapterCode !== ''
            && TransferAdapterRegistry::exists($adapterCode)
            && TransferAdapterRegistry::resolveClass($adapterCode) !== null;
    }

    /**
     * 通道业务能力是否含代付（channel_biz IN 2,3）
     */
    protected function isTransferCapableBiz(int $channelBiz): bool
    {
        return in_array($channelBiz, [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH], true);
    }

    /**
     * 按主键加载代付通道
     *
     * @param int $id 通道ID
     * @return array|null
     */
    protected function loadTransferChannelById(int $id): ?array
    {
        $channel = Channel::where('id', $id)
            ->where('status', 1)
            ->whereIn('channel_biz', [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH])
            ->find();
        if (!$channel) {
            return null;
        }
        $row = $this->channelToTransferArray($channel);
        $row['title'] = (string) $channel->title;

        return $row;
    }

    /**
     * 按编码加载代付通道（含上游密钥），供适配器工厂构造；未找到/停用返回 null
     *
     * @param string $code 通道编码
     * @return array|null
     */
    protected function loadTransferChannel(string $code): ?array
    {
        $channel = Channel::where('code', $code)
            ->where('status', 1)
            ->whereIn('channel_biz', [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH])
            ->find();
        if (!$channel) {
            return null;
        }
        $row = $this->channelToTransferArray($channel);
        $row['title'] = (string) $channel->title;

        return $row;
    }

    /**
     * 通道模型转代付适配器所需数组
     *
     * @param Channel $channel 通道模型
     * @return array
     */
    protected function channelToTransferArray(Channel $channel): array
    {
        $raw = $channel->getData();
        return [
            'id'                   => (int) $channel->id,
            'code'                 => (string) $channel->code,
            'adapter'              => (string) $channel->adapter,
            'transfer_adapter'     => (string) ($raw['transfer_adapter'] ?? ''),
            'channel_biz'          => (int) ($raw['channel_biz'] ?? Channel::BIZ_NONE),
            'gateway_url'          => (string) $channel->gateway_url,
            'upstream_mch_id'      => (string) $channel->upstream_mch_id,
            'upstream_key'         => (string) $channel->upstream_key,
            'upstream_public_key'  => (string) $channel->upstream_public_key,
            'upstream_private_key' => (string) $channel->upstream_private_key,
        ];
    }

    /**
     * 拼接代付回调地址（上游回调指向平台）
     *
     * @param string $channelCode 通道编码（空则回退配置）
     * @return string
     */
    protected function buildTransferNotifyUrl(string $channelCode = ''): string
    {
        $domain = (string) config('plugin.paymentchannel.app.notify_domain', '');
        if ($channelCode === '') {
            $channelCode = (string) config('plugin.paymentchannel.app.transfer_channel_code', '');
        }
        if ($domain === '' || $channelCode === '') {
            return '';
        }
        return rtrim($domain, '/') . '/pay/transferNotify/' . $channelCode;
    }

    /**
     * 按ID加载提现单
     * @param int $id 提现单ID
     * @return array|null
     */
    protected function loadWithdraw(int $id): ?array
    {
        $w = Withdraw::where('id', $id)->find();
        return $w ? $w->toArray() : null;
    }

    /**
     * 按提现单号加载
     * @param string $withdrawNo 提现单号
     * @return array|null
     */
    protected function loadWithdrawByNo(string $withdrawNo): ?array
    {
        $w = Withdraw::where('withdraw_no', $withdrawNo)->find();
        return $w ? $w->toArray() : null;
    }

    /**
     * 从银行卡行构建提现单快照字段（申请时写入，与 sa_pay_transfer 收款字段命名对齐）
     *
     * @param array $bankCard sa_pay_bank_card 行
     * @return array{account_name:string,account_no:string,bank_name:string,bank_code:string,branch_name:string}
     */
    protected function buildBankCardSnapshot(array $bankCard): array
    {
        return [
            'account_name' => (string) ($bankCard['holder_name'] ?? ''),
            'account_no'   => (string) ($bankCard['card_no'] ?? ''),
            'bank_name'    => (string) ($bankCard['bank_name'] ?? ''),
            'bank_code'    => (string) ($bankCard['bank_code'] ?? ''),
            'branch_name'  => (string) ($bankCard['branch_name'] ?? ''),
        ];
    }

    /**
     * 解析提现单收款账户：仅读申请时落库快照，禁止通过 bank_card_id 回查实时卡信息
     *
     * @param array $withdraw 提现单
     * @return array{account_name:string,account_no:string,bank_name:string,bank_code:string,branch_name:string}
     */
    protected function resolveWithdrawBankAccount(array $withdraw): array
    {
        $accountNo = trim((string) ($withdraw['account_no'] ?? ''));
        if ($accountNo === '') {
            return [];
        }

        return [
            'account_name' => (string) ($withdraw['account_name'] ?? ''),
            'account_no'   => $accountNo,
            'bank_name'    => (string) ($withdraw['bank_name'] ?? ''),
            'bank_code'    => (string) ($withdraw['bank_code'] ?? ''),
            'branch_name'  => (string) ($withdraw['branch_name'] ?? ''),
        ];
    }

    /**
     * 加载银行卡
     * @param int $bankCardId 银行卡ID
     * @return array|null
     */
    protected function loadBankCard(int $bankCardId): ?array
    {
        $c = BankCard::where('id', $bankCardId)->find();
        return $c ? $c->toArray() : null;
    }

    /**
     * 创建提现单，返回ID
     * @param array $data 提现单数据
     * @return int
     */
    protected function createWithdraw(array $data): int
    {
        $w = Withdraw::create($data);
        return (int) $w->id;
    }

    /**
     * 更新提现单
     * @param int $id 提现单ID
     * @param array $patch 待更新字段
     */
    protected function updateWithdraw(int $id, array $patch): void
    {
        Withdraw::where('id', $id)->update($patch);
    }

    /**
     * 按商户 + 商户代付单号加载代付单（API 代付幂等键 / 查单）
     *
     * @param int $merchantId 商户ID
     * @param string $outBizNo 商户代付单号
     * @return array|null
     */
    protected function loadWithdrawByOutBizNo(int $merchantId, string $outBizNo): ?array
    {
        if ($merchantId <= 0 || $outBizNo === '') {
            return null;
        }
        $w = Withdraw::where('merchant_id', $merchantId)
            ->where('out_biz_no', $outBizNo)
            ->find();
        return $w ? $w->toArray() : null;
    }

    /**
     * 是否自动放款（API 代付阈值判定）
     *
     * 读配置 transfer_api.auto_threshold（元）：<=0 表示全部转后台人工审核；
     * 否则代付金额 <= 阈值自动放款，> 阈值留待人工。
     *
     * @param string $amount 代付金额（元）
     * @return bool true=自动放款
     */
    protected function shouldAutoDisburse(string $amount): bool
    {
        $threshold = AmountHelper::format((string) config('plugin.paymentchannel.app.transfer_api.auto_threshold', '0'));
        if (!AmountHelper::gtZero($threshold)) {
            return false;
        }
        // amount <= threshold → 自动放款
        return AmountHelper::compare($amount, $threshold) <= 0;
    }

    /**
     * 组装 API 代付单回包摘要（供 /pay/transfer、/pay/transferQuery 回下游）
     *
     * @param array $withdraw 代付单
     * @return array{withdraw_no:string,out_biz_no:string,amount:string,fee:string,real_amount:string,status:int,status_text:string,transfer_no:string}
     */
    protected function buildApiResult(array $withdraw): array
    {
        $status = (int) ($withdraw['status'] ?? Withdraw::STATUS_PENDING);
        return [
            'withdraw_no' => (string) ($withdraw['withdraw_no'] ?? ''),
            'out_biz_no'  => (string) ($withdraw['out_biz_no'] ?? ''),
            'amount'      => AmountHelper::format((string) ($withdraw['amount'] ?? '0')),
            'fee'         => AmountHelper::format((string) ($withdraw['fee'] ?? '0')),
            'real_amount' => AmountHelper::format((string) ($withdraw['real_amount'] ?? '0')),
            'status'      => $status,
            'status_text' => $this->apiStatusText($status),
            'transfer_no' => (string) ($withdraw['transfer_no'] ?? ''),
        ];
    }

    /**
     * 代付单状态码 → 下游可读文案
     *
     * @param int $status 状态码
     * @return string
     */
    protected function apiStatusText(int $status): string
    {
        return match ($status) {
            Withdraw::STATUS_PENDING    => 'pending',   // 待审核
            Withdraw::STATUS_APPROVED   => 'approved',  // 审核通过待下发
            Withdraw::STATUS_PAYING     => 'paying',    // 代付中
            Withdraw::STATUS_SUCCESS    => 'success',   // 成功
            Withdraw::STATUS_REJECTED   => 'rejected',  // 审核拒绝
            Withdraw::STATUS_PAY_FAILED => 'fail',      // 代付失败
            default                     => 'unknown',
        };
    }

    /**
     * API 代付结果异步回调下游（仅 source=API代付 触发；人工提现单跳过）
     *
     * 接缝方法：默认实例化 {@see MerchantNotifyService} 投递；单测可重写为假实现。
     *
     * @param array $withdraw 代付单
     * @param bool $success 是否成功
     * @param string $reason 失败原因
     */
    protected function dispatchTransferNotify(array $withdraw, bool $success, string $reason = ''): void
    {
        if ((int) ($withdraw['source'] ?? Withdraw::SOURCE_WITHDRAW) !== Withdraw::SOURCE_API_TRANSFER) {
            return;
        }
        if (trim((string) ($withdraw['notify_url'] ?? '')) === '') {
            return;
        }
        try {
            (new MerchantNotifyService())->dispatchTransfer($withdraw, $success, $reason);
        } catch (Throwable $e) {
            // 回调失败不影响出款主流程（已落 NotifyLog，后续可重试/人工重发）
        }
    }
}
