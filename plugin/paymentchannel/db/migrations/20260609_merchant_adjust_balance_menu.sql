-- 商户余额调账菜单权限（pay:merchant:adjustBalance）
-- 执行后需在「系统管理 → 角色管理」为运营角色勾选该权限

INSERT INTO `sa_system_menu` (`id`, `parent_id`, `name`, `code`, `permission`, `type`, `path`, `component`, `redirect`, `icon`, `sort`, `is_hidden`, `is_cache`, `is_affix`, `is_iframe`, `is_link`, `is_keep_alive`, `is_full`, `remark`, `status`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`)
SELECT 9019, 9010, '余额调账', '', 'pay:merchant:adjustBalance', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '人工增减商户可用余额', 1, 1, NOW(), NOW(), NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sa_system_menu` WHERE `id` = 9019 OR `permission` = 'pay:merchant:adjustBalance');
