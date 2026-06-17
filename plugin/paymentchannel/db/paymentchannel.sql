-- +----------------------------------------------------------------------
-- | SaiPayment 四方支付渠道系统 —— 业务表结构（13 张 sa_pay_* 表）
-- +----------------------------------------------------------------------
-- | 插件：paymentchannel
-- | 约定：
-- |   - 字符集 utf8mb4；引擎 InnoDB；ROW_FORMAT=Dynamic
-- |   - 金额统一 decimal(16,4)（元，配合 bcmath），费率 decimal(8,4)（百分数，如 2.6000=2.6%）
-- |   - 软删除字段 delete_time（与 saiadmin BaseModel 一致）
-- |   - 公共字段 created_by/updated_by/create_time/update_time/delete_time
-- |   - status 约定：1 正常 / 2 停用（与 saiadmin data_status 一致）
-- |   - 与 saiadmin 同库导入；可重复执行（DROP TABLE IF EXISTS）
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 1. 商户表 sa_pay_merchant
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_merchant`;
CREATE TABLE `sa_pay_merchant`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（对外唯一标识）',
  `name` varchar(100) NULL DEFAULT NULL COMMENT '商户名称',
  `avatar` varchar(500) NULL DEFAULT NULL COMMENT '商户门户头像URL',
  `secret_key` varchar(64) NULL DEFAULT NULL COMMENT 'MD5 签名密钥',
  `rsa_public_key` text NULL COMMENT '商户 RSA 公钥（验商户来签）',
  `rsa_private_key` text NULL COMMENT '平台 RSA 私钥（对该商户回调签名用）',
  `balance` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '可用余额（元）',
  `balance_freeze` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '冻结余额（元）',
  `rate` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '代收费率（历史/展示，Phase9.1起不参与下单计费，以merchant_channel+通道为准）',
  `rate_transfer` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '代付费率（历史/展示，Phase9.4.4起不参与提现计费，以merchant_channel为准）',
  `single_min` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '单笔最小金额（0 表示不限）',
  `single_max` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '单笔最大金额（0 表示不限）',
  `auto_disbursement_threshold` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT 'API代付自动下发阈值（元，0=全部自动下发免审核；>0时金额<=该值自动下发、超过转人工）',
  `transfer_self_audit` tinyint(1) NOT NULL DEFAULT 0 COMMENT '代付自审开关（0=平台审核；1=商户门户自助审核下发/拒绝，平台不再管）',
  `ip_whitelist` text NULL COMMENT 'IP 白名单（逗号分隔）',
  `ip_whitelist_status` smallint(6) NOT NULL DEFAULT 2 COMMENT 'IP 白名单开关 (1开启 2关闭)',
  `login_name` varchar(50) NULL DEFAULT NULL COMMENT '商户门户登录名',
  `password` varchar(255) NULL DEFAULT NULL COMMENT '商户门户登录密码（哈希）',
  `status` smallint(6) NOT NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_mch_id`(`mch_id`) USING BTREE,
  UNIQUE INDEX `uk_login_name`(`login_name`) USING BTREE
) ENGINE = InnoDB COMMENT = '支付商户表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 2. 上游通道表 sa_pay_channel
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_channel`;
CREATE TABLE `sa_pay_channel`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(100) NOT NULL COMMENT '通道名称',
  `code` varchar(64) NOT NULL COMMENT '通道编码（唯一）',
  `adapter` varchar(64) NOT NULL COMMENT '适配器标识（对应 service/channel/adapters 类）',
  `transfer_adapter` varchar(64) NULL DEFAULT NULL COMMENT '代付适配器标识（Phase9.5代付能力仅认本字段）',
  `channel_biz` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '通道业务能力：0未配置 1仅代收 2仅代付 3双能力（Phase9.5）',
  `pay_type` smallint(6) NOT NULL DEFAULT 1 COMMENT '支付类型 (1支付宝PC 2支付宝H5 3微信PC 4微信H5 5银联快捷 6银联扫码 7其他)',
  `gateway_url` varchar(255) NULL DEFAULT NULL COMMENT '上游网关地址',
  `upstream_mch_id` varchar(100) NULL DEFAULT NULL COMMENT '上游商户号',
  `upstream_key` varchar(255) NULL DEFAULT NULL COMMENT '上游 MD5 密钥',
  `upstream_public_key` text NULL COMMENT '上游 RSA 公钥',
  `upstream_private_key` text NULL COMMENT '上游 RSA 私钥',
  `rate` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '上游费率（百分数）',
  `rate_self` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '代收平台默认费率（百分数）',
  `rate_transfer_self` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '代付平台默认费率(%)，merchant_channel.rate_transfer=0时继承',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '直连选路权重（越大越优先）；综合路由内仍用 route_channel.weight',
  `money_rule` varchar(255) NULL DEFAULT NULL COMMENT '直连选路金额规则：空=不限；范围(300-10000)或固定池(800+1000)',
  `status` smallint(6) NOT NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_code`(`code`) USING BTREE,
  INDEX `idx_pay_type`(`pay_type`) USING BTREE,
  INDEX `idx_channel_biz`(`channel_biz`) USING BTREE
) ENGINE = InnoDB COMMENT = '上游支付通道表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 3. 商户-通道定制表 sa_pay_merchant_channel
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_merchant_channel`;
CREATE TABLE `sa_pay_merchant_channel`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `channel_id` int(11) UNSIGNED NOT NULL COMMENT '通道ID',
  `rate` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '代收平台费率(%)：0=继承通道rate_self；>0=商户独立费率且须>通道上游成本rate',
  `rate_transfer` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '代付平台费率(%)：0=继承通道rate_transfer_self；>0=商户独立代付费率',
  `day_limit` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '日限额（元，0=不限，Phase9.2运行时接入）',
  `single_min` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '单笔最小金额（元，0=不限，Phase9.2运行时接入）',
  `single_max` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '单笔最大金额（元，0=不限，Phase9.2运行时接入）',
  `status` smallint(6) NOT NULL DEFAULT 1 COMMENT '代收授权 (1已授权 2停用)，无启用绑定则不可代收',
  `transfer_enabled` smallint(6) NOT NULL DEFAULT 2 COMMENT '代付授权 (1已授权 2停用)，与代收status独立',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_merchant_channel`(`merchant_id`, `channel_id`) USING BTREE
) ENGINE = InnoDB COMMENT = '商户-通道授权（代收+代付费率/限额，代收status/代付transfer_enabled独立）' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 3b. 商户-路由授权表 sa_pay_merchant_route（Phase 9.3.1 可选收紧）
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_merchant_route`;
CREATE TABLE `sa_pay_merchant_route`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `route_id` int(11) UNSIGNED NOT NULL COMMENT '路由ID',
  `status` smallint(6) NOT NULL DEFAULT 1 COMMENT '授权状态 (1正常/已授权 2停用)，无启用记录则不收紧路由',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_merchant_route`(`merchant_id`, `route_id`) USING BTREE,
  INDEX `idx_merchant_id`(`merchant_id`) USING BTREE,
  INDEX `idx_route_id`(`route_id`) USING BTREE
) ENGINE = InnoDB COMMENT = '商户-路由授权（代收路由白名单，可选）' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 4. 综合路由表 sa_pay_route
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_route`;
CREATE TABLE `sa_pay_route`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(100) NOT NULL COMMENT '路由名称',
  `pay_type` smallint(6) NOT NULL DEFAULT 1 COMMENT '支付类型 (1支付宝PC 2支付宝H5 3微信PC 4微信H5 5银联快捷 6银联扫码 7其他)',
  `rate` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '历史字段（代收不再使用路由费率，固定0）',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '路由优先级（倒序越大越先遍历；代收选路用）',
  `status` smallint(6) NOT NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_pay_type`(`pay_type`) USING BTREE
) ENGINE = InnoDB COMMENT = '综合路由表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 5. 路由-通道表 sa_pay_route_channel
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_route_channel`;
CREATE TABLE `sa_pay_route_channel`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `route_id` int(11) UNSIGNED NOT NULL COMMENT '路由ID',
  `channel_id` int(11) UNSIGNED NOT NULL COMMENT '通道ID',
  `money_rule` varchar(255) NULL DEFAULT NULL COMMENT '金额规则：范围(300-10000) 或 固定池(300+500+1000)',
  `weight` int(11) NOT NULL DEFAULT 1 COMMENT '权重（命中多通道时按权重分配）',
  `status` smallint(6) NOT NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_route_id`(`route_id`) USING BTREE,
  INDEX `idx_channel_id`(`channel_id`) USING BTREE
) ENGINE = InnoDB COMMENT = '路由-通道关联表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 6. 代收订单表 sa_pay_order
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_order`;
CREATE TABLE `sa_pay_order`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` varchar(32) NOT NULL COMMENT '平台订单号（唯一）',
  `out_trade_no` varchar(64) NOT NULL COMMENT '商户订单号',
  `upstream_no` varchar(64) NULL DEFAULT NULL COMMENT '上游订单号',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余便于查询）',
  `channel_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '命中通道ID',
  `route_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '命中路由ID',
  `pay_type` smallint(6) NOT NULL DEFAULT 1 COMMENT '支付类型 (1-7)',
  `amount` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '订单金额（元）',
  `fee` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '手续费（元）',
  `real_amount` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '实际入账金额=金额-手续费（元）',
  `rate` decimal(8, 4) NOT NULL DEFAULT 0.0000 COMMENT '费率快照（百分数）',
  `rate_source` varchar(32) NOT NULL DEFAULT '' COMMENT '费率来源快照(merchant_channel/route/channel，Phase9.3.4)',
  `merchant_channel_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '费率来源为merchant_channel时的绑定ID快照',
  `status` smallint(6) NOT NULL DEFAULT 0 COMMENT '状态 (0待支付 1已支付 2失败 3已关闭)',
  `settle_status` smallint(6) NOT NULL DEFAULT 0 COMMENT '入账状态 (0未入账 1已入账)',
  `notify_status` smallint(6) NOT NULL DEFAULT 0 COMMENT '商户通知状态 (0未通知 1已通知 2通知失败)',
  `sign_type` smallint(6) NOT NULL DEFAULT 1 COMMENT '商户签名类型 (1MD5 2RSA)',
  `notify_url` varchar(500) NULL DEFAULT NULL COMMENT '商户异步通知地址',
  `return_url` varchar(500) NULL DEFAULT NULL COMMENT '商户同步跳转地址',
  `pay_url` text NULL COMMENT '上游支付链接/二维码内容',
  `commodity_name` varchar(255) NULL DEFAULT NULL COMMENT '商品名称',
  `client_ip` varchar(64) NULL DEFAULT NULL COMMENT '用户端IP',
  `extra` varchar(500) NULL DEFAULT NULL COMMENT '商户透传参数',
  `expire_time` datetime(0) NULL DEFAULT NULL COMMENT '订单过期时间',
  `pay_time` datetime(0) NULL DEFAULT NULL COMMENT '支付成功时间',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_order_no`(`order_no`) USING BTREE,
  UNIQUE INDEX `uk_merchant_out_trade`(`merchant_id`, `out_trade_no`) USING BTREE,
  INDEX `idx_status`(`status`) USING BTREE,
  INDEX `idx_create_time`(`create_time`) USING BTREE,
  INDEX `idx_upstream_no`(`upstream_no`) USING BTREE
) ENGINE = InnoDB COMMENT = '代收订单表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 7. 代付出款表 sa_pay_transfer
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_transfer`;
CREATE TABLE `sa_pay_transfer`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `transfer_no` varchar(32) NOT NULL COMMENT '平台代付单号（唯一）',
  `out_trade_no` varchar(64) NOT NULL COMMENT '商户代付订单号',
  `upstream_no` varchar(64) NULL DEFAULT NULL COMMENT '上游代付订单号',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余）',
  `channel_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '代付通道ID',
  `amount` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '代付金额（元）',
  `fee` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '代付手续费（元）',
  `account_name` varchar(100) NULL DEFAULT NULL COMMENT '收款人姓名',
  `account_no` varchar(64) NULL DEFAULT NULL COMMENT '收款账号/卡号',
  `bank_name` varchar(100) NULL DEFAULT NULL COMMENT '收款银行',
  `bank_code` varchar(32) NULL DEFAULT NULL COMMENT '银行编码',
  `status` smallint(6) NOT NULL DEFAULT 0 COMMENT '状态 (0待处理 1处理中 2成功 3失败)',
  `notify_url` varchar(500) NULL DEFAULT NULL COMMENT '商户异步通知地址',
  `notify_status` smallint(6) NOT NULL DEFAULT 0 COMMENT '商户通知状态 (0未通知 1已通知 2通知失败)',
  `finish_time` datetime(0) NULL DEFAULT NULL COMMENT '完成时间',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_transfer_no`(`transfer_no`) USING BTREE,
  UNIQUE INDEX `uk_merchant_out_trade`(`merchant_id`, `out_trade_no`) USING BTREE,
  INDEX `idx_status`(`status`) USING BTREE
) ENGINE = InnoDB COMMENT = '代付出款表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 8. 商户提现表 sa_pay_withdraw
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_withdraw`;
CREATE TABLE `sa_pay_withdraw`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `withdraw_no` varchar(32) NOT NULL COMMENT '平台提现单号（唯一）',
  `out_biz_no` varchar(64) NULL DEFAULT NULL COMMENT '商户代付单号（API 幂等键）',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余）',
  `source` smallint(6) NOT NULL DEFAULT 1 COMMENT '单据来源 (1商户提现 2API代付)',
  `bank_card_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '收款银行卡ID',
  `account_name` varchar(100) NULL DEFAULT NULL COMMENT '收款人姓名（申请时快照）',
  `account_no` varchar(64) NULL DEFAULT NULL COMMENT '收款银行卡号（申请时快照）',
  `bank_name` varchar(100) NULL DEFAULT NULL COMMENT '开户银行（申请时快照）',
  `bank_code` varchar(32) NULL DEFAULT NULL COMMENT '银行编码（申请时快照）',
  `branch_name` varchar(255) NULL DEFAULT NULL COMMENT '开户支行（申请时快照）',
  `account_phone` varchar(20) NULL DEFAULT NULL COMMENT '收款人手机号（申请时快照，API 直传代付）',
  `notify_url` varchar(500) NULL DEFAULT NULL COMMENT '下游商户异步通知地址（API 代付）',
  `amount` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '提现金额（元）',
  `fee` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '提现手续费（元）',
  `real_amount` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '实际到账金额（元）',
  `status` smallint(6) NOT NULL DEFAULT 0 COMMENT '状态 (0待审核 1审核通过 2代付中 3成功 -1审核拒绝 -2代付失败)',
  `transfer_no` varchar(32) NULL DEFAULT NULL COMMENT '关联代付单号',
  `audit_by` int(11) NULL DEFAULT NULL COMMENT '审核人',
  `audit_time` datetime(0) NULL DEFAULT NULL COMMENT '审核时间',
  `audit_remark` varchar(255) NULL DEFAULT NULL COMMENT '审核备注',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_withdraw_no`(`withdraw_no`) USING BTREE,
  UNIQUE INDEX `uk_merchant_out_biz_no`(`merchant_id`, `out_biz_no`) USING BTREE,
  INDEX `idx_merchant_id`(`merchant_id`) USING BTREE,
  INDEX `idx_status`(`status`) USING BTREE
) ENGINE = InnoDB COMMENT = '商户提现表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 9. 充值表 sa_pay_recharge
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_recharge`;
CREATE TABLE `sa_pay_recharge`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `recharge_no` varchar(32) NOT NULL COMMENT '平台充值单号（唯一）',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余）',
  `amount` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '充值金额（元）',
  `recharge_type` smallint(6) NOT NULL DEFAULT 1 COMMENT '充值方式 (1余额充值 2转卡充值 3在线充值)',
  `status` smallint(6) NOT NULL DEFAULT 0 COMMENT '状态 (0待审核 1通过 -1驳回)',
  `audit_by` int(11) NULL DEFAULT NULL COMMENT '审核人',
  `audit_time` datetime(0) NULL DEFAULT NULL COMMENT '审核时间',
  `audit_remark` varchar(255) NULL DEFAULT NULL COMMENT '审核备注',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_recharge_no`(`recharge_no`) USING BTREE,
  INDEX `idx_merchant_id`(`merchant_id`) USING BTREE
) ENGINE = InnoDB COMMENT = '商户充值表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 10. 商户银行卡表 sa_pay_bank_card
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_bank_card`;
CREATE TABLE `sa_pay_bank_card`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `holder_name` varchar(100) NOT NULL COMMENT '持卡人姓名',
  `card_no` varchar(64) NOT NULL COMMENT '银行卡号',
  `bank_name` varchar(100) NULL DEFAULT NULL COMMENT '开户银行',
  `bank_code` varchar(32) NULL DEFAULT NULL COMMENT '银行编码',
  `branch_name` varchar(255) NULL DEFAULT NULL COMMENT '开户支行',
  `status` smallint(6) NOT NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_merchant_id`(`merchant_id`) USING BTREE
) ENGINE = InnoDB COMMENT = '商户银行卡表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 11. 资金流水表 sa_pay_capital_flow
-- ----------------------------
-- 说明：不可变流水账，idempotent_key 唯一约束是「记账幂等」的最后防线。
DROP TABLE IF EXISTS `sa_pay_capital_flow`;
CREATE TABLE `sa_pay_capital_flow`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `flow_no` varchar(40) NOT NULL COMMENT '流水号',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余）',
  `biz_type` smallint(6) NOT NULL DEFAULT 0 COMMENT '业务类型 (1代收入账 2提现冻结 3提现扣款 4提现退款 5充值 6手续费 7人工调整)',
  `biz_no` varchar(40) NULL DEFAULT NULL COMMENT '关联业务单号（订单号/提现号等）',
  `change_type` smallint(6) NOT NULL DEFAULT 1 COMMENT '账户类型 (1可用余额 2冻结余额)',
  `change_amount` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '变动金额（元，正数增、负数减）',
  `before_balance` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '变动前余额（元）',
  `after_balance` decimal(16, 4) NOT NULL DEFAULT 0.0000 COMMENT '变动后余额（元）',
  `idempotent_key` varchar(64) NOT NULL COMMENT '幂等键（唯一，防重复记账）',
  `remark` varchar(255) NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_idempotent_key`(`idempotent_key`) USING BTREE,
  INDEX `idx_merchant_id`(`merchant_id`) USING BTREE,
  INDEX `idx_biz_no`(`biz_no`) USING BTREE
) ENGINE = InnoDB COMMENT = '资金流水表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 12. 商户通知日志表 sa_pay_notify_log
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_notify_log`;
CREATE TABLE `sa_pay_notify_log`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` varchar(32) NOT NULL COMMENT '关联平台单号',
  `merchant_id` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `biz_type` smallint(6) NOT NULL DEFAULT 1 COMMENT '通知类型 (1代收 2代付)',
  `notify_url` varchar(500) NULL DEFAULT NULL COMMENT '通知地址',
  `request_body` text NULL COMMENT '通知请求内容',
  `response_body` text NULL COMMENT '商户响应内容',
  `http_code` int(11) NULL DEFAULT NULL COMMENT 'HTTP 状态码',
  `retry_num` int(11) NOT NULL DEFAULT 0 COMMENT '已重试次数',
  `status` smallint(6) NOT NULL DEFAULT 0 COMMENT '状态 (0待通知 1成功 2失败)',
  `next_notify_time` datetime(0) NULL DEFAULT NULL COMMENT '下次重试时间',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_order_no`(`order_no`) USING BTREE,
  INDEX `idx_status`(`status`) USING BTREE
) ENGINE = InnoDB COMMENT = '商户通知日志表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- 13. 上游交互日志表 sa_pay_channel_log
-- ----------------------------
DROP TABLE IF EXISTS `sa_pay_channel_log`;
CREATE TABLE `sa_pay_channel_log`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `channel_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '通道ID',
  `biz_no` varchar(40) NULL DEFAULT NULL COMMENT '关联业务单号',
  `type` smallint(6) NOT NULL DEFAULT 1 COMMENT '交互类型 (1下单 2回调 3查单 4代付)',
  `request` text NULL COMMENT '请求内容',
  `response` text NULL COMMENT '响应内容',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime(0) NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime(0) NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime(0) NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_biz_no`(`biz_no`) USING BTREE,
  INDEX `idx_channel_id`(`channel_id`) USING BTREE
) ENGINE = InnoDB COMMENT = '上游交互日志表' ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;
