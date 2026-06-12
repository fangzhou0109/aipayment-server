<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户-通道授权逻辑层
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\logic;

use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\validate\MerchantChannelValidate;
use plugin\paymentchannel\service\AmountHelper;
use plugin\saiadmin\exception\ApiException;

/**
 * 商户-通道授权逻辑层
 *
 * 维护「商户×通道」代收授权白名单与定制费率；支持按商户/通道维度列表与批量授权。
 */
class MerchantChannelLogic extends PaymentBaseLogic
{
    /**
     * 构造函数：注入商户-通道模型
     */
    public function __construct()
    {
        $this->model = new MerchantChannel();
    }

    /**
     * 按商户列出通道授权（含通道只读参考字段，供后台配置表格）
     *
     * @param int $merchantId 商户ID
     * @return array<int,array>
     */
    public function listByMerchant(int $merchantId): array
    {
        $bindings = $this->loadBindingsByMerchant($merchantId);
        $result = [];

        foreach ($bindings as $row) {
            $channelId = (int) ($row['channel_id'] ?? 0);
            $channel = $this->loadChannelForList($channelId);
            $result[] = $this->formatListRow($row, $channel);
        }

        return $result;
    }

    /**
     * 商户门户：已开启代收通道列表（只读、脱敏）
     *
     * 条件：绑定 status=已授权 且 通道具备代收能力且平台侧启用。
     * 不返回上游成本、适配器等运营敏感字段。
     *
     * @param int $merchantId 商户ID（须来自 token）
     * @return array<int,array>
     */
    public function listEnabledPayForMerchantPortal(int $merchantId): array
    {
        $result = [];

        foreach ($this->loadBindingsByMerchant($merchantId) as $row) {
            if ((int) ($row['status'] ?? 0) !== MerchantChannel::STATUS_NORMAL) {
                continue;
            }

            $channel = $this->loadChannelForList((int) ($row['channel_id'] ?? 0));
            if (!$this->isPayCapableChannel($channel)) {
                continue;
            }
            if ((int) ($channel['status'] ?? 0) !== 1) {
                continue;
            }

            $result[] = $this->formatMerchantPortalPayRow($row, $channel);
        }

        usort($result, static function (array $a, array $b): int {
            $payTypeCmp = ($a['pay_type'] ?? 0) <=> ($b['pay_type'] ?? 0);
            if ($payTypeCmp !== 0) {
                return $payTypeCmp;
            }

            return strcmp((string) ($a['money_rule'] ?? ''), (string) ($b['money_rule'] ?? ''));
        });

        return $result;
    }

    /**
     * 商户门户：已开启代付通道列表（只读、脱敏）
     *
     * 条件：绑定 transfer_enabled=已授权 且 通道具备代付能力且平台侧启用。
     *
     * @param int $merchantId 商户ID（须来自 token）
     * @return array<int,array>
     */
    public function listEnabledTransferForMerchantPortal(int $merchantId): array
    {
        $candidates = [];

        foreach ($this->loadBindingsByMerchant($merchantId) as $row) {
            if ((int) ($row['transfer_enabled'] ?? 0) !== MerchantChannel::TRANSFER_ENABLED) {
                continue;
            }

            $channel = $this->loadChannelForList((int) ($row['channel_id'] ?? 0));
            if (!$this->isTransferCapableChannel($channel)) {
                continue;
            }
            if ((int) ($channel['status'] ?? 0) !== 1) {
                continue;
            }

            $candidates[] = ['binding' => $row, 'channel' => $channel];
        }

        usort($candidates, static function (array $a, array $b): int {
            $sortCmp = ((int) ($b['channel']['sort'] ?? 0)) <=> ((int) ($a['channel']['sort'] ?? 0));
            if ($sortCmp !== 0) {
                return $sortCmp;
            }

            $bizCmp = ((int) ($a['channel']['channel_biz'] ?? 0)) <=> ((int) ($b['channel']['channel_biz'] ?? 0));
            if ($bizCmp !== 0) {
                return $bizCmp;
            }

            return strcmp(
                (string) ($a['channel']['money_rule'] ?? ''),
                (string) ($b['channel']['money_rule'] ?? '')
            );
        });

        $defaultBindingId = (int) ($candidates[0]['binding']['id'] ?? 0);
        $result = [];

        foreach ($candidates as $item) {
            $formatted = $this->formatMerchantPortalTransferRow($item['binding'], $item['channel']);
            $formatted['is_fee_default'] = (int) ($item['binding']['id'] ?? 0) === $defaultBindingId ? 1 : 0;
            $result[] = $formatted;
        }

        return $result;
    }

