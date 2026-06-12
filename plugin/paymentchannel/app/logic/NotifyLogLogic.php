<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户通知日志逻辑层（平台后台）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\NotifyLog;
use plugin\paymentchannel\service\MerchantNotifyService;
use plugin\saiadmin\exception\ApiException;

/**
 * 商户通知日志逻辑层（平台后台 /core/pay/notify）
 *
 * 只读列表/详情 + 人工重发；日志由 {@see MerchantNotifyService} 写入，后台不提供增删改。
 */
class NotifyLogLogic extends PaymentBaseLogic
{
    /** 通知状态 → 中文 */
    public const STATUS_TEXT = [
        NotifyLog::STATUS_PENDING => '待通知',
        NotifyLog::STATUS_SUCCESS => '成功',
        NotifyLog::STATUS_FAILED  => '失败',
    ];

    /** 通知类型 → 中文 */
    public const BIZ_TYPE_TEXT = [
        NotifyLog::BIZ_PAY       => '代收',
        NotifyLog::BIZ_TRANSFER  => '代付',
    ];

    /**
     * @param MerchantNotifyService|null $notifyService 通知服务（测试可注入）
     */
    public function __construct(private ?MerchantNotifyService $notifyService = null)
    {
        $this->model = new NotifyLog();
        $this->notifyService = $notifyService ?? new MerchantNotifyService();
    }

    /**
     * 列表搜索：支持 mch_id 模糊筛商户，预加载商户号；列表不查大字段 request/response
     *
     * @param array $searchWhere
     * @return mixed
     */
    public function search(array $searchWhere = []): mixed
    {
        $mchId = trim((string) ($searchWhere['mch_id'] ?? ''));
        unset($searchWhere['mch_id']);

        $query = parent::search($searchWhere)
            ->field('id,order_no,merchant_id,biz_type,notify_url,http_code,retry_num,status,next_notify_time,create_time,update_time')
            ->with([
                'merchant' => function ($q) {
                    $q->field('id,mch_id,name');
                },
            ]);

        if ($mchId !== '') {
            $merchantIds = Merchant::where('mch_id', 'like', '%' . $mchId . '%')->column('id');
            $query->whereIn('merchant_id', $merchantIds ?: [0]);
        }

        return $query;
    }

    /**
     * 分页列表：扁平化商户号，剔除关联对象
     *
     * @param mixed $query
     * @return mixed
     */
    public function getList($query): mixed
    {
        $result = parent::getList($query);
        if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
            $result['data'] = array_map([$this, 'formatListRow'], $result['data']);
        }

        return $result;
    }

    /**
     * 详情：含完整 request/response 原文
     *
     * @param mixed $id
     * @return array
     * @throws ApiException
     */
    public function read($id): mixed
    {
        $model = NotifyLog::with([
            'merchant' => function ($q) {
                $q->field('id,mch_id,name');
            },
        ])->findOrEmpty($id);

        if ($model->isEmpty()) {
            throw new ApiException('数据不存在');
        }

        return $this->formatListRow($model->toArray(), true);
    }

    /**
     * 人工重发通知（原样重放已签名 request_body）
     *
     * @param int|string $id 日志ID
     * @return array{success:bool, message:string}
     * @throws PaymentException
     */
    public function resend(int|string $id): array
    {
        $logId = (int) $id;
        if ($logId <= 0) {
            throw new PaymentException('请选择通知日志');
        }

        return $this->notifyService->resendManual($logId);
    }

    /**
     * 格式化列表/详情行
     *
     * @param array $row 原始行
     * @param bool $withBody 详情模式保留 request/response
     * @return array
     */
    protected function formatListRow(array $row, bool $withBody = false): array
    {
        $merchant = $row['merchant'] ?? null;
        if (is_array($merchant)) {
            $row['mch_id'] = (string) ($merchant['mch_id'] ?? '');
            $row['merchant_name'] = (string) ($merchant['name'] ?? '');
        } else {
            $row['mch_id'] = '';
            $row['merchant_name'] = '';
        }
        unset($row['merchant']);

        if (!$withBody) {
            unset($row['request_body'], $row['response_body']);
        }

        return $row;
    }
}
