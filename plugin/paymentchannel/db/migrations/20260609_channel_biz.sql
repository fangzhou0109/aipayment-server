-- +----------------------------------------------------------------------
-- | Phase 9.5.1 通道业务能力 channel_biz —— 增量结构迁移
-- +----------------------------------------------------------------------
-- | 说明：
-- |   - sa_pay_channel 增 channel_biz（0未配置 1仅代收 2仅代付 3双能力）。
-- |   - 存量行按当前 adapter / transfer_adapter 与注册表已知 code 回填。
-- | 执行：在目标库手动 source 本文件；可重复执行。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

SET @dbname = DATABASE();

-- ---------- sa_pay_channel.channel_biz ----------
SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_channel'
      AND COLUMN_NAME = 'channel_biz'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_channel`
       ADD COLUMN `channel_biz` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
         COMMENT ''通道业务能力：0未配置 1仅代收 2仅代付 3双能力（Phase9.5）'' AFTER `transfer_adapter`',
    'SELECT ''skip: channel_biz already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- 存量回填（与 ChannelAdapterRegistry / TransferAdapterRegistry 登记 code 对齐）----------
UPDATE `sa_pay_channel`
SET `channel_biz` = CASE
    WHEN TRIM(`adapter`) IN (''mock'', ''alipay_scan'', ''wechat_scan'')
         AND TRIM(IFNULL(`transfer_adapter`, '''')) IN (''mock_transfer'', ''bank_transfer'') THEN 3
    WHEN TRIM(`adapter`) IN (''mock'', ''alipay_scan'', ''wechat_scan'') THEN 1
    WHEN TRIM(IFNULL(`transfer_adapter`, '''')) IN (''mock_transfer'', ''bank_transfer'') THEN 2
    ELSE 0
END
WHERE `delete_time` IS NULL;
