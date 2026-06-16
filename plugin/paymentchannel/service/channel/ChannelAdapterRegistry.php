<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游渠道适配器注册中心
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\channel;

/**
 * 上游渠道适配器注册中心
 *
 * 集中登记「已支持的上游适配器」，供两处使用：
 *  1) 后台通道管理：为 adapter 字段提供下拉选项（code + 名称）；
 *  2) 通道校验：保存通道时校验所选 adapter 是否合法。
 *
 * 设计为静态注册表（无状态、易单测）。Phase 3.1 已回填 `class` 指向具体实现类：
 * MockAdapter 为联调用内置适配器；支付宝/微信扫码复用通用 ScanPayAdapter（差异在通道凭证里配置）。
 * 新增上游 = 新增一个 Adapter 类并在此登记一行，核心业务零改动。
 */
class ChannelAdapterRegistry
{
    /**
     * 已注册适配器表：code => [name, class]
     *
     * - code：适配器唯一标识，存入 sa_pay_channel.adapter；
     * - name：后台下拉展示名；
     * - class：适配器实现类全名（null 表示尚未实现，工厂会拒绝创建）。
     *
     * @var array<string,array{name:string,class:?string}>
     */
    private const ADAPTERS = [
        // 联调用 Mock 适配器——始终可选，便于本地/灰度联调
        'mock' => ['name' => 'Mock 模拟通道', 'class' => \plugin\paymentchannel\service\channel\adapters\MockAdapter::class],
        // 支付宝/微信扫码：协议同属「通用扫码」，复用 ScanPayAdapter，差异由通道凭证(extra/密钥/网关)承载
        'alipay_scan' => ['name' => '支付宝扫码', 'class' => \plugin\paymentchannel\service\channel\adapters\ScanPayAdapter::class],
        'wechat_scan' => ['name' => '微信扫码', 'class' => \plugin\paymentchannel\service\channel\adapters\ScanPayAdapter::class],
        // LQPAY：另一套 SaiPayment 部署作为上游，走商户网关 /pay/submitOrder、/pay/query
        'lqpay' => ['name' => 'LQPAY（SaiPayment 同源）', 'class' => \plugin\paymentchannel\service\channel\adapters\LqpayAdapter::class],
        // NPay：彩虹易支付协议的缅甸聚合上游，走 mapi.php 下单、api.php 查单
        'npay' => ['name' => 'NPay（彩虹易支付/缅甸）', 'class' => \plugin\paymentchannel\service\channel\adapters\NpayAdapter::class],
        // KBZPay：NPAY 协议的缅甸 KBZPay 专用通道，固定 type=kbzpay、回调认 TRADE_SUCCESS、payurl 补全域名
        'kbzpay' => ['name' => 'KBZPay（缅甸）', 'class' => \plugin\paymentchannel\service\channel\adapters\KbzPayAdapter::class],
    ];

    /**
     * 获取全部适配器下拉选项（label/value 结构，适配前端 sa-select）
     *
     * @return array<int,array{label:string,value:string}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::ADAPTERS as $code => $meta) {
            $options[] = ['label' => $meta['name'], 'value' => $code];
        }
        return $options;
    }

    /**
     * 判断适配器标识是否已注册
     *
     * @param string $code 适配器标识
     * @return bool
     */
    public static function exists(string $code): bool
    {
        return isset(self::ADAPTERS[$code]);
    }

    /**
     * 获取适配器实现类全名（未实现或未注册返回 null）
     *
     * @param string $code 适配器标识
     * @return string|null
     */
    public static function resolveClass(string $code): ?string
    {
        return self::ADAPTERS[$code]['class'] ?? null;
    }

    /**
     * 获取全部已注册适配器标识
     *
     * @return string[]
     */
    public static function codes(): array
    {
        return array_keys(self::ADAPTERS);
    }
}
