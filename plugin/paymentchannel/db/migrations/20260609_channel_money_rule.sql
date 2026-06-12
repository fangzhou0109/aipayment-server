-- +----------------------------------------------------------------------
-- | 代收通道直连选路金额规则（sa_pay_channel.money_rule）
-- +----------------------------------------------------------------------
-- | 与 route_channel.money_rule 格式一致：空=不限；范围 300-10000；固定池 800+1000
-- | 综合路由未命中时，直连模式按本字段过滤后再以 sort 加权选通道。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sa_pay_channel'
      AND COLUMN_NAME = 'money_rule'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `sa_pay_channel` ADD COLUMN `money_rule` varchar(255) NULL DEFAULT NULL COMMENT ''直连选路金额规则：空=不限；范围(300-10000)或固定池(800+1000)'' AFTER `sort`',
    'SELECT ''skip: sa_pay_channel.money_rule already exists'' AS msg'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
