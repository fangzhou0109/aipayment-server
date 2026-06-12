<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户充值逻辑层（申请→平台审核→入账/驳回）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Recharge;
use plugin\paymentchannel\service\AmountHelper;
use plugin\paymentchannel\service\LedgerService;
use plugin\paymentchannel\service\OrderNoGenerator;

/**
 * 商户充值逻辑层
 *
 * 充值状态机（无手续费、无冻结，比提现简单）：
 *   申请 apply   ：商户提交充值申请（线下转账/转卡等），建单 status=待审核(0)，**不动余额**
 *   审核 audit   ：驳回→ status=驳回(-1) 不动余额；通过→ 事务内 `creditRecharge` 入账 + status=通过(1)
 *
 * 资金安全：
 *  - 仅「审核通过」才把金额计入可用余额，并写一条 `capital_flow`（biz=充值）；
 *  - 入账与改单**同一事务**，账实一致；幂等键 `recharge:{no}` 防重复审核重复入账；
 *  - 金额全程 bcmath（{@see AmountHelper}），禁浮点。
 *
 * 可测试性：`LedgerService` 可注入；DB 访问抽 protected 接缝，单测以子类重写脱离 DB。
 */
class RechargeLogic extends PaymentBaseLogic
{
    /**
     * 入账/资金服务
     * @var LedgerService
     */
    private LedgerService $ledger;

    /**
     * @param LedgerService|null $ledger 资金服务（测试可注入）
     */
    public function __construct(?LedgerService $ledger = null)
    {
        $this->model = new Recharge();
        $this->ledger = $ledger ?? new LedgerService();
    }

    /**
     * 商户申请充值（供商户门户 /mapi 调用，Phase 5；此处为核心逻辑）
     *
     * 仅建待审核单，不动余额——入账发生在审核通过时。
     *
     * @param array $merchant 商户上下文（id/mch_id）
     * @param array $params { amount(元), recharge_type(1余额/2转卡/3在线), remark }
     * @return array 充值单摘要
     * @throws PaymentException 金额非法 / 充值方式非法
     */
    public function apply(array $merchant, array $params): array
    {
        $merchantId = (int) ($merchant['id'] ?? 0);
        $mchId = (string) ($merchant['mch_id'] ?? '');
        $amount = AmountHelper::format((string) ($params['amount'] ?? '0'));
        $rechargeType = (int) ($params['recharge_type'] ?? Recharge::TYPE_BALANCE);
        $remark = (string) ($params['remark'] ?? '');

        if (!AmountHelper::gtZero($amount)) {
            throw new PaymentException('充值金额必须大于 0');
        }
        // 充值方式必须为合法枚举（1余额/2转卡/3在线）
        if (!in_array($rechargeType, [Recharge::TYPE_BALANCE, Recharge::TYPE_CARD, Recharge::TYPE_ONLINE], true)) {
            throw new PaymentException('充值方式非法');
        }

        $rechargeNo = (new OrderNoGenerator())->recharge();
        $this->createRecharge([
            'recharge_no'   => $rechargeNo,
            'merchant_id'   => $merchantId,
            'mch_id'        => $mchId,
            'amount'        => $amount,
            'recharge_type' => $rechargeType,
            'status'        => Recharge::STATUS_PENDING,
            'remark'        => $remark,
        ]);

        return [
            'recharge_no'   => $rechargeNo,
            'amount'        => $amount,
            'recharge_type' => $rechargeType,
            'status'        => Recharge::STATUS_PENDING,
        ];
    }

    /**
     * 平台审核充值（通过 / 驳回）
     *
     * @param int|string $rechargeId 充值单ID
     * @param bool $approve true 通过（入账）/ false 驳回
     * @param int $auditBy 审核人ID
     * @param string $remark 审核备注
     * @return array { recharge_no, result, status }
     * @throws PaymentException 单不存在 / 状态非待审核
     */
    public function audit(int|string $rechargeId, bool $approve, int $auditBy, string $remark = ''): array
    {
        $recharge = $this->loadRecharge((int) $rechargeId);
        if ($recharge === null) {
            throw new PaymentException('充值单不存在');
        }
        if ((int) $recharge['status'] !== Recharge::STATUS_PENDING) {
            throw new PaymentException('充值单状态不可审核');
        }

        $auditFields = [
            'audit_by'     => $auditBy,
            'audit_time'   => date('Y-m-d H:i:s'),
            'audit_remark' => $remark,
        ];

        // 驳回：不动余额，仅置驳回
        if (!$approve) {
            $this->updateRecharge((int) $recharge['id'], array_merge($auditFields, [
                'status' => Recharge::STATUS_REJECTED,
            ]));
            return ['recharge_no' => $recharge['recharge_no'], 'result' => 'rejected', 'status' => Recharge::STATUS_REJECTED];
        }

        // 通过：事务内入账 + 置通过（账实一致）
        $this->transaction(function () use ($recharge, $auditFields) {
            $this->ledger->creditRecharge(
                (int) $recharge['merchant_id'],
                (string) $recharge['mch_id'],
                (string) $recharge['recharge_no'],
                (string) $recharge['amount']
            );
            $this->updateRecharge((int) $recharge['id'], array_merge($auditFields, [
                'status' => Recharge::STATUS_APPROVED,
            ]));
        });

        return ['recharge_no' => $recharge['recharge_no'], 'result' => 'approved', 'status' => Recharge::STATUS_APPROVED];
    }

    // ===== 接缝：DB 访问，默认走 ThinkORM，单测可在子类重写以脱离数据库 =====

    /**
     * 按ID加载充值单
     * @param int $id 充值单ID
     * @return array|null
     */
    protected function loadRecharge(int $id): ?array
    {
        $r = Recharge::where('id', $id)->find();
        return $r ? $r->toArray() : null;
    }

    /**
     * 创建充值单，返回ID
     * @param array $data 充值单数据
     * @return int
     */
    protected function createRecharge(array $data): int
    {
        $r = Recharge::create($data);
        return (int) $r->id;
    }

    /**
     * 更新充值单
     * @param int $id 充值单ID
     * @param array $patch 待更新字段
     */
    protected function updateRecharge(int $id, array $patch): void
    {
        Recharge::where('id', $id)->update($patch);
    }
}
