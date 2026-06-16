-- ============================================================
-- 代付管理菜单（API 代付订单，pay:transferOrder:*）
-- Phase：提现/代付后台菜单物理拆分
-- 说明：与「提现审核」拆为两个菜单——提现审核只看 source=1 的商户门户人工提现，
--       代付管理只看 source=2 的商户服务端 API 代付单（下游调 /pay/transfer 进单）。
-- 二者共用 sa_pay_withdraw 表与 WithdrawLogic 状态机，权限码独立。
-- 执行后需在「系统管理 → 角色管理」为运营角色勾选「代付管理」相关权限。
-- ============================================================

-- 一级菜单：代付管理（前端路由 /payment/transfer-order，组件 PayTransferOrder）
INSERT INTO `sa_system_menu` (`id`, `parent_id`, `name`, `code`, `permission`, `type`, `path`, `component`, `redirect`, `icon`, `sort`, `is_hidden`, `is_cache`, `is_affix`, `is_iframe`, `is_link`, `is_keep_alive`, `is_full`, `remark`, `status`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`)
SELECT 9100, 9000, '代付管理', 'PayTransferOrder', NULL, 2, 'transfer-order', '/payment/transfer-order', NULL, 'ri:send-plane-line', 58, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, 'API 代付订单（source=2）', 1, 1, NOW(), NOW(), NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sa_system_menu` WHERE `id` = 9100 OR (`name` = '代付管理' AND `parent_id` = 9000));

-- 按钮：数据列表
INSERT INTO `sa_system_menu` (`id`, `parent_id`, `name`, `code`, `permission`, `type`, `path`, `component`, `redirect`, `icon`, `sort`, `is_hidden`, `is_cache`, `is_affix`, `is_iframe`, `is_link`, `is_keep_alive`, `is_full`, `remark`, `status`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`)
SELECT 9101, 9100, '数据列表', '', 'pay:transferOrder:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, NOW(), NOW(), NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sa_system_menu` WHERE `id` = 9101 OR `permission` = 'pay:transferOrder:index');

-- 按钮：读取
INSERT INTO `sa_system_menu` (`id`, `parent_id`, `name`, `code`, `permission`, `type`, `path`, `component`, `redirect`, `icon`, `sort`, `is_hidden`, `is_cache`, `is_affix`, `is_iframe`, `is_link`, `is_keep_alive`, `is_full`, `remark`, `status`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`)
SELECT 9102, 9100, '读取', '', 'pay:transferOrder:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, NOW(), NOW(), NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sa_system_menu` WHERE `id` = 9102 OR `permission` = 'pay:transferOrder:read');

-- 按钮：审核（含代付下发 / 拒绝解冻）
INSERT INTO `sa_system_menu` (`id`, `parent_id`, `name`, `code`, `permission`, `type`, `path`, `component`, `redirect`, `icon`, `sort`, `is_hidden`, `is_cache`, `is_affix`, `is_iframe`, `is_link`, `is_keep_alive`, `is_full`, `remark`, `status`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`)
SELECT 9103, 9100, '审核', '', 'pay:transferOrder:audit', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, NOW(), NOW(), NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sa_system_menu` WHERE `id` = 9103 OR `permission` = 'pay:transferOrder:audit');
