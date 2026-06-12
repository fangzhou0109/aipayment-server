-- +----------------------------------------------------------------------
-- | Phase 9.3.4 订单费率来源快照 —— 增量结构迁移
-- +----------------------------------------------------------------------
-- | 说明：
-- |   - sa_pay_order 增 rate_source、merchant_channel_id，建单时写入便于运营审计。
-- |   - 可重复执行：通过 information_schema 判断列是否已存在。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

SET @dbname = DATABASE();

-- rate_source：merchant_channel | route | channel
SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_order'
      AND COLUMN_NAME = 'rate_source'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_order`
      ADD COLUMN `rate_source` varchar(32) NOT NULL DEFAULT '''' COMMENT ''费率来源快照(merchant_channel/route/channel)'' AFTER `rate`',
    'SELECT ''skip: sa_pay_order.rate_source already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_order'
      AND COLUMN_NAME = 'merchant_channel_id'
);
SET @sql = IF(
    @exists = 0,
    'ALTER TABLE `sa_pay_order`
      ADD COLUMN `merchant_channel_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT ''费率来源为merchant_channel时的绑定ID快照'' AFTER `rate_source`',
    'SELECT ''skip: sa_pay_order.merchant_channel_id already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
