-- +----------------------------------------------------------------------
-- | SaiPayment 四方支付渠道系统 —— 后台菜单与权限种子
-- +----------------------------------------------------------------------
-- | 插件：paymentchannel
-- | 作用：向 saiadmin 的 sa_system_menu 写入「支付管理」目录 + 业务菜单 + 按钮权限。
-- | 约定：
-- |   - ID 使用 9000~9099 高位段，避开 saiadmin 现有/未来菜单（其 AUTO_INCREMENT 起步 1000）。
-- |   - 权限码 slug 统一 pay: 前缀（区别于 saiadmin 的 core:），格式 pay:模块:动作。
-- |   - 菜单 component 指向前端 src/views 下的相对路径（Phase 2.4 创建对应页面）。
-- |   - 可重复执行：先按 ID 段删除再插入。
-- |   - 列顺序：id,parent_id,name,code,slug,type,path,component,method,icon,sort,
-- |            link_url,is_iframe,is_keep_alive,is_hidden,is_fixed_tab,is_full_page,
-- |            generate_id,generate_key,status,remark,created_by,updated_by,
-- |            create_time,update_time,delete_time
-- |   - type：1 目录 / 2 菜单 / 3 按钮(API)
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

-- 幂等：清理本插件菜单 ID 段，便于重复导入
DELETE FROM `sa_system_menu` WHERE `id` BETWEEN 9000 AND 9099;

-- ============== 顶级目录：支付管理 ==============
INSERT INTO `sa_system_menu` VALUES (9000, 0, '支付管理', 'Payment', NULL, 1, '/payment', NULL, NULL, 'ri:bank-card-line', 60, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '四方支付渠道系统', 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);

