<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：插件骨架冒烟测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use plugin\paymentchannel\app\controller\HealthController;
use plugin\saiadmin\basic\OpenController;

/**
 * 插件骨架冒烟测试
 *
 * 纯静态断言，不依赖数据库与 webman 常驻服务，可在本地直接 phpunit 运行：
 *   1. 插件命名空间下的类可通过 composer PSR-4 自动加载；
 *   2. 控制器正确继承 saiadmin 基类（验证跨插件类复用）。
 */
class SmokeTest extends TestCase
{
    /**
     * 插件控制器类应可被自动加载
     */
    public function testHealthControllerIsAutoloadable(): void
    {
        $this->assertTrue(
            class_exists(HealthController::class),
            'HealthController 应能通过 PSR-4 自动加载'
        );
    }

    /**
     * 插件控制器应继承 saiadmin 的 OpenController（跨插件基类复用）
     */
    public function testHealthControllerExtendsOpenController(): void
    {
        // 不调用构造函数，避免触发 init()，保持纯静态断言
        $instance = (new ReflectionClass(HealthController::class))->newInstanceWithoutConstructor();
        $this->assertInstanceOf(OpenController::class, $instance);
    }
}
