-- +----------------------------------------------------------------------
-- | SaiPayment 四方支付渠道系统
-- +----------------------------------------------------------------------
-- | Phase 9.5.7：重导支付插件菜单前的清理（仅 DELETE，幂等）
-- | 用法：
-- |   1) 在目标库执行本文件（或下方两条 DELETE）
-- |   2) 再执行 plugin/paymentchannel/db/menu.sql 全文（含 INSERT）
-- | 说明：
-- |   - 仅清理 ID 9000~9099（paymentchannel 插件菜单段），不影响 saiadmin 其他菜单
-- |   - 须先删 sa_system_role_menu，避免角色仍引用已删 menu_id
-- |   - 重导后请在「角色管理」重新勾选支付管理/代付通道等权限
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

-- 1) 角色-菜单关联（孤儿引用）
DELETE FROM `sa_system_role_menu`
WHERE `menu_id` BETWEEN 9000 AND 9099;

-- 2) 支付插件菜单树（目录 + 业务菜单 + 按钮）
DELETE FROM `sa_system_menu`
WHERE `id` BETWEEN 9000 AND 9099;