-- ============== 1. 商户管理 ==============
INSERT INTO `sa_system_menu` VALUES (9010, 9000, '商户管理', 'PayMerchant', NULL, 2, 'merchant', '/payment/merchant', NULL, 'ri:store-2-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9011, 9010, '数据列表', '', 'pay:merchant:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9012, 9010, '添加', '', 'pay:merchant:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9013, 9010, '修改', '', 'pay:merchant:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9014, 9010, '读取', '', 'pay:merchant:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9015, 9010, '删除', '', 'pay:merchant:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9016, 9010, '重置密钥', '', 'pay:merchant:resetKey', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9019, 9010, '余额调账', '', 'pay:merchant:adjustBalance', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '人工增减商户可用余额', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
-- Phase 9.1：商户代收通道授权与费率（listByMerchant / batchSave / 后台「通道配置」抽屉）
INSERT INTO `sa_system_menu` VALUES (9017, 9010, '代收/代付通道配置', '', 'pay:merchant:channel', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '商户代收与代付通道授权（入口已拆分）', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
-- Phase 9.3.1：商户×路由授权（listByMerchant / batchSave，可选收紧 resolveChannel）
INSERT INTO `sa_system_menu` VALUES (9018, 9010, '路由配置', '', 'pay:merchant:route', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '商户×路由代收白名单', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);

-- ============== 2. 代收通道管理（路径仍为 /payment/channel，Phase 9.5.6） ==============
INSERT INTO `sa_system_menu` VALUES (9020, 9000, '代收通道管理', 'PayChannel', NULL, 2, 'channel', '/payment/channel', NULL, 'ri:route-line', 90, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '列表 scope channel_biz IN 1,3', 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9021, 9020, '数据列表', '', 'pay:channel:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9022, 9020, '添加', '', 'pay:channel:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9023, 9020, '修改', '', 'pay:channel:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9024, 9020, '读取', '', 'pay:channel:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9025, 9020, '删除', '', 'pay:channel:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
-- Phase 9.2.4：通道页「授权商户」批量开通/关闭 merchant_channel
INSERT INTO `sa_system_menu` VALUES (9026, 9020, '授权商户', '', 'pay:channel:auth', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '通道维度批量授权商户代收', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);

-- ============== 2b. 代付通道管理（Phase 9.5.7，插入后路由及以下菜单 ID +4） ==============
INSERT INTO `sa_system_menu` VALUES (9027, 9000, '代付通道管理', 'PayTransferChannel', NULL, 2, 'transfer-channel', '/payment/transfer-channel', NULL, 'ri:swap-line', 85, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '列表 scope channel_biz IN 2,3', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9028, 9027, '数据列表', '', 'pay:transferChannel:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9029, 9027, '添加', '', 'pay:transferChannel:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9030, 9027, '修改', '', 'pay:transferChannel:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9031, 9027, '读取', '', 'pay:transferChannel:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9032, 9027, '删除', '', 'pay:transferChannel:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9033, 9027, '授权商户', '', 'pay:transferChannel:auth', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '通道维度批量授权商户代付', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);

-- ============== 3. 路由管理（ID 原 9030 段顺延 +4） ==============
INSERT INTO `sa_system_menu` VALUES (9034, 9000, '路由管理', 'PayRoute', NULL, 2, 'route', '/payment/route', NULL, 'ri:share-forward-line', 80, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9035, 9034, '数据列表', '', 'pay:route:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9036, 9034, '添加', '', 'pay:route:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9037, 9034, '修改', '', 'pay:route:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9038, 9034, '读取', '', 'pay:route:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9039, 9034, '删除', '', 'pay:route:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);

-- ============== 3b. 代收测试下单 ==============
INSERT INTO `sa_system_menu` VALUES (9043, 9000, '代收测试下单', 'PayOrderTest', NULL, 2, 'order-test', '/payment/order-test', NULL, 'ri:flask-line', 72, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '平台后台测试 submitOrder，免商户签名', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9040, 9043, '测试下单', '', 'pay:order:testSubmit', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL);

-- ============== 4. 订单管理 ==============
INSERT INTO `sa_system_menu` VALUES (9044, 9000, '订单管理', 'PayOrder', NULL, 2, 'order', '/payment/order', NULL, 'ri:file-list-3-line', 70, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9045, 9044, '数据列表', '', 'pay:order:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9046, 9044, '读取', '', 'pay:order:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9047, 9044, '补单', '', 'pay:order:reissue', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9048, 9044, '导出', '', 'pay:order:export', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);

-- ============== 5. 提现审核 ==============
INSERT INTO `sa_system_menu` VALUES (9054, 9000, '提现审核', 'PayWithdraw', NULL, 2, 'withdraw', '/payment/withdraw', NULL, 'ri:hand-coin-line', 60, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9055, 9054, '数据列表', '', 'pay:withdraw:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9056, 9054, '读取', '', 'pay:withdraw:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9057, 9054, '审核', '', 'pay:withdraw:audit', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);

-- ============== 6. 充值审核 ==============
INSERT INTO `sa_system_menu` VALUES (9064, 9000, '充值审核', 'PayRecharge', NULL, 2, 'recharge', '/payment/recharge', NULL, 'ri:wallet-3-line', 50, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9065, 9064, '数据列表', '', 'pay:recharge:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9066, 9064, '读取', '', 'pay:recharge:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9067, 9064, '审核', '', 'pay:recharge:audit', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);

-- ============== 7. 资金流水 ==============
INSERT INTO `sa_system_menu` VALUES (9074, 9000, '资金流水', 'PayCapital', NULL, 2, 'capital', '/payment/capital', NULL, 'ri:exchange-funds-line', 40, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9075, 9074, '数据列表', '', 'pay:capital:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9076, 9074, '导出', '', 'pay:capital:export', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);

-- ============== 8. 银行卡管理 ==============
INSERT INTO `sa_system_menu` VALUES (9084, 9000, '银行卡', 'PayBankCard', NULL, 2, 'bank-card', '/payment/bank-card', NULL, 'ri:bank-line', 30, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9085, 9084, '数据列表', '', 'pay:bankCard:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9086, 9084, '添加', '', 'pay:bankCard:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9087, 9084, '修改', '', 'pay:bankCard:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9088, 9084, '删除', '', 'pay:bankCard:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);

-- ============== 9. 通知日志 ==============
INSERT INTO `sa_system_menu` VALUES (9094, 9000, '通知日志', 'PayNotifyLog', NULL, 2, 'notify-log', '/payment/notify-log', NULL, 'ri:notification-3-line', 20, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9095, 9094, '数据列表', '', 'pay:notify:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9096, 9094, '读取', '', 'pay:notify:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
INSERT INTO `sa_system_menu` VALUES (9097, 9094, '重发通知', '', 'pay:notify:resend', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);
