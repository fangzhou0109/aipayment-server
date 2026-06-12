<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：OrderNoGenerator 单元测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\OrderNoGenerator;

/**
 * OrderNoGenerator 单号生成器测试
 *
 * 通过注入「内存序列提供者」替代 Redis，纯单元测试不依赖外部服务：
 *  - 校验单号格式（长度 24、前缀、纯数字主体）；
 *  - 校验各业务类型前缀；
 *  - 校验同毫秒内大量生成不重复（模拟并发自增）；
 *  - 校验非法前缀抛异常、机器位边界。
 */
class OrderNoGeneratorTest extends TestCase
{
    /**
     * 构造一个带内存自增序列的生成器（每个 bucketKey 独立计数，模拟 Redis INCR）
     *
     * @param int $machineId 机器位
     * @return OrderNoGenerator
     */
    private function makeGenerator(int $machineId = 7): OrderNoGenerator
    {
        $counters = [];
        $provider = function (string $bucketKey) use (&$counters): int {
            // 模拟 Redis INCR：同一 key 自增
            $counters[$bucketKey] = ($counters[$bucketKey] ?? 0) + 1;
            return $counters[$bucketKey];
        };
        return new OrderNoGenerator($machineId, $provider);
    }

    /**
     * 单号格式：定长 24 位，首位为前缀字母，其余为数字
     */
    public function testFormat(): void
    {
        $no = $this->makeGenerator()->pay();
        $this->assertSame(24, strlen($no), '单号应为定长 24 位');
        $this->assertSame('P', $no[0], '首位应为业务前缀');
        $this->assertMatchesRegularExpression('/^P\d{23}$/', $no, '前缀后应为 23 位数字');
    }

    /**
     * 各业务类型前缀正确
     */
    public function testTypePrefixes(): void
    {
        $g = $this->makeGenerator();
        $this->assertStringStartsWith('P', $g->pay());
        $this->assertStringStartsWith('T', $g->transfer());
        $this->assertStringStartsWith('W', $g->withdraw());
        $this->assertStringStartsWith('R', $g->recharge());
    }

    /**
     * 机器位正确嵌入（第 18-19 位为机器位，补零 2 位）
     * 结构：前缀(1) + 时间(17) => 机器位起始下标 18
     */
    public function testMachineIdEmbedded(): void
    {
        $no = $this->makeGenerator(7)->pay();
        $machinePart = substr($no, 18, 2);
        $this->assertSame('07', $machinePart, '机器位应补零为 2 位');
    }

    /**
     * 同一生成器大量生成不重复（同毫秒走自增序列，跨毫秒走时间差）
     */
    public function testUniquenessBulk(): void
    {
        $g = $this->makeGenerator();
        $set = [];
        for ($i = 0; $i < 5000; $i++) {
            $set[$g->pay()] = true;
        }
        $this->assertCount(5000, $set, '5000 个单号应全部唯一');
    }

    /**
     * 非法前缀抛异常
     */
    public function testInvalidTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeGenerator()->generate('X');
    }

    /**
     * 机器位超出 0-99 时取模收敛为 2 位
     */
    public function testMachineIdOverflowWraps(): void
    {
        $no = $this->makeGenerator(123)->pay(); // 123 % 100 = 23
        $this->assertSame('23', substr($no, 18, 2));
    }

    /**
     * 序列循环：注入恒定大数，验证序列段被取模到 4 位且补零正确
     */
    public function testSequenceModuloWraps(): void
    {
        // 序列提供者恒返回 10001 -> %10000 = 1 -> 补零 '0001'
        $g = new OrderNoGenerator(0, fn (string $k): int => 10001);
        $no = $g->pay();
        $this->assertSame('0001', substr($no, -4), '序列应取模 4 位并补零');
    }
}
