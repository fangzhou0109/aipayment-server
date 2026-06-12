-- +----------------------------------------------------------------------
-- | Phase 9.2.1 商户×通道单笔限额 —— 增量结构迁移
-- +----------------------------------------------------------------------
-- | 说明：
-- |   - 为 sa_pay_merchant_channel 增 single_min / single_max（元，decimal(16,4)）。
-- |   - 默认 0.0000 表示不限；运行时校验由 Phase 9.2.2 RiskService 接入。
-- |   - 可重复执行：通过 information_schema 判断列是否已存在。
-- | 执行：在目标库手动 source 本文件；勿纳入 paymentchannel.sql 全量 DROP 流程。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

SET @dbname = DATABASE();

-- single_min：单笔最小金额（0=不限）
SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_merchant_channel'
      AND COLUMN_NAME = 'single_min'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_merchant_channel`
       ADD COLUMN `single_min` decimal(16, 4) NOT NULL DEFAULT 0.0000
         COMMENT ''单笔最小金额（元，0=不限，Phase9.2运行时接入）'' AFTER `day_limit`',
    'SELECT ''skip: single_min already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- single_max：单笔最大金额（0=不限）
SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_merchant_channel'
      AND COLUMN_NAME = 'single_max'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_merchant_channel`
       ADD COLUMN `single_max` decimal(16, 4) NOT NULL DEFAULT 0.0000
         COMMENT ''单笔最大金额（元，0=不限，Phase9.2运行时接入）'' AFTER `single_min`',
    'SELECT ''skip: single_max already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 表注释对齐 Phase 9.2
ALTER TABLE `sa_pay_merchant_channel`
  COMMENT = '商户-通道授权与代收费率/限额定制（代收白名单）';