    /**
     * 按商户列出代付通道及绑定（仅 channel_biz IN 2,3，供「代付通道配置」表格）
     *
     * 以全部具备代付能力的通道为基准，叠加该商户 transfer_enabled / rate_transfer（无绑定则默认停用）。
     *
     * @param int $merchantId 商户ID
     * @return array<int,array>
     */
    public function listTransferByMerchant(int $merchantId): array
    {
        $bindingMap = [];
        foreach ($this->loadBindingsByMerchant($merchantId) as $row) {
            $bindingMap[(int) ($row['channel_id'] ?? 0)] = $row;
        }

        $result = [];
        foreach ($this->loadTransferCapableChannels() as $channel) {
            $channelId = (int) ($channel['id'] ?? 0);
            $result[] = $this->formatTransferListRow(
                $merchantId,
                $channel,
                $bindingMap[$channelId] ?? null
            );
        }

        return $result;
    }

    /**
     * 按通道列出商户授权（含商户只读字段，供「授权商户」抽屉）
     *
     * @param int $channelId 通道ID
     * @return array<int,array>
     * @throws ApiException 通道不存在
     */
    public function listByChannel(int $channelId): array
    {
        $channel = $this->loadChannelForList($channelId);
        if ($channel === null) {
            throw new ApiException('通道不存在');
        }

        $bindings = $this->loadBindingsByChannel($channelId);
        $bindingMap = [];
        foreach ($bindings as $row) {
            $bindingMap[(int) ($row['merchant_id'] ?? 0)] = $row;
        }

        $result = [];
        foreach ($this->loadActiveMerchants() as $merchant) {
            $merchantId = (int) ($merchant['id'] ?? 0);
            $result[] = $this->formatMerchantAuthRow(
                $merchant,
                $bindingMap[$merchantId] ?? null,
                $channel
            );
        }

        return $result;
    }

    /**
     * 按通道批量授权/关闭商户（通道维度放量入口）
     *
     * 开通（status=1）：新绑定默认 rate=0 继承 rate_self；已有绑定仅恢复 status，保留定制费率。
     * 关闭（status=2）：已有绑定置为停用；无绑定则跳过。
     *
     * @param int $channelId 通道ID
     * @param int[] $merchantIds 商户ID列表
     * @param int $status MerchantChannel::STATUS_NORMAL | STATUS_DISABLED
     * @return array{saved:int}
     * @throws ApiException
     */
    public function batchAuthorizeByChannel(int $channelId, array $merchantIds, int $status): array
    {
        if ($channelId <= 0) {
            throw new ApiException('通道ID无效');
        }
        if (!in_array($status, [MerchantChannel::STATUS_NORMAL, MerchantChannel::STATUS_DISABLED], true)) {
            throw new ApiException('授权状态非法');
        }
        if ($this->loadChannelForList($channelId) === null) {
            throw new ApiException('通道不存在');
        }

        $merchantIds = array_values(array_unique(array_filter(array_map('intval', $merchantIds))));
        if ($merchantIds === []) {
            throw new ApiException('请选择商户');
        }

        $saved = 0;

        return $this->transaction(function () use ($channelId, $merchantIds, $status, &$saved) {
            foreach ($merchantIds as $merchantId) {
                if ($this->loadMerchantForAuthorize($merchantId) === null) {
                    throw new ApiException('商户不存在或已停用：' . $merchantId);
                }

                $existing = $this->findBinding($merchantId, $channelId);

                if ($status === MerchantChannel::STATUS_NORMAL) {
                    if ($existing !== null) {
                        $this->edit((int) $existing['id'], ['status' => MerchantChannel::STATUS_NORMAL]);
                    } else {
                        $data = $this->defaultAuthorizeRow($merchantId, $channelId);
                        $this->validateRow($data, 'save');
                        $this->add($data);
                    }
                    $saved++;
                    continue;
                }

                // 关闭授权：仅更新既有绑定
                if ($existing !== null) {
                    $this->edit((int) $existing['id'], ['status' => MerchantChannel::STATUS_DISABLED]);
                    $saved++;
                }
            }

            return ['saved' => $saved];
        });
    }

