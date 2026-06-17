-- ----------------------------------------------------------------------
-- 迁移：商户「API 代付自动下发阈值」（每商户独立配置）
-- 说明：sa_pay_merchant 增加 auto_disbursement_threshold（元）。
--   = 0（默认）：回落平台全局 transfer_api.auto_threshold（默认 0 = 全部转人工审核）
--   > 0：API 代付进单时金额 <= 该阈值自动下发，> 阈值留「待审核」转平台人工
-- 商户可在商户门户「API 对接信息」页自助设置。
-- ----------------------------------------------------------------------
ALTER TABLE `sa_pay_merchant`
  ADD COLUMN `auto_disbursement_threshold` decimal(16, 4) NOT NULL DEFAULT 0.0000
  COMMENT 'API代付自动下发阈值（元，0=回落全局/全部人工；>0时金额<=该值自动下发）'
  AFTER `single_max`;
