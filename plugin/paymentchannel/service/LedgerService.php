<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：即时入账服务（记账）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service;

use plugin\paymentchannel\app\model\CapitalFlow;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\Order;
use RuntimeException;

/**
 * 即时入账服务（记账核心）
 *
 * 代收订单支付成功后，把「实际入账金额」(real_amount = 金额 - 手续费) 即时计入商户可用余额，
 * 并写一条不可变资金流水（含变动前后余额快照）。第一阶段简化为「即时入账」，
 * D0/D1 滚动结算延后到 Phase 6+。
 *
 * 资金安全（必须满足，且由调用方事务包裹）：
 *  - **同一事务**：余额更新、流水写入、订单入账标记三者原子，账实一致；
 *  - **行锁**：`lockMerchant` 用 FOR UPDATE 锁商户行，杜绝并发双花；
 *  - **幂等三防**：① 入账前查 `idempotent_key` 是否已存在（友好快路径）；
 *    ② `sa_pay_capital_flow.uk_idempotent_key` 唯一索引（DB 硬兜底，并发重复插入直接失败回滚）；
 *    ③ 上游回调侧 Redis 锁 + 订单「已支付」状态（见 NotifyGatewayLogic）。
 *  - 金额全程 {@see AmountHelper}（decimal + bcmath），禁止浮点。
 *
 * 不在此处开启事务：本服务由 {@see \plugin\paymentchannel\app\logic\NotifyGatewayLogic} 在其
 * 回调事务内调用，自带事务上下文；这里只做记账动作，保证与「改单」同一事务回滚边界。
 *
 * 可测试性：DB 访问全部抽为 protected 接缝方法，单测以子类重写脱离数据库。
 */
class LedgerService
{
    /** 幂等键前缀：代收入账（同一订单仅入账一次） */
    public const IDEMPOTENT_PREFIX_PAY_IN = 'pay_in:';

    /** 幂等键前缀：充值入账（同一充值单仅入账一次） */
    public const IDEMPOTENT_PREFIX_RECHARGE = 'recharge:';

    /**
     * 代收入账：可用余额 += real_amount，写流水，标记订单已入账
     *
     * @param array $order 订单数据（须含 id/merchant_id/mch_id/order_no/real_amount）
     * @return bool true=本次完成入账；false=已入账（幂等跳过）
     * @throws RuntimeException 商户不存在等异常（触发上层事务回滚）
     */
    public function credit(array $order): bool
    {
        $merchantId = (int) ($order['merchant_id'] ?? 0);
        $orderNo = (string) ($order['order_no'] ?? '');
        // 实际入账金额（元）：下单时已算好 real_amount = amount - fee
        $realAmount = AmountHelper::format((string) ($order['real_amount'] ?? '0'));
        $idempotentKey = self::IDEMPOTENT_PREFIX_PAY_IN . $orderNo;

        // 1) 幂等预检：该订单已入账则直接跳过（快路径；硬兜底是流水唯一索引）
        if ($this->flowExists($idempotentKey)) {
            return false;
        }

        // 2) 行锁读商户余额（FOR UPDATE），防并发双花
        $merchant = $this->lockMerchant($merchantId);
        if ($merchant === null) {
            throw new RuntimeException('入账失败：商户不存在 #' . $merchantId);
        }

        // 3) 计算变动前后余额（bcmath，禁浮点）
        $before = AmountHelper::format((string) ($merchant['balance'] ?? '0'));
        $after = AmountHelper::add($before, $realAmount);

        // 4) 更新商户可用余额
        $this->updateBalance($merchantId, $after);

        // 5) 写资金流水（idempotent_key 唯一，DB 兜底防重复记账）
        $this->insertFlow([
            'flow_no'        => $this->genFlowNo(),
            'merchant_id'    => $merchantId,
            'mch_id'         => (string) ($order['mch_id'] ?? ''),
            'biz_type'       => CapitalFlow::BIZ_PAY_IN,
            'biz_no'         => $orderNo,
            'change_type'    => CapitalFlow::ACCOUNT_BALANCE,
            'change_amount'  => $realAmount,
            'before_balance' => $before,
            'after_balance'  => $after,
            'idempotent_key' => $idempotentKey,
            'remark'         => '代收入账',
        ]);

        // 6) 标记订单已入账
        $this->markSettled((int) ($order['id'] ?? 0));

        return true;
    }

