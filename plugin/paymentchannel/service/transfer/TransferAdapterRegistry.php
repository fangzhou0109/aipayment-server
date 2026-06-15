<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：上游代付适配器注册中心
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer;

/**
 * 上游代付适配器注册中心
 *
 * 集中登记「已支持的代付适配器」，供两处使用：
 *  1) 后台代付通道管理：为 transfer_adapter 字段提供下拉选项（code + 名称）；
 *  2) 通道校验 / 工厂创建：解析代付适配器实现类。
 *
 * 设计为静态注册表（无状态、易单测）。新增代付上游 = 新增一个 Adapter 类并在此登记一行，
 * 核心业务零改动。
 */
class TransferAdapterRegistry
{
    /**
     * 已注册代付适配器表：code => [name, class]
     *
     * - code：适配器唯一标识，存入代付通道配置；
     * - name：后台下拉展示名；
     * - class：适配器实现类全名（null 表示尚未实现，工厂会拒绝创建）。
     *
     * @var array<string,array{name:string,class:?string}>
     */
    private const ADAPTERS = [
        // 联调用 Mock 代付适配器——始终可选，便于本地/灰度联调
        'mock_transfer' => [
            'name' => 'Mock 模拟代付',
            'class' => \plugin\paymentchannel\service\transfer\adapters\MockTransferAdapter::class,
        ],
        // 银行卡代付：通用协议样例，差异由通道凭证(extra/密钥/网关)承载
        'bank_transfer' => [
            'name' => '银行卡代付',
            'class' => \plugin\paymentchannel\service\transfer\adapters\ScanTransferAdapter::class,
        ],
        // KBZPay 代付：NPAY/易支付协议缅甸代付，走 api.php?act=payout（code=0 成功，金额 MMK 元）
        'kbzpay_transfer' => [
            'name' => 'KBZPay 代付（缅甸）',
            'class' => \plugin\paymentchannel\service\transfer\adapters\KbzPayTransferAdapter::class,
        ],
    ];

    /**
     * 获取全部代付适配器下拉选项（label/value 结构，适配前端 sa-select）
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
     * 判断代付适配器标识是否已注册
     *
     * @param string $code 适配器标识
     * @return bool
     */
    public static function exists(string $code): bool
    {
        return isset(self::ADAPTERS[$code]);
    }

    /**
     * 获取代付适配器实现类全名（未实现或未注册返回 null）
     *
     * @param string $code 适配器标识
     * @return string|null
     */
    public static function resolveClass(string $code): ?string
    {
        return self::ADAPTERS[$code]['class'] ?? null;
    }

    /**
     * 获取全部已注册代付适配器标识
     *
     * @return string[]
     */
    public static function codes(): array
    {
        return array_keys(self::ADAPTERS);
    }
}