    /**
     * 按通道批量开通/关闭商户代付授权（对称 batchAuthorizeByChannel，操作 transfer_enabled）
     *
     * 开通：新绑定默认 rate_transfer=0 继承 rate_transfer_self；已有绑定仅恢复 transfer_enabled，保留代收 status/费率。
     * 关闭：已有绑定置 transfer_enabled=停用；无绑定则跳过。
     *
     * @param int $channelId 通道ID（须具备代付能力）
     * @param int[] $merchantIds 商户ID列表
     * @param int $transferEnabled MerchantChannel::TRANSFER_ENABLED | TRANSFER_DISABLED
     * @return array{saved:int}
     * @throws ApiException
     */
    public function batchAuthorizeTransferByChannel(int $channelId, array $merchantIds, int $transferEnabled): array
    {
        if ($channelId <= 0) {
            throw new ApiException('通道ID无效');
        }
        if (!in_array($transferEnabled, [MerchantChannel::TRANSFER_ENABLED, MerchantChannel::TRANSFER_DISABLED], true)) {
            throw new ApiException('代付授权状态非法');
        }

        $channel = $this->loadChannelForList($channelId);
        if ($channel === null) {
            throw new ApiException('通道不存在');
        }
        if (!$this->isTransferCapableChannel($channel)) {
            throw new ApiException('该通道不具备代付能力');
        }

        $merchantIds = array_values(array_unique(array_filter(array_map('intval', $merchantIds))));
        if ($merchantIds === []) {
            throw new ApiException('请选择商户');
        }

        $saved = 0;

        return $this->transaction(function () use ($channelId, $merchantIds, $transferEnabled, &$saved) {
            foreach ($merchantIds as $merchantId) {
                if ($this->loadMerchantForAuthorize($merchantId) === null) {
                    throw new ApiException('商户不存在或已停用：' . $merchantId);
                }

                $existing = $this->findBinding($merchantId, $channelId);

                if ($transferEnabled === MerchantChannel::TRANSFER_ENABLED) {
                    if ($existing !== null) {
                        $this->edit((int) $existing['id'], ['transfer_enabled' => MerchantChannel::TRANSFER_ENABLED]);
                    } else {
                        $data = $this->defaultTransferAuthorizeRow($merchantId, $channelId);
                        $this->validateTransferRow($data);
                        $this->add($data);
                    }
                    $saved++;
                    continue;
                }

                if ($existing !== null) {
                    $this->edit((int) $existing['id'], ['transfer_enabled' => MerchantChannel::TRANSFER_DISABLED]);
                    $saved++;
                }
            }

            return ['saved' => $saved];
        });
    }

