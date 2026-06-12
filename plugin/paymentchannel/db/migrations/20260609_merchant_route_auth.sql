-- +----------------------------------------------------------------------
-- | Phase 9.3.1 商户×路由授权 —— 增量结构迁移
-- +----------------------------------------------------------------------
-- | 说明：
-- |   - 新增 sa_pay_merchant_route：可选收紧商户可用 Route 集合（VIP 路由组）。
-- |   - 无任何记录时 PayGatewayLogic 仍遍历全部启用路由；有启用记录时仅限授权路由。
-- |   - 可重复执行：通过 information_schema 判断表是否已存在。
-- | 执行：在目标库手动 source 本文件；勿纳入 paymentchannel.sql 全量 DROP 流程。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

SET @dbname = DATABASE();

SET @exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sa_pay_merchant_route'
);
SET @sql = IF(
    @exists = 0,
    'CREATE TABLE `sa_pay_merchant_route` (
      `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''主键'',
      `merchant_id` int(11) UNSIGNED NOT NULL COMMENT ''商户ID'',
      `route_id` int(11) UNSIGNED NOT NULL COMMENT ''路由ID'',
      `status` smallint(6) NOT NULL DEFAULT 1 COMMENT ''授权状态 (1正常/已授权 2停用)，无启用记录则不收紧路由'',
      `remark` varchar(255) NULL DEFAULT NULL COMMENT ''备注'',
      `created_by` int(11) NULL DEFAULT NULL COMMENT ''创建者'',
      `updated_by` int(11) NULL DEFAULT NULL COMMENT ''更新者'',
      `create_time` datetime(0) NULL DEFAULT NULL COMMENT ''创建时间'',
      `update_time` datetime(0) NULL DEFAULT NULL COMMENT ''修改时间'',
      `delete_time` datetime(0) NULL DEFAULT NULL COMMENT ''删除时间'',
      PRIMARY KEY (`id`) USING BTREE,
      UNIQUE INDEX `uk_merchant_route`(`merchant_id`, `route_id`) USING BTREE,
      INDEX `idx_merchant_id`(`merchant_id`) USING BTREE,
      INDEX `idx_route_id`(`route_id`) USING BTREE
    ) ENGINE = InnoDB COMMENT = ''商户-路由授权（代收路由白名单，可选）'' ROW_FORMAT = Dynamic',
    'SELECT ''skip: sa_pay_merchant_route already exists'' AS migration_info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