    // ===== 提现资金操作（冻结 / 扣减 / 退款），均由调用方事务包裹 =====
    // 采用「双账户记账」：可用余额(BALANCE) 与 冻结余额(FREEZE) 各记一条流水（含前后快照），
    // 满足「冻结/解冻/退款与流水一致」可审计要求。幂等键按 (操作, 账户) 维度唯一，重复调用安全。

    /**
     * 提现冻结：可用余额 → 冻结余额（申请提现时）
     *
     * 余额不足直接抛异常（触发上层事务回滚，拒绝提现申请）。
     *
     * @param int    $merchantId 商户ID
     * @param string $mchId      商户号（冗余入流水）
     * @param string $withdrawNo 提现单号（幂等键、流水 biz_no）
     * @param string $amount     冻结金额（元，提现毛额）
     * @throws RuntimeException 商户不存在 / 可用余额不足
     */
    public function freezeWithdraw(int $merchantId, string $mchId, string $withdrawNo, string $amount): void
    {
        $amount = AmountHelper::format($amount);
        // 幂等：该提现已冻结则跳过（快路径；硬兜底为流水唯一索引）
        if ($this->flowExists('wd_freeze_bal:' . $withdrawNo)) {
            return;
        }

        $merchant = $this->lockMerchant($merchantId);
        if ($merchant === null) {
            throw new RuntimeException('提现冻结失败：商户不存在 #' . $merchantId);
        }
        $balance = AmountHelper::format((string) ($merchant['balance'] ?? '0'));
        $freeze = AmountHelper::format((string) ($merchant['balance_freeze'] ?? '0'));

        // 可用余额必须足额（含手续费的毛额）
        if (AmountHelper::compare($balance, $amount) < 0) {
            throw new RuntimeException('提现失败：可用余额不足');
        }

        $newBalance = AmountHelper::sub($balance, $amount);
        $newFreeze = AmountHelper::add($freeze, $amount);
        $this->updateMerchantBalances($merchantId, $newBalance, $newFreeze);

        // 双账户流水：可用 -amount、冻结 +amount
        $this->insertFlow($this->buildFlow(
            $merchantId, $mchId, CapitalFlow::BIZ_WITHDRAW_FREEZE, $withdrawNo,
            CapitalFlow::ACCOUNT_BALANCE, '-' . $amount, $balance, $newBalance,
            'wd_freeze_bal:' . $withdrawNo, '提现冻结(可用)'
        ));
        $this->insertFlow($this->buildFlow(
            $merchantId, $mchId, CapitalFlow::BIZ_WITHDRAW_FREEZE, $withdrawNo,
            CapitalFlow::ACCOUNT_FREEZE, $amount, $freeze, $newFreeze,
            'wd_freeze_frz:' . $withdrawNo, '提现冻结(冻结)'
        ));
    }

    /**
     * 提现成功扣减：冻结余额减少（代付成功，钱已出账）
     *
     * @param int    $merchantId 商户ID
     * @param string $mchId      商户号
     * @param string $withdrawNo 提现单号
     * @param string $amount     扣减金额（元，提现毛额）
     * @throws RuntimeException 商户不存在
     */
    public function deductWithdraw(int $merchantId, string $mchId, string $withdrawNo, string $amount): void
    {
        $amount = AmountHelper::format($amount);
        if ($this->flowExists('wd_deduct_frz:' . $withdrawNo)) {
            return;
        }

        $merchant = $this->lockMerchant($merchantId);
        if ($merchant === null) {
            throw new RuntimeException('提现扣减失败：商户不存在 #' . $merchantId);
        }
        $freeze = AmountHelper::format((string) ($merchant['balance_freeze'] ?? '0'));
        $newFreeze = AmountHelper::sub($freeze, $amount);
        $this->updateMerchantBalances($merchantId, null, $newFreeze);

        // 冻结 -amount（钱真正离开账户，平台留手续费、银行卡到账 real_amount）
        $this->insertFlow($this->buildFlow(
            $merchantId, $mchId, CapitalFlow::BIZ_WITHDRAW_DEDUCT, $withdrawNo,
            CapitalFlow::ACCOUNT_FREEZE, '-' . $amount, $freeze, $newFreeze,
            'wd_deduct_frz:' . $withdrawNo, '提现成功扣减'
        ));
    }

