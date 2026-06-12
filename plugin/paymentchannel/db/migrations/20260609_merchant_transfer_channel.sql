-- +----------------------------------------------------------------------
-- | Phase 9.4.1 商户×代付通道 —— 增量结构迁移（方案 A）
-- +----------------------------------------------------------------------
-- | 说明：
-- |   - sa_pay_merchant_channel 增 rate_transfer、transfer_enabled（与代收 status 独立）。
-- |   - sa_pay_channel 增 transfer_adapter、rate_transfer_self（代付适配器与平台默认费率）。
-- |   - transfer_channel_code 配置保留作系统兜底，运行时接入见 Phase 9.4.2。
-- | 执行：在目标库手动 source 本文件；可重复执行。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

SET @dbname = DATABASE();

-- ---------- sa_pay_merchant_channel.rate_transfer ----------
SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_merchant_channel'
      AND COLUMN_NAME = 'rate_transfer'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_merchant_channel`
       ADD COLUMN `rate_transfer` decimal(8, 4) NOT NULL DEFAULT 0.0000
         COMMENT ''代付平台费率(%)：0=继承通道rate_transfer_self；>0=商户独立代付费率'' AFTER `rate`',
    'SELECT ''skip: rate_transfer already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- sa_pay_merchant_channel.transfer_enabled ----------
SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_merchant_channel'
      AND COLUMN_NAME = 'transfer_enabled'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_merchant_channel`
       ADD COLUMN `transfer_enabled` smallint(6) NOT NULL DEFAULT 2
         COMMENT ''代付授权 (1已授权 2停用)，与代收status独立'' AFTER `status`',
    'SELECT ''skip: transfer_enabled already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- sa_pay_channel.transfer_adapter ----------
SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_channel'
      AND COLUMN_NAME = 'transfer_adapter'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_channel`
       ADD COLUMN `transfer_adapter` varchar(64) NULL DEFAULT NULL
         COMMENT ''代付适配器标识（空则回退 adapter）'' AFTER `adapter`',
    'SELECT ''skip: transfer_adapter already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- sa_pay_channel.rate_transfer_self ----------
SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_channel'
      AND COLUMN_NAME = 'rate_transfer_self'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_channel`
       ADD COLUMN `rate_transfer_self` decimal(8, 4) NOT NULL DEFAULT 0.0000
         COMMENT ''代付平台默认费率(%)，merchant_channel.rate_transfer=0时继承'' AFTER `rate_self`',
    'SELECT ''skip: rate_transfer_self already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `sa_pay_merchant_channel`
  COMMENT = '商户-通道授权（代收+代付费率/限额，代收status/代付transfer_enabled独立）';
