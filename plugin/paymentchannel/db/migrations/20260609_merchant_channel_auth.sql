-- +----------------------------------------------------------------------
-- | Phase 9.1.1 商户通道授权与代收费率 —— 语义迁移（无结构变更）
-- +----------------------------------------------------------------------
-- | 说明：
-- |   - 本脚本**不增删列**，仅更新表/字段 COMMENT，对齐 Phase 9 业务语义。
-- |   - rate=0（merchant_channel）表示继承 sa_pay_channel.rate_self。
-- |   - merchant.rate 自 Phase 9.1 起不再参与代收下单计费（保留字段作历史/展示）。
-- |   - 可重复执行（MODIFY COLUMN 幂等）。
-- | 执行：在目标库手动 source 本文件；勿纳入 paymentchannel.sql 全量 DROP 流程。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

-- 商户表：弱化代收默认费率语义
ALTER TABLE `sa_pay_merchant`
  MODIFY COLUMN `rate` decimal(8, 4) NOT NULL DEFAULT 0.0000
    COMMENT '代收费率（历史/展示，Phase9.1起不参与下单计费，以 merchant_channel+通道为准）';

ALTER TABLE `sa_pay_merchant`
  COMMENT = '支付商户表';

-- 商户-通道授权表：明确授权与费率继承规则
ALTER TABLE `sa_pay_merchant_channel`
  MODIFY COLUMN `rate` decimal(8, 4) NOT NULL DEFAULT 0.0000
    COMMENT '平台费率(%)：0=继承通道 rate_self；>0=商户独立费率且须>通道上游成本 rate';

ALTER TABLE `sa_pay_merchant_channel`
  MODIFY COLUMN `day_limit` decimal(16, 4) NOT NULL DEFAULT 0.0000
    COMMENT '日限额(元，0=不限，Phase9.2运行时接入)';

ALTER TABLE `sa_pay_merchant_channel`
  MODIFY COLUMN `status` smallint(6) NOT NULL DEFAULT 1
    COMMENT '授权状态 (1正常/已授权 2停用)，Phase9.1严格模式：无启用绑定则不可代收';

ALTER TABLE `sa_pay_merchant_channel`
  COMMENT = '商户-通道授权与代收费率定制（代收白名单）';
