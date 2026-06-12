<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：PaymentBaseLogic 默认列表排序测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\logic;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\app\logic\OrderLogic;
use ReflectionClass;

/**
 * 列表默认按 create_time 倒序
 */
class PaymentBaseLogicTest extends TestCase
{
    public function testDefaultListOrderIsCreateTimeDesc(): void
    {
        $ref = new ReflectionClass(OrderLogic::class);
        $orderField = $ref->getProperty('orderField');
        $orderField->setAccessible(true);
        $orderType = $ref->getProperty('orderType');
        $orderType->setAccessible(true);

        $logic = new OrderLogic();
        $this->assertSame('create_time', $orderField->getValue($logic));
        $this->assertSame('DESC', $orderType->getValue($logic));
    }
}
