<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户银行卡逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\BankCard;

/**
 * 商户银行卡逻辑层
 *
 * 复用 PaymentBaseLogic 的 search/getList/read/edit/destroy；新增「绑卡 + 首现卡风控提示」：
 *  - 绑卡前判定是否为该商户的「首张银行卡」（首现卡），随响应返回风控提示标记，
 *    供前端高亮——首现卡的提现需更严格人工复核（参考项目「首现卡提示」风控）。
 *
 * 可测试性：DB 计数抽为 protected 接缝 `countCards`，单测以子类重写脱离数据库。
 */
class BankCardLogic extends PaymentBaseLogic
{
    /**
     * 构造：注入银行卡模型
     */
    public function __construct()
    {
        $this->model = new BankCard();
    }

    /**
     * 绑定银行卡（含首现卡风控判定）
     *
     * 在写入前判定首现卡（此时该商户名下卡数为 0），再落库，返回新ID与首现卡标记。
     *
     * @param array $data 银行卡数据（含 merchant_id/holder_name/card_no...）
     * @return array{id:int, first_card:bool} 新卡ID与是否首现卡
     */
    public function bindCard(array $data): array
    {
        $merchantId = (int) ($data['merchant_id'] ?? 0);
        // 写入前判定：该商户当前无卡 → 本次为首现卡
        $firstCard = $this->isFirstCard($merchantId);
        $id = (int) $this->add($data);
        return ['id' => $id, 'first_card' => $firstCard];
    }

    /**
     * 是否为该商户的首张银行卡（首现卡）
     *
     * @param int $merchantId 商户ID
     * @return bool 名下无卡返回 true
     */
    public function isFirstCard(int $merchantId): bool
    {
        return $this->countCards($merchantId) === 0;
    }

    /**
     * 切换银行卡启用/停用（商户门户）
     *
     * @param int $merchantId 商户ID（防越权）
     * @param int $cardId 银行卡ID
     * @param int $status 目标状态：1正常 / 2停用
     * @return void
     * @throws \InvalidArgumentException 状态非法
     * @throws \RuntimeException 卡不存在或不属于该商户
     */
    public function changeStatus(int $merchantId, int $cardId, int $status): void
    {
        if (!in_array($status, [BankCard::STATUS_NORMAL, BankCard::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('无效的状态');
        }
        $updated = BankCard::where('id', $cardId)
            ->where('merchant_id', $merchantId)
            ->update(['status' => $status]);
        if ($updated === 0) {
            throw new \RuntimeException('银行卡不存在');
        }
    }

    // ===== 接缝：DB 访问，默认走 ThinkORM，单测可在子类重写以脱离数据库 =====

    /**
     * 统计该商户名下银行卡数量（含软删除外的有效卡）
     *
     * @param int $merchantId 商户ID
     * @return int
     */
    protected function countCards(int $merchantId): int
    {
        return (int) BankCard::where('merchant_id', $merchantId)->count();
    }
}
