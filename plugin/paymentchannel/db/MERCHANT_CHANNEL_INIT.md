# 存量商户通道授权初始化指引（Phase 9.1 严格模式）

> Phase 9.1 上线后，代收下单要求商户在 `sa_pay_merchant_channel` 中至少有一条 **status=1（已授权）** 的通道绑定，否则 `submitOrder` 将拒绝（「商户未配置可用支付通道」）。
>
> 本文档供运营 / 运维在 **9.1 代码部署前或同时** 完成存量数据补绑。

## 1. 检查当前缺口

```sql
-- 无任何启用绑定的商户（需补绑）
SELECT m.id, m.mch_id, m.name
FROM sa_pay_merchant m
WHERE m.delete_time IS NULL
  AND m.status = 1
  AND NOT EXISTS (
    SELECT 1 FROM sa_pay_merchant_channel mc
    WHERE mc.merchant_id = m.id
      AND mc.status = 1
      AND mc.delete_time IS NULL
  );
```

## 2. 确认可用上游通道

```sql
-- 已启用的代收通道（需已配置路由 route_channel，否则绑了也选不中）
SELECT c.id, c.code, c.title, c.pay_type, c.rate_self, c.rate AS upstream_cost
FROM sa_pay_channel c
WHERE c.status = 1 AND c.delete_time IS NULL
ORDER BY c.pay_type, c.id;
```

## 3. 补绑方式

### 方式 A：后台 UI（推荐，9.1.5 上线后）

平台后台 → 支付管理 → 商户 → 操作「通道配置」→ 对需要的通道打开授权，平台费率填 `0`（继承通道 `rate_self`）或独立费率（须 **>** 通道上游成本 `rate`）。

### 方式 B：SQL 批量导入（部署窗口 / 无 UI 时）

按「商户 × 应可用通道」插入，**rate=0** 表示继承通道默认平台费率：

```sql
-- 示例：为商户 id=1 授权通道 id=10、11（请按实际 id 替换）
INSERT INTO sa_pay_merchant_channel
  (merchant_id, channel_id, rate, day_limit, status, create_time, update_time)
VALUES
  (1, 10, 0.0000, 0.0000, 1, NOW(), NOW()),
  (1, 11, 0.0000, 0.0000, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  status = VALUES(status),
  update_time = NOW();
```

若需统一给所有正常商户授权某一条通道（慎用，先确认业务）：

```sql
-- 示例：全体正常商户授权 channel_id=10
INSERT INTO sa_pay_merchant_channel (merchant_id, channel_id, rate, day_limit, status, create_time, update_time)
SELECT m.id, 10, 0.0000, 0.0000, 1, NOW(), NOW()
FROM sa_pay_merchant m
WHERE m.status = 1 AND m.delete_time IS NULL
ON DUPLICATE KEY UPDATE status = 1, update_time = NOW();
```

## 4. 费率填写规则（与 Phase 9 一致）

| 字段 | 含义 |
|------|------|
| `merchant_channel.rate = 0` | 使用 `sa_pay_channel.rate_self` |
| `merchant_channel.rate > 0` | 商户独立平台费率，保存/运行时须 **>** `channel.rate`（上游成本） |
| `merchant.rate` | **不再参与代收计费**（9.1 起），勿依赖该字段 |

## 5. 部署顺序建议

1. 执行 [migrations/20260609_merchant_channel_auth.sql](migrations/20260609_merchant_channel_auth.sql)（更新字段注释，无结构变更）
2. 按上文补绑 `merchant_channel`
3. 部署 Phase 9.1 后端（`PayGatewayLogic` 严格模式）
4. 抽样商户 `submitOrder` 验证；未绑商户应明确拒单

## 6. 回滚说明

若需临时回退严格模式，须回滚 **9.1.3 及之后** 的应用代码版本；本迁移脚本仅改 COMMENT，回滚代码即可，无需逆向 SQL。
