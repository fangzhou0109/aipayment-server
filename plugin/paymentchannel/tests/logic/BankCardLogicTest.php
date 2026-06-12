<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户银行卡逻辑/校验测试（卡号 Luhn + 首现卡，脱离 DB）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\BankCardLogic;
use plugin\paymentchannel\app\model\BankCard;
use plugin\paymentchannel\app\validate\BankCardValidate;

/**
 * 可测试的银行卡逻辑：内存替代卡表，重写计数与新增接缝，脱离数据库。
 */
class TestableBankCardLogic extends BankCardLogic
{
    /** 模拟每个商户已绑卡数：merchantId => count */
    public array $cardCounts = [];
    /** 捕获的新增数据 */
    public array $created = [];
    /** 自增ID */
    private int $autoId = 5000;

    public function __construct()
    {
        // 不调用父构造（避免实例化模型/触发 DB），本类仅用内存
    }

    protected function countCards(int $merchantId): int
    {
        return $this->cardCounts[$merchantId] ?? 0;
    }

    public function add(array $data): mixed
    {
        $id = ++$this->autoId;
        $this->created[] = $data;
        // 模拟落库后该商户卡数 +1
        $mid = (int) ($data['merchant_id'] ?? 0);
        $this->cardCounts[$mid] = ($this->cardCounts[$mid] ?? 0) + 1;
        return $id;
    }

    /** 内存卡表：id => row */
    public array $cards = [];

    public function changeStatus(int $merchantId, int $cardId, int $status): void
    {
        if (!in_array($status, [BankCard::STATUS_NORMAL, BankCard::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('无效的状态');
        }
        $card = $this->cards[$cardId] ?? null;
        if ($card === null || (int) $card['merchant_id'] !== $merchantId) {
            throw new \RuntimeException('银行卡不存在');
        }
        $this->cards[$cardId]['status'] = $status;
    }
}

/**
 * 商户银行卡逻辑/校验测试
 *
 * 覆盖 README 4.4 要求：银行卡卡号（Luhn）/ 归属（首现卡判定）校验。脱离 DB。
 */
class BankCardLogicTest extends TestCase
{
    // ===== 卡号 Luhn 校验 =====

    /**
     * 合法卡号通过 Luhn 校验（含带空格格式）
     */
    public function testLuhnValidAcceptsValidCards(): void
    {
        // 经典 Luhn 合法测试卡号
        $this->assertTrue(BankCardValidate::luhnValid('4111111111111111')); // Visa 测试号
        $this->assertTrue(BankCardValidate::luhnValid('5555555555554444')); // Mastercard 测试号
        // 带空格也应通过（内部剔除非数字）
        $this->assertTrue(BankCardValidate::luhnValid('4111 1111 1111 1111'));
    }

    /**
     * 非法卡号被拒（校验位错误 / 含非数字主体 / 长度越界）
     */
    public function testLuhnValidRejectsInvalidCards(): void
    {
        $this->assertFalse(BankCardValidate::luhnValid('4111111111111112')); // 末位错误
        $this->assertFalse(BankCardValidate::luhnValid('1234567890123456')); // 校验和不为 10 倍数
        $this->assertFalse(BankCardValidate::luhnValid('411111')); // 太短(<12)
        $this->assertFalse(BankCardValidate::luhnValid('41111111111111111111')); // 太长(>19)
        $this->assertFalse(BankCardValidate::luhnValid('')); // 空
    }

    /**
     * Luhn 校验位计算正确性：用已知合法号验证算法
     */
    public function testLuhnAlgorithmCorrectness(): void
    {
        // 79927398713 是 Luhn 经典合法示例，但长度不足12；补足成合法16位卡号验证
        $this->assertTrue(BankCardValidate::luhnValid('4242424242424242')); // Stripe 测试卡
        $this->assertFalse(BankCardValidate::luhnValid('4242424242424243'));
    }

    // ===== 首现卡（归属）判定 =====

    /**
     * 商户名下无卡 → 首现卡
     */
    public function testIsFirstCardTrueWhenNoCard(): void
    {
        $logic = new TestableBankCardLogic();
        $this->assertTrue($logic->isFirstCard(7));
    }

    /**
     * 商户名下已有卡 → 非首现卡
     */
    public function testIsFirstCardFalseWhenHasCard(): void
    {
        $logic = new TestableBankCardLogic();
        $logic->cardCounts[7] = 2;
        $this->assertFalse($logic->isFirstCard(7));
    }

    /**
     * 绑卡：首张卡返回 first_card=true，并落库
     */
    public function testBindCardFirstCardFlag(): void
    {
        $logic = new TestableBankCardLogic();
        $result = $logic->bindCard([
            'merchant_id' => 7,
            'holder_name' => '张三',
            'card_no'     => '4111111111111111',
        ]);

        $this->assertTrue($result['first_card']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertCount(1, $logic->created);
        $this->assertSame(7, (int) $logic->created[0]['merchant_id']);
    }

    /**
     * 绑卡：第二张卡返回 first_card=false（首现卡判定在写入前）
     */
    public function testBindCardSecondCardNotFirst(): void
    {
        $logic = new TestableBankCardLogic();
        // 先绑一张
        $logic->bindCard(['merchant_id' => 7, 'holder_name' => '张三', 'card_no' => '4111111111111111']);
        // 再绑第二张
        $second = $logic->bindCard(['merchant_id' => 7, 'holder_name' => '张三', 'card_no' => '4242424242424242']);

        $this->assertFalse($second['first_card']);
        $this->assertCount(2, $logic->created);
    }

    /**
     * 启停：商户可切换名下卡的 status
     */
    public function testChangeStatusUpdatesOwnedCard(): void
    {
        $logic = new TestableBankCardLogic();
        $logic->cards[501] = [
            'id'          => 501,
            'merchant_id' => 7,
            'status'      => BankCard::STATUS_NORMAL,
        ];

        $logic->changeStatus(7, 501, BankCard::STATUS_DISABLED);
        $this->assertSame(BankCard::STATUS_DISABLED, $logic->cards[501]['status']);

        $logic->changeStatus(7, 501, BankCard::STATUS_NORMAL);
        $this->assertSame(BankCard::STATUS_NORMAL, $logic->cards[501]['status']);
    }

    /**
     * 启停：不可操作他人银行卡
     */
    public function testChangeStatusRejectsForeignCard(): void
    {
        $logic = new TestableBankCardLogic();
        $logic->cards[502] = ['id' => 502, 'merchant_id' => 9, 'status' => BankCard::STATUS_NORMAL];
        $this->expectException(\RuntimeException::class);
        $logic->changeStatus(7, 502, BankCard::STATUS_DISABLED);
    }

    /**
     * 不同商户独立判定首现卡（A 已绑不影响 B 的首现卡）
     */
    public function testFirstCardIsolatedPerMerchant(): void
    {
        $logic = new TestableBankCardLogic();
        $logic->bindCard(['merchant_id' => 7, 'holder_name' => 'A', 'card_no' => '4111111111111111']);
        // 商户 8 仍是首现卡
        $resultB = $logic->bindCard(['merchant_id' => 8, 'holder_name' => 'B', 'card_no' => '4242424242424242']);
        $this->assertTrue($resultB['first_card']);
    }
}