    /**
     * 批量保存商户×通道绑定（upsert；merchant_id 以参数为准，不信任 rows 内字段）
     *
     * @param int $merchantId 商户ID
     * @param array $rows 每项含 channel_id、rate、day_limit、single_min、single_max、status
     * @return array{saved:int} 成功写入条数
     * @throws ApiException 校验失败或通道不存在
     */
    public function batchBind(int $merchantId, array $rows): array
    {
        if ($merchantId <= 0) {
            throw new ApiException('商户ID无效');
        }

        $saved = 0;

        return $this->transaction(function () use ($merchantId, $rows, &$saved) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new ApiException('绑定数据格式非法');
                }

                $data = $this->normalizeBatchRow($merchantId, $row);
                $this->validateRow($data, 'save');

                $channelId = (int) $data['channel_id'];
                if ($this->loadChannelForList($channelId) === null) {
                    throw new ApiException('通道不存在：' . $channelId);
                }

                $existing = $this->findBinding($merchantId, $channelId);
                if ($existing !== null) {
                    $this->edit((int) $existing['id'], $data);
                } else {
                    $this->add($data);
                }
                $saved++;
            }

            return ['saved' => $saved];
        });
    }

    /**
     * 批量保存商户×代付通道绑定（仅写入 rate_transfer / transfer_enabled，不改动代收 status/费率）
     *
     * @param int $merchantId 商户ID
     * @param array $rows 每项含 channel_id、rate_transfer、transfer_enabled
     * @return array{saved:int}
     * @throws ApiException
     */
    public function batchBindTransfer(int $merchantId, array $rows): array
    {
        if ($merchantId <= 0) {
            throw new ApiException('商户ID无效');
        }

        $saved = 0;

        return $this->transaction(function () use ($merchantId, $rows, &$saved) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new ApiException('绑定数据格式非法');
                }

                $this->validateTransferRow($row);

                $channelId = (int) ($row['channel_id'] ?? 0);
                $channel = $this->loadChannelForList($channelId);
                if ($channel === null) {
                    throw new ApiException('通道不存在：' . $channelId);
                }
                if (!$this->isTransferCapableChannel($channel)) {
                    throw new ApiException('通道不具备代付能力：' . $channelId);
                }

                $data = $this->normalizeBatchTransferRow($merchantId, $row);
                $this->validateRow($data, 'save');

                $existing = $this->findBinding($merchantId, $channelId);
                if ($existing !== null) {
                    $this->edit((int) $existing['id'], $data);
                } else {
                    $this->add($data);
                }
                $saved++;
            }

            return ['saved' => $saved];
        });
    }

    /**
     * 规范化批量行：强制 merchant_id 来自参数，金额/费率字段格式化
     *
     * @param int $merchantId 商户ID
     * @param array $row 原始行
     * @return array
     */
    protected function normalizeBatchRow(int $merchantId, array $row): array
    {
        return [
            'merchant_id' => $merchantId,
            'channel_id'  => (int) ($row['channel_id'] ?? 0),
            'rate'             => AmountHelper::format((string) ($row['rate'] ?? '0')),
            'rate_transfer'    => AmountHelper::format((string) ($row['rate_transfer'] ?? '0')),
            'day_limit'   => AmountHelper::format((string) ($row['day_limit'] ?? '0')),
            'single_min'  => AmountHelper::format((string) ($row['single_min'] ?? MerchantChannel::LIMIT_UNLIMITED)),
            'single_max'  => AmountHelper::format((string) ($row['single_max'] ?? MerchantChannel::LIMIT_UNLIMITED)),
            'status'           => (int) ($row['status'] ?? MerchantChannel::STATUS_NORMAL),
            'transfer_enabled' => (int) ($row['transfer_enabled'] ?? MerchantChannel::TRANSFER_DISABLED),
        ];
    }

    /**
     * 校验单行绑定数据
     *
     * @param array $data 待校验数据
     * @param string $scene 验证场景
     */
    protected function validateRow(array $data, string $scene = 'save'): void
    {
        $validator = $this->makeValidate();
        if (!$validator->scene($scene)->check($data)) {
            throw new ApiException($validator->getError());
        }
    }

    /**
     * 校验代付批量行（batchTransferRow 场景）
     */
    protected function validateTransferRow(array $data): void
    {
        $validator = $this->makeValidate();
        if (!$validator->scene('batchTransferRow')->check($data)) {
            throw new ApiException($validator->getError());
        }
    }

    /**
     * 创建验证器实例（接缝，单测可注入）
     */
    protected function makeValidate(): MerchantChannelValidate
    {
        return new MerchantChannelValidate();
    }

    /**
     * 格式化列表行：合并通道只读参考字段
     *
     * @param array $binding merchant_channel 行
     * @param array|null $channel 通道行
     * @return array
     */
    protected function formatListRow(array $binding, ?array $channel): array
    {
        return [
            'id'                    => (int) ($binding['id'] ?? 0),
            'merchant_id'           => (int) ($binding['merchant_id'] ?? 0),
            'channel_id'            => (int) ($binding['channel_id'] ?? 0),
            'rate'                       => AmountHelper::format((string) ($binding['rate'] ?? '0')),
            'rate_transfer'              => AmountHelper::format((string) ($binding['rate_transfer'] ?? '0')),
            'day_limit'             => AmountHelper::format((string) ($binding['day_limit'] ?? '0')),
            'single_min'            => AmountHelper::format((string) ($binding['single_min'] ?? MerchantChannel::LIMIT_UNLIMITED)),
            'single_max'            => AmountHelper::format((string) ($binding['single_max'] ?? MerchantChannel::LIMIT_UNLIMITED)),
            'status'                     => (int) ($binding['status'] ?? MerchantChannel::STATUS_NORMAL),
            'transfer_enabled'           => (int) ($binding['transfer_enabled'] ?? MerchantChannel::TRANSFER_DISABLED),
            'remark'                => (string) ($binding['remark'] ?? ''),
            'channel_title'              => (string) ($channel['title'] ?? ''),
            'channel_code'               => (string) ($channel['code'] ?? ''),
            'channel_pay_type'           => (int) ($channel['pay_type'] ?? 0),
            'channel_rate_self'          => AmountHelper::format((string) ($channel['rate_self'] ?? '0')),
            'channel_rate_transfer_self' => AmountHelper::format((string) ($channel['rate_transfer_self'] ?? '0')),
            'channel_upstream_rate'      => AmountHelper::format((string) ($channel['rate'] ?? '0')),
            'channel_transfer_adapter'   => (string) ($channel['transfer_adapter'] ?? ''),
        ];
    }

    /**
     * 格式化代付配置列表行（商户维度）
     */
    protected function formatTransferListRow(int $merchantId, array $channel, ?array $binding): array
    {
        return [
            'id'                         => (int) ($binding['id'] ?? 0),
            'merchant_id'                => $merchantId,
            'channel_id'                 => (int) ($channel['id'] ?? 0),
            'rate_transfer'              => AmountHelper::format((string) ($binding['rate_transfer'] ?? MerchantChannel::RATE_INHERIT)),
            'transfer_enabled'           => (int) ($binding['transfer_enabled'] ?? MerchantChannel::TRANSFER_DISABLED),
            'channel_title'              => (string) ($channel['title'] ?? ''),
            'channel_code'               => (string) ($channel['code'] ?? ''),
            'channel_biz'                => (int) ($channel['channel_biz'] ?? Channel::BIZ_NONE),
            'channel_rate_transfer_self' => AmountHelper::format((string) ($channel['rate_transfer_self'] ?? '0')),
            'channel_transfer_adapter'   => (string) ($channel['transfer_adapter'] ?? ''),
            // 代收侧只读，便于运营对照（代付保存不修改）
            'status'                     => (int) ($binding['status'] ?? MerchantChannel::STATUS_DISABLED),
            'rate'                       => AmountHelper::format((string) ($binding['rate'] ?? MerchantChannel::RATE_INHERIT)),
        ];
    }

    /**
     * 新开通绑定的默认行（rate=0 继承通道 rate_self）
     */
    protected function defaultAuthorizeRow(int $merchantId, int $channelId): array
    {
        return [
            'merchant_id' => $merchantId,
            'channel_id'  => $channelId,
            'rate'             => MerchantChannel::RATE_INHERIT,
            'rate_transfer'    => MerchantChannel::RATE_INHERIT,
            'day_limit'   => MerchantChannel::LIMIT_UNLIMITED,
            'single_min'  => MerchantChannel::LIMIT_UNLIMITED,
            'single_max'  => MerchantChannel::LIMIT_UNLIMITED,
            'status'           => MerchantChannel::STATUS_NORMAL,
            'transfer_enabled' => MerchantChannel::TRANSFER_DISABLED,
        ];
    }

    /**
     * 新开通代付绑定的默认行（rate_transfer=0 继承 rate_transfer_self，代收默认停用）
     */
    protected function defaultTransferAuthorizeRow(int $merchantId, int $channelId): array
    {
        return [
            'merchant_id'      => $merchantId,
            'channel_id'       => $channelId,
            'rate'             => MerchantChannel::RATE_INHERIT,
            'rate_transfer'    => MerchantChannel::RATE_INHERIT,
            'day_limit'        => MerchantChannel::LIMIT_UNLIMITED,
            'single_min'       => MerchantChannel::LIMIT_UNLIMITED,
            'single_max'       => MerchantChannel::LIMIT_UNLIMITED,
            'status'           => MerchantChannel::STATUS_DISABLED,
            'transfer_enabled' => MerchantChannel::TRANSFER_ENABLED,
        ];
    }

    /**
     * 代付批量行归一化：合并既有绑定，仅覆盖代付字段
     */
    protected function normalizeBatchTransferRow(int $merchantId, array $row): array
    {
        $channelId = (int) ($row['channel_id'] ?? 0);
        $existing = $this->findBinding($merchantId, $channelId);

        if ($existing !== null) {
            return array_merge($existing, [
                'merchant_id'      => $merchantId,
                'channel_id'       => $channelId,
                'rate_transfer'    => AmountHelper::format((string) ($row['rate_transfer'] ?? $existing['rate_transfer'] ?? '0')),
                'transfer_enabled' => (int) ($row['transfer_enabled'] ?? $existing['transfer_enabled'] ?? MerchantChannel::TRANSFER_DISABLED),
            ]);
        }

        return [
            'merchant_id'      => $merchantId,
            'channel_id'       => $channelId,
            'rate'             => MerchantChannel::RATE_INHERIT,
            'rate_transfer'    => AmountHelper::format((string) ($row['rate_transfer'] ?? '0')),
            'day_limit'        => MerchantChannel::LIMIT_UNLIMITED,
            'single_min'       => MerchantChannel::LIMIT_UNLIMITED,
            'single_max'       => MerchantChannel::LIMIT_UNLIMITED,
            'status'           => MerchantChannel::STATUS_DISABLED,
            'transfer_enabled' => (int) ($row['transfer_enabled'] ?? MerchantChannel::TRANSFER_DISABLED),
        ];
    }

    /**
     * 格式化商户门户代收能力行（脱敏）
     *
     * 商户 API 下单传 pay_type，平台内部选路匹配具体上游通道；故不暴露 channel_code/title。
     */
    protected function formatMerchantPortalPayRow(array $binding, array $channel): array
    {
        $rate = AmountHelper::format((string) ($binding['rate'] ?? MerchantChannel::RATE_INHERIT));
        $rateSelf = AmountHelper::format((string) ($channel['rate_self'] ?? '0'));
        $payType = (int) ($channel['pay_type'] ?? 0);

        return [
            'id'             => (int) ($binding['id'] ?? 0),
            'pay_type'       => $payType,
            'pay_type_name'  => $this->resolvePayTypeName($payType),
            'money_rule'     => trim((string) ($channel['money_rule'] ?? '')),
            'rate'           => $rate,
            'effective_rate' => $this->resolveEffectiveRate($rate, $rateSelf),
            'rate_inherit'   => $this->isInheritRate($rate),
            'single_min'     => AmountHelper::format((string) ($binding['single_min'] ?? MerchantChannel::LIMIT_UNLIMITED)),
            'single_max'     => AmountHelper::format((string) ($binding['single_max'] ?? MerchantChannel::LIMIT_UNLIMITED)),
            'day_limit'      => AmountHelper::format((string) ($binding['day_limit'] ?? MerchantChannel::LIMIT_UNLIMITED)),
        ];
    }

    /**
     * 支付类型文案（与 sa_pay_channel.pay_type / 商户下单 pay_type 一致）
     */
    protected function resolvePayTypeName(int $payType): string
    {
        return match ($payType) {
            1       => '支付宝PC',
            2       => '支付宝H5',
            3       => '微信PC',
            4       => '微信H5',
            5       => '银联快捷',
            6       => '银联扫码',
            7       => '其他',
            default => '未知',
        };
    }

    /**
     * 格式化商户门户代付能力行（脱敏）
     *
     * 代付由平台审核后选路上游通道，商户提现申请无需传通道编码；故不暴露 channel_code/title/adapter。
     */
    protected function formatMerchantPortalTransferRow(array $binding, array $channel): array
    {
        $rateTransfer = AmountHelper::format((string) ($binding['rate_transfer'] ?? MerchantChannel::RATE_INHERIT));
        $rateTransferSelf = AmountHelper::format((string) ($channel['rate_transfer_self'] ?? '0'));
        $channelBiz = (int) ($channel['channel_biz'] ?? Channel::BIZ_NONE);

        return [
            'id'                      => (int) ($binding['id'] ?? 0),
            'channel_biz'             => $channelBiz,
            'channel_biz_name'        => $this->resolveChannelBizName($channelBiz),
            'money_rule'              => trim((string) ($channel['money_rule'] ?? '')),
            'rate_transfer'           => $rateTransfer,
            'effective_rate_transfer' => $this->resolveEffectiveRate($rateTransfer, $rateTransferSelf),
            'rate_inherit'            => $this->isInheritRate($rateTransfer),
        ];
    }

    /**
     * 通道业务能力文案（商户门户代付列表仅会出现 2/3）
     */
    protected function resolveChannelBizName(int $channelBiz): string
    {
        return match ($channelBiz) {
            Channel::BIZ_PAY_ONLY      => '仅代收',
            Channel::BIZ_TRANSFER_ONLY => '仅代付',
            Channel::BIZ_BOTH          => '代收+代付',
            default                    => '未配置',
        };
    }

    /**
     * 解析生效费率：定制为 0 时继承通道默认
     */
    protected function resolveEffectiveRate(string $customRate, string $defaultRate): string
    {
        return $this->isInheritRate($customRate)
            ? AmountHelper::format($defaultRate)
            : AmountHelper::format($customRate);
    }

    /**
     * 是否为「继承通道默认」费率（0.0000）
     */
    protected function isInheritRate(string $rate): bool
    {
        return AmountHelper::format($rate) === AmountHelper::format(MerchantChannel::RATE_INHERIT);
    }

    /**
     * 通道是否具备代收业务能力
     */
    protected function isPayCapableChannel(?array $channel): bool
    {
        if ($channel === null) {
            return false;
        }
        $biz = (int) ($channel['channel_biz'] ?? Channel::BIZ_NONE);

        return in_array($biz, [Channel::BIZ_PAY_ONLY, Channel::BIZ_BOTH], true);
    }

    /**
     * 通道是否具备代付业务能力
     */
    protected function isTransferCapableChannel(?array $channel): bool
    {
        if ($channel === null) {
            return false;
        }
        $biz = (int) ($channel['channel_biz'] ?? Channel::BIZ_NONE);

        return in_array($biz, [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH], true);
    }

    /**
     * 加载全部具备代付能力的通道（列表基准）
     *
     * @return array<int,array>
     */
    protected function loadTransferCapableChannels(): array
    {
        return Channel::whereIn('channel_biz', [Channel::BIZ_TRANSFER_ONLY, Channel::BIZ_BOTH])
            ->field('id,title,code,transfer_adapter,rate_transfer_self,channel_biz,status,sort')
            ->order('sort', 'desc')
            ->order('create_time', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 格式化通道维度商户授权行
     */
    protected function formatMerchantAuthRow(array $merchant, ?array $binding, array $channel): array
    {
        return [
            'id'                => (int) ($binding['id'] ?? 0),
            'merchant_id'       => (int) ($merchant['id'] ?? 0),
            'channel_id'        => (int) ($channel['id'] ?? 0),
            'mch_id'            => (string) ($merchant['mch_id'] ?? ''),
            'name'              => (string) ($merchant['name'] ?? ''),
            'rate'              => AmountHelper::format((string) ($binding['rate'] ?? MerchantChannel::RATE_INHERIT)),
            'rate_transfer'     => AmountHelper::format((string) ($binding['rate_transfer'] ?? MerchantChannel::RATE_INHERIT)),
            'status'            => (int) ($binding['status'] ?? MerchantChannel::STATUS_DISABLED),
            'transfer_enabled'  => (int) ($binding['transfer_enabled'] ?? MerchantChannel::TRANSFER_DISABLED),
            'channel_title'     => (string) ($channel['title'] ?? ''),
            'channel_rate_self' => AmountHelper::format((string) ($channel['rate_self'] ?? '0')),
            'channel_rate_transfer_self' => AmountHelper::format((string) ($channel['rate_transfer_self'] ?? '0')),
        ];
    }

    /**
     * 加载通道下全部绑定
     *
     * @param int $channelId 通道ID
     * @return array<int,array>
     */
    protected function loadBindingsByChannel(int $channelId): array
    {
        return MerchantChannel::where('channel_id', $channelId)
            ->order('create_time', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 加载可授权的正常商户
     *
     * @return array<int,array>
     */
    protected function loadActiveMerchants(): array
    {
        return Merchant::where('status', 1)
            ->field('id,mch_id,name,status')
            ->order('create_time', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 校验商户可授权（存在且 status=正常）
     */
    protected function loadMerchantForAuthorize(int $merchantId): ?array
    {
        $row = Merchant::where('id', $merchantId)
            ->where('status', 1)
            ->field('id,mch_id,name')
            ->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 加载商户下全部绑定记录
     *
     * @param int $merchantId 商户ID
     * @return array<int,array>
     */
    protected function loadBindingsByMerchant(int $merchantId): array
    {
        return MerchantChannel::where('merchant_id', $merchantId)
            ->order('create_time', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 加载通道摘要（列表/存在性校验用）
     *
     * @param int $channelId 通道ID
     * @return array|null
     */
    protected function loadChannelForList(int $channelId): ?array
    {
        $row = Channel::where('id', $channelId)
            ->field('id,title,code,pay_type,money_rule,rate,rate_self,rate_transfer_self,transfer_adapter,channel_biz,status,sort')
            ->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 查找既有绑定（merchant_id + channel_id 唯一）
     *
     * @param int $merchantId 商户ID
     * @param int $channelId 通道ID
     * @return array|null
     */
    protected function findBinding(int $merchantId, int $channelId): ?array
    {
        $row = MerchantChannel::where('merchant_id', $merchantId)
            ->where('channel_id', $channelId)
            ->find();

        return $row ? $row->toArray() : null;
    }
}
