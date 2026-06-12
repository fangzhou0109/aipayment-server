<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：代付结果枚举
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\service\transfer\dto;

/**
 * 代付结果枚举
 *
 * 适配器解析上游代付回调 / 查单后，统一用本枚举表达「这笔代付当前的状态」，
 * 屏蔽各上游差异，让上层（代付回调处理、查单）只关心：处理中 / 成功 / 失败。
 *
 * 取值刻意与 sa_pay_transfer.status 对齐（0待处理/1处理中/2成功/3失败），便于回写代付单。
 */
enum TransferOutcome: int
{
    /** 待处理（尚未提交上游） */
    case Pending = 0;
    /** 处理中（上游已受理，出款中） */
    case Processing = 1;
    /** 成功（已出款，可终结代付单） */
    case Success = 2;
    /** 失败（需退款解冻，见 Phase 4.2） */
    case Failed = 3;

    /**
     * 是否为「出款成功」终态
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this === self::Success;
    }

    /**
     * 是否为「出款失败」终态
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this === self::Failed;
    }
}