    /**
     * 提现退款：冻结余额 → 可用余额（审核拒绝 / 代付失败时解冻退回）
     *
     * @param int    $merchantId 商户ID
     * @param string $mchId      商户号
     * @param string $withdrawNo 提现单号
     * @param string $amount     退款金额（元，提现毛额）
     * @throws RuntimeException 商户不存在
     */
    public function refundWithdraw(int $merchantId, string $mchId, string $withdrawNo, string $amount): void
    {
        $amount = AmountHelper::format($amount);
        if ($this->flowExists('wd_refund_bal:' . $withdrawNo)) {
            return;
        }

        $merchant = $this->lockMerchant($merchantId);
        if ($merchant === null) {
            throw new RuntimeException('提现退款失败：商户不存在 #' . $merchantId);
        }
        $balance = AmountHelper::format((string) ($merchant['balance'] ?? '0'));
        $freeze = AmountHelper::format((string) ($merchant['balance_freeze'] ?? '0'));
        $newFreeze = AmountHelper::sub($freeze, $amount);
        $newBalance = AmountHelper::add($balance, $amount);
        $this->updateMerchantBalances($merchantId, $newBalance, $newFreeze);

        // 双账户流水：冻结 -amount、可用 +amount（原路退回）
        $this->insertFlow($this->buildFlow(
            $merchantId, $mchId, CapitalFlow::BIZ_WITHDRAW_REFUND, $withdrawNo,
            CapitalFlow::ACCOUNT_FREEZE, '-' . $amount, $freeze, $newFreeze,
            'wd_refund_frz:' . $withdrawNo, '提现退款解冻(冻结)'
        ));
        $this->insertFlow($this->buildFlow(
            $merchantId, $mchId, CapitalFlow::BIZ_WITHDRAW_REFUND, $withdrawNo,
            CapitalFlow::ACCOUNT_BALANCE, $amount, $balance, $newBalance,
            'wd_refund_bal:' . $withdrawNo, '提现退款解冻(可用)'
        ));
    }

    // ===== 充值入账，由调用方事务包裹 =====

    /**
     * 充值入账：可用余额 += amount，写一条充值流水（审核通过时调用）
     *
     * 充值无手续费、无冻结：商户申请 → 平台审核通过即把金额计入可用余额。
     * 幂等键 `recharge:{rechargeNo}` 保证同一充值单仅入账一次（重复审核安全）。
     *
     * @param int    $merchantId 商户ID
     * @param string $mchId      商户号（冗余入流水）
     * @param string $rechargeNo 充值单号（幂等键、流水 biz_no）
     * @param string $amount     充值金额（元）
     * @throws RuntimeException 商户不存在
     */
    public function creditRecharge(int $merchantId, string $mchId, string $rechargeNo, string $amount): void
    {
        $amount = AmountHelper::format($amount);
        $key = self::IDEMPOTENT_PREFIX_RECHARGE . $rechargeNo;
        // 幂等：该充值单已入账则跳过（快路径；硬兜底为流水唯一索引）
        if ($this->flowExists($key)) {
            return;
        }

        $merchant = $this->lockMerchant($merchantId);
        if ($merchant === null) {
            throw new RuntimeException('充值入账失败：商户不存在 #' . $merchantId);
        }
        $before = AmountHelper::format((string) ($merchant['balance'] ?? '0'));
        $after = AmountHelper::add($before, $amount);
        $this->updateMerchantBalances($merchantId, $after, null);

        $this->insertFlow($this->buildFlow(
            $merchantId, $mchId, CapitalFlow::BIZ_RECHARGE, $rechargeNo,
            CapitalFlow::ACCOUNT_BALANCE, $amount, $before, $after,
            $key, '充值入账'
        ));
    }

    /**
     * 组装一条资金流水数据（统一填充流水号与公共字段）
     *
     * @param int    $merchantId    商户ID
     * @param string $mchId         商户号
     * @param int    $bizType       业务类型（CapitalFlow::BIZ_*）
     * @param string $bizNo         业务单号
     * @param int    $changeType    账户类型（CapitalFlow::ACCOUNT_*）
     * @param string $changeAmount  变动金额（元，带正负号）
     * @param string $before        变动前余额（元）
     * @param string $after         变动后余额（元）
     * @param string $idempotentKey 幂等键
     * @param string $remark        备注
     * @return array
     */
    private function buildFlow(
        int $merchantId, string $mchId, int $bizType, string $bizNo,
        int $changeType, string $changeAmount, string $before, string $after,
        string $idempotentKey, string $remark
    ): array {
        return [
            'flow_no'        => $this->genFlowNo(),
            'merchant_id'    => $merchantId,
            'mch_id'         => $mchId,
            'biz_type'       => $bizType,
            'biz_no'         => $bizNo,
            'change_type'    => $changeType,
            'change_amount'  => $changeAmount,
            'before_balance' => $before,
            'after_balance'  => $after,
            'idempotent_key' => $idempotentKey,
            'remark'         => $remark,
        ];
    }

    // ===== DB 访问接缝：默认走 ThinkORM，单测可在子类重写以脱离数据库 =====

    /**
     * 幂等键对应的流水是否已存在
     *
     * @param string $idempotentKey 幂等键
     * @return bool
     */
    protected function flowExists(string $idempotentKey): bool
    {
        return CapitalFlow::where('idempotent_key', $idempotentKey)->count() > 0;
    }

    /**
     * 行锁读取商户（FOR UPDATE），返回数组；不存在返回 null
     *
     * @param int $merchantId 商户ID
     * @return array|null
     */
    protected function lockMerchant(int $merchantId): ?array
    {
        // lock(true) 生成 SELECT ... FOR UPDATE，须在事务内调用方有效
        $merchant = Merchant::where('id', $merchantId)->lock(true)->find();
        return $merchant ? $merchant->toArray() : null;
    }

    /**
     * 更新商户可用余额
     *
     * @param int $merchantId 商户ID
     * @param string $newBalance 新余额（元）
     */
    protected function updateBalance(int $merchantId, string $newBalance): void
    {
        Merchant::where('id', $merchantId)->update(['balance' => $newBalance]);
    }

    /**
     * 更新商户可用余额 / 冻结余额（任一为 null 则不更新该字段）
     *
     * @param int         $merchantId 商户ID
     * @param string|null $balance    新可用余额（元），null 不改
     * @param string|null $freeze     新冻结余额（元），null 不改
     */
    protected function updateMerchantBalances(int $merchantId, ?string $balance, ?string $freeze): void
    {
        $patch = [];
        if ($balance !== null) {
            $patch['balance'] = $balance;
        }
        if ($freeze !== null) {
            $patch['balance_freeze'] = $freeze;
        }
        if ($patch !== []) {
            Merchant::where('id', $merchantId)->update($patch);
        }
    }

    /**
     * 写一条资金流水
     *
     * @param array $data 流水数据
     */
    protected function insertFlow(array $data): void
    {
        CapitalFlow::create($data);
    }

    /**
     * 标记订单已入账（settle_status = 已入账）
     *
     * @param int $orderId 订单ID
     */
    protected function markSettled(int $orderId): void
    {
        Order::where('id', $orderId)->update(['settle_status' => Order::SETTLE_DONE]);
    }

    /**
     * 生成资金流水号（F + 17 位毫秒时间 + 6 位随机）
     *
     * 流水号仅作展示/检索用，去重以 idempotent_key 为准，故用时间 + 随机即可。
     *
     * @return string
     */
    protected function genFlowNo(): string
    {
        $now = microtime(true);
        $ms = (int) (($now - (int) $now) * 1000);
        $time = date('YmdHis', (int) $now) . str_pad((string) $ms, 3, '0', STR_PAD_LEFT);
        return 'F' . $time . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
