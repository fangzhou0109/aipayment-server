-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2026-06-11 17:40:13
-- 服务器版本： 8.0.45
-- PHP 版本： 8.3.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `saipayment`
--

-- --------------------------------------------------------

--
-- 表的结构 `sa_article`
--

CREATE TABLE `sa_article` (
  `id` int NOT NULL COMMENT '编号',
  `category_id` int NOT NULL COMMENT '分类id',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '文章标题',
  `author` varchar(255) DEFAULT NULL COMMENT '文章作者',
  `image` varchar(1000) DEFAULT '' COMMENT '文章图片',
  `describe` varchar(1000) NOT NULL COMMENT '文章简介',
  `content` text NOT NULL COMMENT '文章内容',
  `views` int DEFAULT '0' COMMENT '浏览次数',
  `sort` int UNSIGNED DEFAULT '100' COMMENT '排序',
  `status` tinyint UNSIGNED DEFAULT '1' COMMENT '状态',
  `is_link` tinyint(1) DEFAULT '2' COMMENT '是否外链',
  `link_url` varchar(255) DEFAULT NULL COMMENT '链接地址',
  `is_hot` tinyint UNSIGNED DEFAULT '2' COMMENT '是否热门',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='文章表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_article_banner`
--

CREATE TABLE `sa_article_banner` (
  `id` int NOT NULL COMMENT '编号',
  `banner_type` int DEFAULT NULL COMMENT '类型',
  `image` varchar(1000) DEFAULT NULL COMMENT '图片地址',
  `is_href` tinyint(1) DEFAULT '1' COMMENT '是否链接',
  `url` varchar(255) DEFAULT NULL COMMENT '链接地址',
  `title` varchar(255) DEFAULT NULL COMMENT '标题',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `sort` int DEFAULT '0' COMMENT '排序',
  `remark` varchar(255) DEFAULT NULL COMMENT '描述',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='文章轮播图' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_article_category`
--

CREATE TABLE `sa_article_category` (
  `id` int UNSIGNED NOT NULL COMMENT '编号',
  `parent_id` int NOT NULL DEFAULT '0' COMMENT '父级ID',
  `category_name` varchar(255) NOT NULL COMMENT '分类标题',
  `describe` varchar(255) DEFAULT NULL COMMENT '分类简介',
  `image` varchar(255) DEFAULT NULL COMMENT '分类图片',
  `sort` int UNSIGNED DEFAULT '100' COMMENT '排序',
  `status` tinyint UNSIGNED DEFAULT '1' COMMENT '状态',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='文章分类表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_bank_card`
--

CREATE TABLE `sa_pay_bank_card` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `holder_name` varchar(100) NOT NULL COMMENT '持卡人姓名',
  `card_no` varchar(64) NOT NULL COMMENT '银行卡号',
  `bank_name` varchar(100) DEFAULT NULL COMMENT '开户银行',
  `bank_code` varchar(32) DEFAULT NULL COMMENT '银行编码',
  `branch_name` varchar(255) DEFAULT NULL COMMENT '开户支行',
  `status` smallint NOT NULL DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='商户银行卡表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_capital_flow`
--

CREATE TABLE `sa_pay_capital_flow` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `flow_no` varchar(40) NOT NULL COMMENT '流水号',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余）',
  `biz_type` smallint NOT NULL DEFAULT '0' COMMENT '业务类型 (1代收入账 2提现冻结 3提现扣款 4提现退款 5充值 6手续费 7人工调整)',
  `biz_no` varchar(40) DEFAULT NULL COMMENT '关联业务单号（订单号/提现号等）',
  `change_type` smallint NOT NULL DEFAULT '1' COMMENT '账户类型 (1可用余额 2冻结余额)',
  `change_amount` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '变动金额（元，正数增、负数减）',
  `before_balance` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '变动前余额（元）',
  `after_balance` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '变动后余额（元）',
  `idempotent_key` varchar(64) NOT NULL COMMENT '幂等键（唯一，防重复记账）',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='资金流水表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_channel`
--

CREATE TABLE `sa_pay_channel` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `title` varchar(100) NOT NULL COMMENT '通道名称',
  `code` varchar(64) NOT NULL COMMENT '通道编码（唯一）',
  `adapter` varchar(64) NOT NULL COMMENT '适配器标识（对应 service/channel/adapters 类）',
  `transfer_adapter` varchar(64) DEFAULT NULL COMMENT '代付适配器标识（空则回退 adapter）',
  `channel_biz` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT '通道业务能力：0未配置 1仅代收 2仅代付 3双能力（Phase9.5）',
  `pay_type` smallint NOT NULL DEFAULT '1' COMMENT '支付类型 (1支付宝PC 2支付宝H5 3微信PC 4微信H5 5银联快捷 6银联扫码 7其他)',
  `gateway_url` varchar(255) DEFAULT NULL COMMENT '上游网关地址',
  `upstream_mch_id` varchar(100) DEFAULT NULL COMMENT '上游商户号',
  `upstream_key` varchar(255) DEFAULT NULL COMMENT '上游 MD5 密钥',
  `upstream_public_key` text COMMENT '上游 RSA 公钥',
  `upstream_private_key` text COMMENT '上游 RSA 私钥',
  `rate` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '上游费率（百分数）',
  `rate_self` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '平台默认费率（百分数）',
  `rate_transfer_self` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '代付平台默认费率(%)，merchant_channel.rate_transfer=0时继承',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序（倒序优先）',
  `money_rule` varchar(255) DEFAULT NULL COMMENT '直连选路金额规则：空=不限；范围(300-10000)或固定池(800+1000)',
  `status` smallint NOT NULL DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='上游支付通道表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_pay_channel`
--

INSERT INTO `sa_pay_channel` (`id`, `title`, `code`, `adapter`, `transfer_adapter`, `channel_biz`, `pay_type`, `gateway_url`, `upstream_mch_id`, `upstream_key`, `upstream_public_key`, `upstream_private_key`, `rate`, `rate_self`, `rate_transfer_self`, `sort`, `money_rule`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 'Mock测试通道', 'mock_test_001', 'mock', NULL, 1, 3, '', 'admin', '', '', '', 2.0000, 3.5000, 0.0000, 0, '1+10', 1, '', NULL, 1, '2026-06-08 15:28:41', '2026-06-09 23:14:13', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_channel_log`
--

CREATE TABLE `sa_pay_channel_log` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `channel_id` int UNSIGNED DEFAULT NULL COMMENT '通道ID',
  `biz_no` varchar(40) DEFAULT NULL COMMENT '关联业务单号',
  `type` smallint NOT NULL DEFAULT '1' COMMENT '交互类型 (1下单 2回调 3查单 4代付)',
  `request` text COMMENT '请求内容',
  `response` text COMMENT '响应内容',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='上游交互日志表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_merchant`
--

CREATE TABLE `sa_pay_merchant` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（对外唯一标识）',
  `name` varchar(100) DEFAULT NULL COMMENT '商户名称',
  `avatar` varchar(500) DEFAULT NULL COMMENT '商户门户头像URL',
  `secret_key` varchar(64) DEFAULT NULL COMMENT 'MD5 签名密钥',
  `rsa_public_key` text COMMENT '商户 RSA 公钥（验商户来签）',
  `rsa_private_key` text COMMENT '平台 RSA 私钥（对该商户回调签名用）',
  `balance` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '可用余额（元）',
  `balance_freeze` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '冻结余额（元）',
  `rate` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '代收费率（历史/展示，Phase9.1起不参与下单计费，以 merchant_channel+通道为准）',
  `rate_transfer` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '代付默认费率（百分数）',
  `single_min` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '单笔最小金额（0 表示不限）',
  `single_max` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '单笔最大金额（0 表示不限）',
  `auto_disbursement_threshold` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT 'API代付自动下发阈值（元，0=全部自动下发免审核；>0时金额<=该值自动下发、超过转人工）',
  `transfer_self_audit` tinyint(1) NOT NULL DEFAULT '0' COMMENT '代付自审开关（0=平台审核；1=商户门户自助审核下发/拒绝，平台不再管）',
  `ip_whitelist` text COMMENT 'IP 白名单（逗号分隔）',
  `ip_whitelist_status` smallint NOT NULL DEFAULT '2' COMMENT 'IP 白名单开关 (1开启 2关闭)',
  `login_name` varchar(50) DEFAULT NULL COMMENT '商户门户登录名',
  `password` varchar(255) DEFAULT NULL COMMENT '商户门户登录密码（哈希）',
  `status` smallint NOT NULL DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='支付商户表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_pay_merchant`
--

INSERT INTO `sa_pay_merchant` (`id`, `mch_id`, `name`, `avatar`, `secret_key`, `rsa_public_key`, `rsa_private_key`, `balance`, `balance_freeze`, `rate`, `rate_transfer`, `single_min`, `single_max`, `ip_whitelist`, `ip_whitelist_status`, `login_name`, `password`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 'TEST_M001', '测试商户', 'https://api.starfusionx.com/storage/20260610/9d61daca475a7d2e2798bb9de5515d4398a2d474.png', '5153c07228892d6398fefba54c24e1dc', '-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwGi7egDmps/0fABqR/Yo\nx7o+FIHFJ8cwSKAb61oklyuxi6ND0dvi68cfRZD4Y6KxhqhkYXfvbGN2eo6hPkfM\nsCSWnqhwmSwpuPxgYnnnCI2omt4uQUpOb3aUj71bDoJOdSc4pZ+kvutJEU5kxZqy\nNBgjpr49y9ZBFhJUPo/Ym2VB4wnhtknPUU4nUSkmgatSqTo6xIhPP4SCAsWcQuzC\nGPgcUtTFbD5ayg4ZuIik85nBvZSDNM3UeBJHliqg259svpnDxVrRfumYPwFhcYIG\nO2+EGLliEcZfopzVgJe0tdx/OjZN8E+6WWPMSHzQB/3W7p+iCPYMWR2jeYKyPPkx\nXwIDAQAB\n-----END PUBLIC KEY-----', '-----BEGIN PRIVATE KEY-----\nMIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDAaLt6AOamz/R8\nAGpH9ijHuj4UgcUnxzBIoBvrWiSXK7GLo0PR2+Lrxx9FkPhjorGGqGRhd+9sY3Z6\njqE+R8ywJJaeqHCZLCm4/GBieecIjaia3i5BSk5vdpSPvVsOgk51Jziln6S+60kR\nTmTFmrI0GCOmvj3L1kEWElQ+j9ibZUHjCeG2Sc9RTidRKSaBq1KpOjrEiE8/hIIC\nxZxC7MIY+BxS1MVsPlrKDhm4iKTzmcG9lIM0zdR4EkeWKqDbn2y+mcPFWtF+6Zg/\nAWFxggY7b4QYuWIRxl+inNWAl7S13H86Nk3wT7pZY8xIfNAH/dbun6II9gxZHaN5\ngrI8+TFfAgMBAAECggEADm4MWVsF8U65RC93yQvSBSCXlUwiMBiFK30uetkY02mI\neDN3W57FBu+5DauQpVBHRhUM6i5ne1Z+RTS7LQOPe0pTLXTudN5WBrSOufPweri9\nA1hUWbsr5Loc7CbEVHM7VRfq7KjrXxIgObcKYbN3V+bTjabI1aes2+3l+YcqNIUj\nPmaJOpgMz0bQYRfwId8unnUSlJbydEFvKBq0zEDyWx7LdVJlDSCn5X1i8tTeyjQi\nMZWZu2rIvlipRtYPnerL06eBhuUDloraI1oqWcjFu2qBBWp1mdeS8g+uk9OeXOJb\nvdNoyB6dUR2vQtOhbh3la5VB0XOVLQGc27T1cyzJIQKBgQDjNHYZi74ubGAggXWy\nWZGvohISYsXWdYplUuvcMi+OKxIQgHV9DRxpzhovkHRiRgBp54lYZ8DtXKa9j/f7\nmNhXopAEIyBlf9B6AMyVPo/6dUNc34s3VhNzrV2ysm5+Z85Q6wYIEMhrS7Hm6g/b\nOD4Au6DJkS6s4MmZE/sjMgp8rwKBgQDYy1cEeXC7O4PyS2MtpJFoZgVCLog87qam\nUhqaC4s4nQ3chahxfe4qypz2EqEehudLWaydmK15Td6q6pMwOdl7YcNfJoyWasm0\nV7ZZgmvmKJoH30F9YIpOXc0xGjIOiZqEwvd32P9Ke+hbk4Ge745726o852CGCOBd\nqiK2kY+iUQKBgHGmNkUE/7adA2B/IW57G0KtYTjNK4Tg+r9AQTa968fDh5+1gg9x\nVXsfWz3bljvqJB7VcIBGNd0FcWp072hsxhrf+AX5xCTBUHkWmT82MjLoIS/9qdee\nONCuMaZHVrnoFu2nAjdancX98Rk+j3vqoCkhsYXiF2TmdDEcK40pZNGFAoGANwBn\nxE/XJPl1gVxU/jh5V6ZCgUby85qMlzfPXfO9z5Aw+xjB4oFTknGzHs8dJ3SMa9aF\nb7pTkKoL6wr2as7SeXYVLifGlUbkg6eZMN5g55S3d3XR1LBQho9PxrxhpMSOek5I\noJRiOJB1I/6pbRxT5uKVLzx4hajs23aVtgH6EfECgYA0VgTDljU3Za5e7QYR+y3u\nEJ9TZOkJMGFB3Of++4SHIxjuhdTofqmM1Dl8pLjvd1ntapQMRiLWLLPsZdwhVZ9P\nhhIEE3wiwIByGNJrCjg0XCKPrQBbYplTmKHmCMnt1bOa7Eyu9Mik6up7gPI/K4Ba\nUTiHFTLJw1z+CW5P2YAg7g==\n-----END PRIVATE KEY-----\n', 0.0000, 0.0000, 2.6000, 0.0000, 0.0000, 0.0000, '', 2, 'test1', '$2y$10$x606y08A0S9hNh6aeDMduOJC1jJNfoTjzYYXMtrAY5ZH4cZLYPBz2', 1, '', NULL, 1, '2026-06-08 15:28:41', '2026-06-10 01:26:19', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_merchant_channel`
--

CREATE TABLE `sa_pay_merchant_channel` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `channel_id` int UNSIGNED NOT NULL COMMENT '通道ID',
  `rate` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '平台费率(%)：0=继承通道 rate_self；>0=商户独立费率且须>通道上游成本 rate',
  `rate_transfer` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '代付平台费率(%)：0=继承通道rate_transfer_self；>0=商户独立代付费率',
  `day_limit` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '日限额(元，0=不限，Phase9.2运行时接入)',
  `single_min` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '单笔最小金额（元，0=不限，Phase9.2运行时接入）',
  `single_max` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '单笔最大金额（元，0=不限，Phase9.2运行时接入）',
  `status` smallint NOT NULL DEFAULT '1' COMMENT '授权状态 (1正常/已授权 2停用)，Phase9.1严格模式：无启用绑定则不可代收',
  `transfer_enabled` smallint NOT NULL DEFAULT '2' COMMENT '代付授权 (1已授权 2停用)，与代收status独立',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='商户-通道授权（代收+代付费率/限额，代收status/代付transfer_enabled独立）' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_merchant_route`
--

CREATE TABLE `sa_pay_merchant_route` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `route_id` int UNSIGNED NOT NULL COMMENT '路由ID',
  `status` smallint NOT NULL DEFAULT '1' COMMENT '授权状态 (1正常/已授权 2停用)，无启用记录则不收紧路由',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='商户-路由授权（代收路由白名单，可选）' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_notify_log`
--

CREATE TABLE `sa_pay_notify_log` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `order_no` varchar(32) NOT NULL COMMENT '关联平台单号',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `biz_type` smallint NOT NULL DEFAULT '1' COMMENT '通知类型 (1代收 2代付)',
  `notify_url` varchar(500) DEFAULT NULL COMMENT '通知地址',
  `request_body` text COMMENT '通知请求内容',
  `response_body` text COMMENT '商户响应内容',
  `http_code` int DEFAULT NULL COMMENT 'HTTP 状态码',
  `retry_num` int NOT NULL DEFAULT '0' COMMENT '已重试次数',
  `status` smallint NOT NULL DEFAULT '0' COMMENT '状态 (0待通知 1成功 2失败)',
  `next_notify_time` datetime DEFAULT NULL COMMENT '下次重试时间',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='商户通知日志表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_order`
--

CREATE TABLE `sa_pay_order` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `order_no` varchar(32) NOT NULL COMMENT '平台订单号（唯一）',
  `out_trade_no` varchar(64) NOT NULL COMMENT '商户订单号',
  `upstream_no` varchar(64) DEFAULT NULL COMMENT '上游订单号',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余便于查询）',
  `channel_id` int UNSIGNED DEFAULT NULL COMMENT '命中通道ID',
  `route_id` int UNSIGNED DEFAULT NULL COMMENT '命中路由ID',
  `pay_type` smallint NOT NULL DEFAULT '1' COMMENT '支付类型 (1-7)',
  `amount` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '订单金额（元）',
  `fee` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '手续费（元）',
  `real_amount` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '实际入账金额=金额-手续费（元）',
  `rate` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '费率快照（百分数）',
  `rate_source` varchar(32) NOT NULL DEFAULT '' COMMENT '费率来源快照(merchant_channel/route/channel)',
  `merchant_channel_id` int UNSIGNED DEFAULT NULL COMMENT '费率来源为merchant_channel时的绑定ID快照',
  `status` smallint NOT NULL DEFAULT '0' COMMENT '状态 (0待支付 1已支付 2失败 3已关闭)',
  `settle_status` smallint NOT NULL DEFAULT '0' COMMENT '入账状态 (0未入账 1已入账)',
  `notify_status` smallint NOT NULL DEFAULT '0' COMMENT '商户通知状态 (0未通知 1已通知 2通知失败)',
  `sign_type` smallint NOT NULL DEFAULT '1' COMMENT '商户签名类型 (1MD5 2RSA)',
  `notify_url` varchar(500) DEFAULT NULL COMMENT '商户异步通知地址',
  `return_url` varchar(500) DEFAULT NULL COMMENT '商户同步跳转地址',
  `pay_url` text COMMENT '上游支付链接/二维码内容',
  `commodity_name` varchar(255) DEFAULT NULL COMMENT '商品名称',
  `client_ip` varchar(64) DEFAULT NULL COMMENT '用户端IP',
  `extra` varchar(500) DEFAULT NULL COMMENT '商户透传参数',
  `expire_time` datetime DEFAULT NULL COMMENT '订单过期时间',
  `pay_time` datetime DEFAULT NULL COMMENT '支付成功时间',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='代收订单表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_recharge`
--

CREATE TABLE `sa_pay_recharge` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `recharge_no` varchar(32) NOT NULL COMMENT '平台充值单号（唯一）',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余）',
  `amount` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '充值金额（元）',
  `recharge_type` smallint NOT NULL DEFAULT '1' COMMENT '充值方式 (1余额充值 2转卡充值 3在线充值)',
  `status` smallint NOT NULL DEFAULT '0' COMMENT '状态 (0待审核 1通过 -1驳回)',
  `audit_by` int DEFAULT NULL COMMENT '审核人',
  `audit_time` datetime DEFAULT NULL COMMENT '审核时间',
  `audit_remark` varchar(255) DEFAULT NULL COMMENT '审核备注',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='商户充值表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_route`
--

CREATE TABLE `sa_pay_route` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `title` varchar(100) NOT NULL COMMENT '路由名称',
  `pay_type` smallint NOT NULL DEFAULT '1' COMMENT '支付类型 (1支付宝PC 2支付宝H5 3微信PC 4微信H5 5银联快捷 6银联扫码 7其他)',
  `rate` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '路由费率（百分数）',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序',
  `status` smallint NOT NULL DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='综合路由表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_pay_route`
--

INSERT INTO `sa_pay_route` (`id`, `title`, `pay_type`, `rate`, `sort`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 'Mock路由PT3', 3, 0.0000, 0, 2, '', NULL, 1, '2026-06-08 15:28:41', '2026-06-09 22:56:32', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_route_channel`
--

CREATE TABLE `sa_pay_route_channel` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `route_id` int UNSIGNED NOT NULL COMMENT '路由ID',
  `channel_id` int UNSIGNED NOT NULL COMMENT '通道ID',
  `money_rule` varchar(255) DEFAULT NULL COMMENT '金额规则：范围(300-10000) 或 固定池(300+500+1000)',
  `weight` int NOT NULL DEFAULT '1' COMMENT '权重（命中多通道时按权重分配）',
  `status` smallint NOT NULL DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='路由-通道关联表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_transfer`
--

CREATE TABLE `sa_pay_transfer` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `transfer_no` varchar(32) NOT NULL COMMENT '平台代付单号（唯一）',
  `out_trade_no` varchar(64) NOT NULL COMMENT '商户代付订单号',
  `upstream_no` varchar(64) DEFAULT NULL COMMENT '上游代付订单号',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余）',
  `channel_id` int UNSIGNED DEFAULT NULL COMMENT '代付通道ID',
  `amount` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '代付金额（元）',
  `fee` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '代付手续费（元）',
  `account_name` varchar(100) DEFAULT NULL COMMENT '收款人姓名',
  `account_no` varchar(64) DEFAULT NULL COMMENT '收款账号/卡号',
  `bank_name` varchar(100) DEFAULT NULL COMMENT '收款银行',
  `bank_code` varchar(32) DEFAULT NULL COMMENT '银行编码',
  `status` smallint NOT NULL DEFAULT '0' COMMENT '状态 (0待处理 1处理中 2成功 3失败)',
  `notify_url` varchar(500) DEFAULT NULL COMMENT '商户异步通知地址',
  `notify_status` smallint NOT NULL DEFAULT '0' COMMENT '商户通知状态 (0未通知 1已通知 2通知失败)',
  `finish_time` datetime DEFAULT NULL COMMENT '完成时间',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='代付出款表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_pay_withdraw`
--

CREATE TABLE `sa_pay_withdraw` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `withdraw_no` varchar(32) NOT NULL COMMENT '平台提现单号（唯一）',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `mch_id` varchar(32) NOT NULL COMMENT '商户号（冗余）',
  `bank_card_id` int UNSIGNED DEFAULT NULL COMMENT '收款银行卡ID',
  `account_name` varchar(100) DEFAULT NULL COMMENT '收款人姓名（申请时快照）',
  `account_no` varchar(64) DEFAULT NULL COMMENT '收款银行卡号（申请时快照）',
  `bank_name` varchar(100) DEFAULT NULL COMMENT '开户银行（申请时快照）',
  `bank_code` varchar(32) DEFAULT NULL COMMENT '银行编码（申请时快照）',
  `branch_name` varchar(255) DEFAULT NULL COMMENT '开户支行（申请时快照）',
  `account_phone` varchar(20) DEFAULT NULL COMMENT '收款人手机号（申请时快照，API 直传代付）',
  `amount` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '提现金额（元）',
  `fee` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '提现手续费（元）',
  `real_amount` decimal(16,4) NOT NULL DEFAULT '0.0000' COMMENT '实际到账金额（元）',
  `status` smallint NOT NULL DEFAULT '0' COMMENT '状态 (0待审核 1审核通过 2代付中 3成功 -1审核拒绝 -2代付失败)',
  `transfer_no` varchar(32) DEFAULT NULL COMMENT '关联代付单号',
  `audit_by` int DEFAULT NULL COMMENT '审核人',
  `audit_time` datetime DEFAULT NULL COMMENT '审核时间',
  `audit_remark` varchar(255) DEFAULT NULL COMMENT '审核备注',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='商户提现表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_attachment`
--

CREATE TABLE `sa_system_attachment` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `category_id` int DEFAULT '0' COMMENT '文件分类',
  `storage_mode` smallint DEFAULT '1' COMMENT '存储模式 (1 本地 2 阿里云 3 七牛云 4 腾讯云)',
  `origin_name` varchar(255) DEFAULT NULL COMMENT '原文件名',
  `object_name` varchar(50) DEFAULT NULL COMMENT '新文件名',
  `hash` varchar(64) DEFAULT NULL COMMENT '文件hash',
  `mime_type` varchar(255) DEFAULT NULL COMMENT '资源类型',
  `storage_path` varchar(100) DEFAULT NULL COMMENT '存储目录',
  `suffix` varchar(10) DEFAULT NULL COMMENT '文件后缀',
  `size_byte` bigint DEFAULT NULL COMMENT '字节数',
  `size_info` varchar(50) DEFAULT NULL COMMENT '文件大小',
  `url` varchar(255) DEFAULT NULL COMMENT 'url地址',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='附件信息表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_attachment`
--

INSERT INTO `sa_system_attachment` (`id`, `category_id`, `storage_mode`, `origin_name`, `object_name`, `hash`, `mime_type`, `storage_path`, `suffix`, `size_byte`, `size_info`, `url`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 1, 1, 'logo.svg', '4d5daced6849ac60b69cb0238740e9618369467b.svg', '4d5daced6849ac60b69cb0238740e9618369467b', 'image/svg+xml', 'public/storage/20260610/4d5daced6849ac60b69cb0238740e9618369467b.svg', 'svg', 219123, '213.99 KB', 'https://api.starfusionx.com/storage/20260610/4d5daced6849ac60b69cb0238740e9618369467b.svg', NULL, 1, 1, '2026-06-10 15:29:22', '2026-06-10 15:29:22', NULL),
(2, 1, 1, 'jiedan_1.png', 'afaccfa414e42eff8fdd166ec76d43d4d9c7775b.png', 'afaccfa414e42eff8fdd166ec76d43d4d9c7775b', 'image/png', 'public/storage/20260610/afaccfa414e42eff8fdd166ec76d43d4d9c7775b.png', 'png', 14991, '14.64 KB', 'https://api.starfusionx.com/storage/20260610/afaccfa414e42eff8fdd166ec76d43d4d9c7775b.png', NULL, 1, 1, '2026-06-10 15:32:27', '2026-06-10 15:32:27', NULL),
(3, 1, 1, 'ChatGPT Image 2026年6月10日 17_26_58.png', '9d61daca475a7d2e2798bb9de5515d4398a2d474.png', '9d61daca475a7d2e2798bb9de5515d4398a2d474', 'image/png', 'public/storage/20260610/9d61daca475a7d2e2798bb9de5515d4398a2d474.png', 'png', 1152341, '1.1 MB', 'https://api.starfusionx.com/storage/20260610/9d61daca475a7d2e2798bb9de5515d4398a2d474.png', NULL, 1, 1, '2026-06-10 17:50:45', '2026-06-10 17:50:45', NULL),
(4, 1, 1, 'ChatGPT Image 2026年6月10日 17_26_58.png', '9d61daca475a7d2e2798bb9de5515d4398a2d474.png', '9d61daca475a7d2e2798bb9de5515d4398a2d474', 'image/png', 'public/storage/20260610/9d61daca475a7d2e2798bb9de5515d4398a2d474.png', 'png', 1152341, '1.1 MB', 'https://api.starfusionx.com/storage/20260610/9d61daca475a7d2e2798bb9de5515d4398a2d474.png', NULL, 1, 1, '2026-06-10 23:38:06', '2026-06-10 23:38:06', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_category`
--

CREATE TABLE `sa_system_category` (
  `id` int NOT NULL COMMENT '分类ID',
  `parent_id` int NOT NULL DEFAULT '0' COMMENT '父id',
  `level` varchar(255) DEFAULT NULL COMMENT '组集关系',
  `category_name` varchar(100) NOT NULL DEFAULT '' COMMENT '分类名称',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='附件分类表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_category`
--

INSERT INTO `sa_system_category` (`id`, `parent_id`, `level`, `category_name`, `sort`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 0, '0,', '全部分类', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(2, 1, '0,1,', '图片分类', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(3, 1, '0,1,', '文件分类', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(4, 1, '0,1,', '系统图片', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(5, 1, '0,1,', '其他分类', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_config`
--

CREATE TABLE `sa_system_config` (
  `id` int UNSIGNED NOT NULL COMMENT '编号',
  `group_id` int DEFAULT NULL COMMENT '组id',
  `key` varchar(32) NOT NULL COMMENT '配置键名',
  `value` text COMMENT '配置值',
  `name` varchar(255) DEFAULT NULL COMMENT '配置名称',
  `input_type` varchar(32) DEFAULT NULL COMMENT '数据输入类型',
  `config_select_data` varchar(500) DEFAULT NULL COMMENT '配置选项数据',
  `sort` smallint UNSIGNED DEFAULT '0' COMMENT '排序',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建人',
  `updated_by` int DEFAULT NULL COMMENT '更新人',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='参数配置信息表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_config`
--

INSERT INTO `sa_system_config` (`id`, `group_id`, `key`, `value`, `name`, `input_type`, `config_select_data`, `sort`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 1, 'site_copyright', 'Copyright © 2024 LQPAY', '版权信息', 'textarea', NULL, 96, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:38:55', NULL),
(2, 1, 'site_desc', 'LQPAY', '网站描述', 'textarea', NULL, 97, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:38:55', NULL),
(3, 1, 'site_keywords', 'LQPAY', '网站关键字', 'input', NULL, 98, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:38:55', NULL),
(4, 1, 'site_name', 'LQPAY', '网站名称', 'input', NULL, 99, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:38:55', NULL),
(5, 1, 'site_record_number', '', '网站备案号', 'input', NULL, 95, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:38:55', NULL),
(6, 2, 'upload_allow_file', 'txt,doc,docx,xls,xlsx,ppt,pptx,rar,zip,7z,gz,pdf,wps,md,jpg,png,jpeg,mp4,pem,crt', '文件类型', 'input', NULL, 0, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(7, 2, 'upload_allow_image', 'jpg,jpeg,png,gif,svg,bmp', '图片类型', 'input', NULL, 0, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(8, 2, 'upload_mode', '1', '上传模式', 'select', '[{\"label\":\"本地上传\",\"value\":\"1\"},{\"label\":\"阿里云OSS\",\"value\":\"2\"},{\"label\":\"七牛云\",\"value\":\"3\"},{\"label\":\"腾讯云COS\",\"value\":\"4\"},{\"label\":\"亚马逊S3\",\"value\":\"5\"}]', 99, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(10, 2, 'upload_size', '52428800', '上传大小', 'input', NULL, 88, '单位Byte,1MB=1024*1024Byte', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(11, 2, 'local_root', 'public/storage/', '本地存储路径', 'input', NULL, 0, '本地存储文件路径', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(12, 2, 'local_domain', 'https://api.starfusionx.com', '本地存储域名', 'input', NULL, 0, 'http://127.0.0.1:8787', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(13, 2, 'local_uri', '/storage/', '本地访问路径', 'input', NULL, 0, '访问是通过domain + uri', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(14, 2, 'qiniu_accessKey', '', '七牛key', 'input', NULL, 0, '七牛云存储secretId', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(15, 2, 'qiniu_secretKey', '', '七牛secret', 'input', NULL, 0, '七牛云存储secretKey', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(16, 2, 'qiniu_bucket', '', '七牛bucket', 'input', NULL, 0, '七牛云存储bucket', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(17, 2, 'qiniu_dirname', '', '七牛dirname', 'input', NULL, 0, '七牛云存储dirname', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(18, 2, 'qiniu_domain', '', '七牛domain', 'input', NULL, 0, '七牛云存储domain', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(19, 2, 'cos_secretId', '', '腾讯Id', 'input', NULL, 0, '腾讯云存储secretId', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(20, 2, 'cos_secretKey', '', '腾讯key', 'input', NULL, 0, '腾讯云secretKey', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(21, 2, 'cos_bucket', '', '腾讯bucket', 'input', NULL, 0, '腾讯云存储bucket', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(22, 2, 'cos_dirname', '', '腾讯dirname', 'input', NULL, 0, '腾讯云存储dirname', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(23, 2, 'cos_domain', '', '腾讯domain', 'input', NULL, 0, '腾讯云存储domain', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(24, 2, 'cos_region', '', '腾讯region', 'input', NULL, 0, '腾讯云存储region', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(25, 2, 'oss_accessKeyId', '', '阿里Id', 'input', NULL, 0, '阿里云存储accessKeyId', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(26, 2, 'oss_accessKeySecret', '', '阿里Secret', 'input', NULL, 0, '阿里云存储accessKeySecret', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(27, 2, 'oss_bucket', '', '阿里bucket', 'input', NULL, 0, '阿里云存储bucket', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(28, 2, 'oss_dirname', '', '阿里dirname', 'input', NULL, 0, '阿里云存储dirname', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(29, 2, 'oss_domain', '', '阿里domain', 'input', NULL, 0, '阿里云存储domain', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(30, 2, 'oss_endpoint', '', '阿里endpoint', 'input', NULL, 0, '阿里云存储endpoint', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(31, 3, 'Host', 'smtp.qq.com', 'SMTP服务器', 'input', '', 100, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(32, 3, 'Port', '465', 'SMTP端口', 'input', '', 100, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(33, 3, 'Username', '', 'SMTP用户名', 'input', '', 100, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(34, 3, 'Password', '', 'SMTP密码', 'input', '', 100, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(35, 3, 'SMTPSecure', 'ssl', 'SMTP验证方式', 'radio', '[\r\n    {\"label\":\"ssl\",\"value\":\"ssl\"},\r\n    {\"label\":\"tsl\",\"value\":\"tsl\"}\r\n]', 100, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(36, 3, 'From', '', '默认发件人', 'input', '', 100, '默认发件的邮箱地址', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(37, 3, 'FromName', '账户注册', '默认发件名称', 'input', '', 100, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(38, 3, 'CharSet', 'UTF-8', '编码', 'input', '', 100, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(39, 3, 'SMTPDebug', '0', '调试模式', 'radio', '[\r\n    {\"label\":\"关闭\",\"value\":\"0\"},\r\n    {\"label\":\"client\",\"value\":\"1\"},\r\n    {\"label\":\"server\",\"value\":\"2\"}\r\n]', 100, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(40, 2, 's3_key', '', 'key', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(41, 2, 's3_secret', '', 'secret', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(42, 2, 's3_bucket', '', 'bucket', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(43, 2, 's3_dirname', '', 'dirname', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(44, 2, 's3_domain', '', 'domain', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(45, 2, 's3_region', '', 'region', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(46, 2, 's3_version', '', 'version', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(47, 2, 's3_use_path_style_endpoint', '', 'path_style_endpoint', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(48, 2, 's3_endpoint', '', 'endpoint', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL),
(49, 2, 's3_acl', '', 'acl', 'input', '', 0, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 15:28:28', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_config_group`
--

CREATE TABLE `sa_system_config_group` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `name` varchar(50) DEFAULT NULL COMMENT '字典名称',
  `code` varchar(100) DEFAULT NULL COMMENT '字典标示',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建人',
  `updated_by` int DEFAULT NULL COMMENT '更新人',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='参数配置分组表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_config_group`
--

INSERT INTO `sa_system_config_group` (`id`, `name`, `code`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, '站点配置', 'site_config', '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(2, '上传配置', 'upload_config', NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(3, '邮件服务', 'email_config', NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_dept`
--

CREATE TABLE `sa_system_dept` (
  `id` bigint UNSIGNED NOT NULL,
  `parent_id` bigint UNSIGNED DEFAULT '0' COMMENT '父级ID，0为根节点',
  `name` varchar(64) NOT NULL COMMENT '部门名称',
  `code` varchar(64) DEFAULT NULL COMMENT '部门编码',
  `leader_id` bigint UNSIGNED DEFAULT NULL COMMENT '部门负责人ID',
  `level` varchar(255) DEFAULT '' COMMENT '祖级列表，格式: 0,1,5, (便于查询子孙节点)',
  `sort` int DEFAULT '0' COMMENT '排序，数字越小越靠前',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态: 1启用, 0禁用',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='部门表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_dept`
--

INSERT INTO `sa_system_dept` (`id`, `parent_id`, `name`, `code`, `leader_id`, `level`, `sort`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 0, 'LQPAY', 'GROUP', 1, '0', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:41:41', NULL),
(2, 1, '总办', 'GMO', NULL, '0,1,', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:28', '2026-06-10 23:40:28'),
(10, 1, '微信事业群', 'WXG', NULL, '0,1,', 200, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:37', '2026-06-10 23:40:37'),
(11, 1, '互动娱乐事业群', 'IEG', NULL, '0,1,', 300, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:59', '2026-06-10 23:40:59'),
(12, 1, '云与智慧产业事业群', 'CSIG', NULL, '0,1,', 400, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:41:09', '2026-06-10 23:41:09'),
(101, 10, '微信基础产品部', 'WX_BASE', NULL, '0,1,10,', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:32', '2026-06-10 23:40:32'),
(102, 10, '微信支付线', 'WX_PAY', NULL, '0,1,10,', 200, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:34', '2026-06-10 23:40:34'),
(111, 11, '天美工作室群', 'TIMI', NULL, '0,1,11,', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:51', '2026-06-10 23:40:51'),
(112, 11, '光子工作室群', 'LIGHT', NULL, '0,1,11,', 200, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:56', '2026-06-10 23:40:56'),
(121, 12, '腾讯云事业部', 'CLOUD', NULL, '0,1,12,', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:41:03', '2026-06-10 23:41:03'),
(1111, 111, '王者荣耀项目组', 'HOK', NULL, '0,1,11,111,', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:40', '2026-06-10 23:40:40'),
(1112, 111, 'QQ飞车项目组', 'QQ_SPEED', NULL, '0,1,11,111,', 200, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:40:46', '2026-06-10 23:40:46');

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_dict_data`
--

CREATE TABLE `sa_system_dict_data` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `type_id` int UNSIGNED DEFAULT NULL COMMENT '字典类型ID',
  `label` varchar(50) DEFAULT NULL COMMENT '字典标签',
  `value` varchar(100) DEFAULT NULL COMMENT '字典值',
  `color` varchar(50) DEFAULT NULL COMMENT '字典颜色',
  `code` varchar(100) DEFAULT NULL COMMENT '字典标示',
  `sort` smallint UNSIGNED DEFAULT '0' COMMENT '排序',
  `status` smallint DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='字典数据表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_dict_data`
--

INSERT INTO `sa_system_dict_data` (`id`, `type_id`, `label`, `value`, `color`, `code`, `sort`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(2, 2, '本地存储', '1', '#5d87ff', 'upload_mode', 99, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(3, 2, '阿里云OSS', '2', '#f9901f', 'upload_mode', 98, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(4, 2, '七牛云', '3', '#00ced1', 'upload_mode', 97, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(5, 2, '腾讯云COS', '4', '#1d84ff', 'upload_mode', 96, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(6, 2, '亚马逊S3', '5', '#ff80c8', 'upload_mode', 95, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(7, 3, '正常', '1', '#13deb9', 'data_status', 0, 1, '1为正常', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(8, 3, '停用', '2', '#ff4d4f', 'data_status', 0, 1, '2为停用', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(9, 4, '统计页面', 'statistics', '#00ced1', 'dashboard', 100, 1, '管理员用', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(10, 4, '工作台', 'work', '#ff8c00', 'dashboard', 50, 1, '员工使用', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(11, 5, '男', '1', '#5d87ff', 'gender', 0, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(12, 5, '女', '2', '#ff4500', 'gender', 0, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(13, 5, '未知', '3', '#b48df3', 'gender', 0, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(16, 12, '图片', 'image', '#60c041', 'attachment_type', 10, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(17, 12, '文档', 'text', '#1d84ff', 'attachment_type', 9, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(18, 12, '音频', 'audio', '#00ced1', 'attachment_type', 8, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(19, 12, '视频', 'video', '#ff4500', 'attachment_type', 7, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(20, 12, '应用程序', 'application', '#ff8c00', 'attachment_type', 6, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(21, 13, '目录', '1', '#909399', 'menu_type', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(22, 13, '菜单', '2', '#1e90ff', 'menu_type', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(23, 13, '按钮', '3', '#ff4500', 'menu_type', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(24, 13, '外链', '4', '#00ced1', 'menu_type', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(25, 14, '是', '1', '#60c041', 'yes_or_no', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(26, 14, '否', '2', '#ff4500', 'yes_or_no', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(47, 20, 'URL任务GET', '1', '#5d87ff', 'crontab_task_type', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(48, 20, 'URL任务POST', '2', '#00ced1', 'crontab_task_type', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(49, 20, '类任务', '3', '#ff8c00', 'crontab_task_type', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_dict_type`
--

CREATE TABLE `sa_system_dict_type` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `name` varchar(50) DEFAULT NULL COMMENT '字典名称',
  `code` varchar(100) DEFAULT NULL COMMENT '字典标示',
  `status` smallint DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='字典类型表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_dict_type`
--

INSERT INTO `sa_system_dict_type` (`id`, `name`, `code`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(2, '存储模式', 'upload_mode', 1, '上传文件存储模式', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(3, '数据状态', 'data_status', 1, '通用数据状态', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(4, '后台首页', 'dashboard', 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(5, '性别', 'gender', 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(12, '附件类型', 'attachment_type', 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(13, '菜单类型', 'menu_type', 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(14, '是否', 'yes_or_no', 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(20, '定时任务类型', 'crontab_task_type', 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_login_log`
--

CREATE TABLE `sa_system_login_log` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `username` varchar(20) DEFAULT NULL COMMENT '用户名',
  `ip` varchar(45) DEFAULT NULL COMMENT '登录IP地址',
  `ip_location` varchar(255) DEFAULT NULL COMMENT 'IP所属地',
  `os` varchar(50) DEFAULT NULL COMMENT '操作系统',
  `browser` varchar(50) DEFAULT NULL COMMENT '浏览器',
  `status` smallint DEFAULT '1' COMMENT '登录状态 (1成功 2失败)',
  `message` varchar(50) DEFAULT NULL COMMENT '提示消息',
  `login_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '登录时间',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '更新时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='登录日志表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_login_log`
--

INSERT INTO `sa_system_login_log` (`id`, `username`, `ip`, `ip_location`, `os`, `browser`, `status`, `message`, `login_time`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 'admin', '203.160.80.111', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-06 23:56:09', NULL, 1, 1, '2026-06-06 23:56:09', '2026-06-06 23:56:09', NULL),
(2, 'admin', '203.160.80.111', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-07 00:16:36', NULL, 1, 1, '2026-06-07 00:16:36', '2026-06-07 00:16:36', NULL),
(3, 'admin', '203.160.80.111', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-07 00:27:59', NULL, 1, 1, '2026-06-07 00:28:00', '2026-06-07 00:28:00', NULL),
(4, 'admin', '203.160.80.56', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-08 03:47:52', NULL, 1, 1, '2026-06-08 03:47:53', '2026-06-08 03:47:53', NULL),
(5, 'admin', '203.160.86.40', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-08 14:32:25', NULL, 1, 1, '2026-06-08 14:32:25', '2026-06-08 14:32:25', NULL),
(6, 'admin', '203.160.86.40', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-09 00:29:37', NULL, 1, 1, '2026-06-09 00:29:37', '2026-06-09 00:29:37', NULL),
(7, 'admin', '203.160.86.40', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-09 01:48:54', NULL, 1, 1, '2026-06-09 01:48:54', '2026-06-09 01:48:54', NULL),
(8, 'admin', '203.160.86.82', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-09 13:49:29', NULL, 1, 1, '2026-06-09 13:49:30', '2026-06-09 13:49:30', NULL),
(9, 'admin', '203.160.86.82', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-09 21:55:31', NULL, 1, 1, '2026-06-09 21:55:32', '2026-06-09 21:55:32', NULL),
(10, 'admin', '203.160.86.40', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-10 15:17:01', NULL, 1, 1, '2026-06-10 15:17:02', '2026-06-10 15:17:02', NULL),
(11, 'admin', '156.146.51.79', '美国', 'Mac', 'Chrome', 1, '登录成功', '2026-06-10 17:44:41', NULL, 1, 1, '2026-06-10 17:44:41', '2026-06-10 17:44:41', NULL),
(12, 'admin', '156.146.51.79', '美国', 'Mac', 'Chrome', 1, '登录成功', '2026-06-10 17:48:36', NULL, 1, 1, '2026-06-10 17:48:36', '2026-06-10 17:48:36', NULL),
(13, 'admin', '156.146.51.79', '美国', 'Mac', 'Chrome', 1, '登录成功', '2026-06-10 17:54:14', NULL, 1, 1, '2026-06-10 17:54:14', '2026-06-10 17:54:14', NULL),
(14, 'admin', '156.146.51.87', '美国', 'Mac', 'Chrome', 1, '登录成功', '2026-06-10 23:37:29', NULL, 1, 1, '2026-06-10 23:37:30', '2026-06-10 23:37:30', NULL),
(15, 'admin', '156.146.51.87', '美国', 'Mac', 'Chrome', 1, '登录成功', '2026-06-11 00:17:11', NULL, 1, 1, '2026-06-11 00:17:12', '2026-06-11 00:17:12', NULL),
(16, 'admin', '94.204.1.213', '阿联酋', 'Mac', 'Chrome', 1, '登录成功', '2026-06-11 01:18:18', NULL, 1, 1, '2026-06-11 01:18:18', '2026-06-11 01:18:18', NULL),
(17, 'admin', '94.204.1.213', '阿联酋', 'Mac', 'Chrome', 1, '登录成功', '2026-06-11 01:40:06', NULL, 1, 1, '2026-06-11 01:40:06', '2026-06-11 01:40:06', NULL),
(18, 'admin', '3.1.76.56', '新加坡', 'Win', 'Chrome', 1, '登录成功', '2026-06-11 02:05:45', NULL, 1, 1, '2026-06-11 02:05:46', '2026-06-11 02:05:46', NULL),
(19, 'admin', '203.160.86.40', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-11 17:05:03', NULL, 1, 1, '2026-06-11 17:05:03', '2026-06-11 17:05:03', NULL),
(20, 'admin', '203.160.86.40', '香港-0:联通', 'Mac', 'Chrome', 1, '登录成功', '2026-06-11 17:05:23', NULL, 1, 1, '2026-06-11 17:05:23', '2026-06-11 17:05:23', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_mail`
--

CREATE TABLE `sa_system_mail` (
  `id` int UNSIGNED NOT NULL COMMENT '编号',
  `gateway` varchar(50) DEFAULT NULL COMMENT '网关',
  `from` varchar(50) DEFAULT NULL COMMENT '发送人',
  `email` varchar(50) DEFAULT NULL COMMENT '接收人',
  `code` varchar(20) DEFAULT NULL COMMENT '验证码',
  `content` varchar(500) DEFAULT NULL COMMENT '邮箱内容',
  `status` varchar(20) DEFAULT NULL COMMENT '发送状态',
  `response` varchar(500) DEFAULT NULL COMMENT '返回结果',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='邮件记录' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_menu`
--

CREATE TABLE `sa_system_menu` (
  `id` bigint UNSIGNED NOT NULL,
  `parent_id` bigint UNSIGNED DEFAULT '0' COMMENT '父级ID',
  `name` varchar(64) NOT NULL COMMENT '菜单名称',
  `code` varchar(64) DEFAULT NULL COMMENT '组件名称',
  `slug` varchar(100) DEFAULT NULL COMMENT '权限标识，如 user:list, user:add',
  `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '类型: 1目录, 2菜单, 3按钮/API',
  `path` varchar(255) DEFAULT NULL COMMENT '路由地址(前端)或API路径(后端)',
  `component` varchar(255) DEFAULT NULL COMMENT '前端组件路径，如 layout/User',
  `method` varchar(10) DEFAULT NULL COMMENT '请求方式',
  `icon` varchar(64) DEFAULT NULL COMMENT '图标',
  `sort` int DEFAULT '100' COMMENT '排序',
  `link_url` varchar(255) DEFAULT NULL COMMENT '外部链接',
  `is_iframe` tinyint(1) DEFAULT '2' COMMENT '是否iframe',
  `is_keep_alive` tinyint(1) DEFAULT '2' COMMENT '是否缓存',
  `is_hidden` tinyint(1) DEFAULT '2' COMMENT '是否隐藏',
  `is_fixed_tab` tinyint(1) DEFAULT '2' COMMENT '是否固定标签页',
  `is_full_page` tinyint(1) DEFAULT '2' COMMENT '是否全屏',
  `generate_id` int DEFAULT '0' COMMENT '生成id',
  `generate_key` varchar(255) DEFAULT NULL COMMENT '生成key',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `remark` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='菜单权限表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_menu`
--

INSERT INTO `sa_system_menu` (`id`, `parent_id`, `name`, `code`, `slug`, `type`, `path`, `component`, `method`, `icon`, `sort`, `link_url`, `is_iframe`, `is_keep_alive`, `is_hidden`, `is_fixed_tab`, `is_full_page`, `generate_id`, `generate_key`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 0, '仪表盘', 'Dashboard', '', 1, '/dashboard', '', NULL, 'ri:pie-chart-line', 1001, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:46:33', NULL),
(2, 1, '工作台', 'Console', NULL, 2, 'console', '/dashboard/console', NULL, 'ri:home-smile-2-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(3, 0, '系统管理', 'System', NULL, 1, '/system', NULL, NULL, 'ri:user-3-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(4, 3, '用户管理', 'User', NULL, 2, 'user', '/system/user', NULL, 'ri:user-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(5, 3, '部门管理', 'Dept', NULL, 2, 'dept', '/system/dept', NULL, 'ri:node-tree', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(6, 3, '角色管理', 'Role', NULL, 2, 'role', '/system/role', NULL, 'ri:admin-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(7, 3, '岗位管理', 'Post', '', 2, 'post', '/system/post', NULL, 'ri:signpost-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(8, 3, '菜单管理', 'Menu', NULL, 2, 'menu', '/system/menu', NULL, 'ri:menu-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(10, 0, '运维管理', 'Safeguard', NULL, 1, '/safeguard', '', NULL, 'ri:shield-check-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(11, 10, '缓存管理', 'Cache', '', 2, 'cache', '/safeguard/cache', NULL, 'ri:keyboard-box-line', 80, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(12, 10, '数据字典', 'Dict', NULL, 2, 'dict', '/safeguard/dict', NULL, 'ri:database-2-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(13, 10, '附件管理', 'Attachment', '', 2, 'attachment', '/safeguard/attachment', NULL, 'ri:file-cloud-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(14, 10, '数据表维护', 'Database', '', 2, 'database', '/safeguard/database', NULL, 'ri:database-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(15, 10, '登录日志', 'LoginLog', '', 2, 'login-log', '/safeguard/login-log', NULL, 'ri:login-circle-line', 50, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(16, 10, '操作日志', 'OperLog', '', 2, 'oper-log', '/safeguard/oper-log', NULL, 'ri:shield-keyhole-line', 50, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(17, 10, '邮件日志', 'EmailLog', '', 2, 'email-log', '/safeguard/email-log', NULL, 'ri:mail-line', 50, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(18, 3, '系统设置', 'Config', NULL, 2, 'config', '/system/config', NULL, 'ri:settings-4-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(19, 0, '官方文档', 'Document', '', 4, '', '', NULL, 'ri:file-copy-2-fill', 90, 'https://saithink.top', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:45:47', '2026-06-10 23:45:47'),
(20, 4, '数据列表', '', 'core:user:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(21, 1, '个人中心', 'UserCenter', '', 2, 'user-center', '/dashboard/user-center/index', NULL, 'ri:user-2-line', 100, '', 2, 2, 1, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(22, 4, '添加', '', 'core:user:save', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(23, 4, '修改', '', 'core:user:update', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(24, 4, '读取', '', 'core:user:read', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(25, 4, '删除', '', 'core:user:destroy', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(26, 4, '重置密码', '', 'core:user:password', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(27, 4, '清理缓存', '', 'core:user:cache', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(28, 4, '设置工作台', '', 'core:user:home', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(29, 5, '数据列表', '', 'core:dept:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(30, 5, '添加', '', 'core:dept:save', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(31, 5, '修改', '', 'core:dept:update', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(32, 5, '读取', '', 'core:dept:read', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(33, 5, '删除', '', 'core:dept:destroy', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(34, 6, '添加', '', 'core:role:save', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(35, 6, '数据列表', '', 'core:role:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(36, 6, '修改', '', 'core:role:update', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(37, 6, '读取', '', 'core:role:read', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(38, 6, '删除', '', 'core:role:destroy', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(39, 6, '菜单权限', '', 'core:role:menu', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(41, 7, '数据列表', '', 'core:post:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(42, 7, '添加', '', 'core:post:save', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(43, 7, '修改', '', 'core:post:update', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(44, 7, '读取', '', 'core:post:read', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(45, 7, '删除', '', 'core:post:destroy', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(46, 7, '导入', '', 'core:post:import', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(47, 7, '导出', '', 'core:post:export', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(48, 8, '数据列表', '', 'core:menu:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(49, 8, '读取', '', 'core:menu:read', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(50, 8, '添加', '', 'core:menu:save', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(51, 8, '修改', '', 'core:menu:update', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(52, 8, '删除', '', 'core:menu:destroy', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(53, 18, '数据列表', '', 'core:config:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(54, 18, '管理', '', 'core:config:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(55, 18, '修改', '', 'core:config:update', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(56, 12, '数据列表', '', 'core:dict:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(57, 12, '管理', '', 'core:dict:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(58, 13, '数据列表', '', 'core:attachment:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(59, 13, '管理', '', 'core:attachment:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(60, 14, '数据表列表', '', 'core:database:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(61, 14, '数据表维护', '', 'core:database:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(62, 14, '回收站数据', '', 'core:recycle:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(63, 14, '回收站管理', '', 'core:recycle:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(64, 15, '数据列表', '', 'core:logs:login', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(65, 15, '删除', '', 'core:logs:deleteLogin', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(66, 16, '数据列表', '', 'core:logs:Oper', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(67, 16, '删除', '', 'core:logs:deleteOper', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(68, 17, '数据列表', '', 'core:email:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(69, 17, '删除', '', 'core:email:destroy', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(70, 10, '服务监控', 'Server', '', 2, 'server', '/safeguard/server', NULL, 'ri:server-line', 90, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(71, 70, '数据列表', '', 'core:server:monitor', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(72, 11, '数据列表', '', 'core:server:cache', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(73, 11, '缓存清理', '', 'core:server:clear', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(74, 2, '登录数据统计', '', 'core:console:list', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(75, 0, '附加权限', 'Permission', '', 1, 'permission', '', NULL, 'ri:apps-2-ai-line', 100, '', 2, 2, 1, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(76, 75, '上传图片', '', 'core:system:uploadImage', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(77, 75, '上传文件', '', 'core:system:uploadFile', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(78, 75, '附件列表', '', 'core:system:resource', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(79, 75, '用户列表', '', 'core:system:user', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(80, 0, '工具', 'Tool', '', 1, '/tool', '', NULL, 'ri:tools-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(81, 80, '代码生成', 'Code', '', 2, 'code', '/tool/code', NULL, 'ri:code-s-slash-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(82, 80, '定时任务', 'Crontab', '', 2, 'crontab', '/tool/crontab', NULL, 'ri:time-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(83, 82, '数据列表', '', 'tool:crontab:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(84, 82, '管理', '', 'tool:crontab:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(85, 82, '运行任务', '', 'tool:crontab:run', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(86, 81, '数据列表', '', 'tool:code:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(87, 81, '管理', '', 'tool:code:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(88, 0, '插件市场', 'Plugin', '', 2, '/plugin', '/plugin/saipackage/install/index', NULL, 'ri:apps-2-ai-line', 100, '', 2, 2, 1, 2, 2, 0, NULL, 2, '', 1, 1, '2026-01-01 00:00:00', '2026-06-10 23:45:57', NULL),
(9000, 0, '支付管理', 'Payment', '', 1, '/payment', '', NULL, 'ri:bank-card-line', 200, '', 2, 2, 2, 2, 2, 0, NULL, 1, '四方支付渠道系统', 1, 1, '2026-06-08 00:00:00', '2026-06-10 23:46:42', NULL),
(9010, 9000, '商户管理', 'PayMerchant', NULL, 2, 'merchant', '/payment/merchant', NULL, 'ri:store-2-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9011, 9010, '数据列表', '', 'pay:merchant:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9012, 9010, '添加', '', 'pay:merchant:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9013, 9010, '修改', '', 'pay:merchant:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9014, 9010, '读取', '', 'pay:merchant:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9015, 9010, '删除', '', 'pay:merchant:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9016, 9010, '重置密钥', '', 'pay:merchant:resetKey', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9017, 9010, '代收/代付通道配置', '', 'pay:merchant:channel', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '商户代收与代付通道授权（入口已拆分）', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9018, 9010, '路由配置', '', 'pay:merchant:route', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '商户×路由代收白名单', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9020, 9000, '支付通道', 'PayChannel', '', 2, 'channel', '/payment/channel', NULL, 'ri:route-line', 90, '', 2, 2, 2, 2, 2, 0, NULL, 1, '列表 scope channel_biz IN 1,3', 1, 1, '2026-06-08 00:00:00', '2026-06-10 23:48:27', NULL),
(9021, 9020, '数据列表', '', 'pay:channel:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9022, 9020, '添加', '', 'pay:channel:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9023, 9020, '修改', '', 'pay:channel:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9024, 9020, '读取', '', 'pay:channel:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9025, 9020, '删除', '', 'pay:channel:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9026, 9020, '授权商户', '', 'pay:channel:auth', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '通道维度批量授权商户代收', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9027, 9000, '代付通道', 'PayTransferChannel', '', 2, 'transfer-channel', '/payment/transfer-channel', NULL, 'ri:swap-line', 85, '', 2, 2, 2, 2, 2, 0, NULL, 1, '列表 scope channel_biz IN 2,3', 1, 1, '2026-06-09 00:00:00', '2026-06-10 23:48:37', NULL),
(9028, 9027, '数据列表', '', 'pay:transferChannel:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9029, 9027, '添加', '', 'pay:transferChannel:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9030, 9027, '修改', '', 'pay:transferChannel:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9031, 9027, '读取', '', 'pay:transferChannel:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9032, 9027, '删除', '', 'pay:transferChannel:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9033, 9027, '授权商户', '', 'pay:transferChannel:auth', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '通道维度批量授权商户代付', 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9034, 9000, '路由管理', 'PayRoute', NULL, 2, 'route', '/payment/route', NULL, 'ri:share-forward-line', 80, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9035, 9034, '数据列表', '', 'pay:route:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9036, 9034, '添加', '', 'pay:route:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9037, 9034, '修改', '', 'pay:route:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9038, 9034, '读取', '', 'pay:route:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9039, 9034, '删除', '', 'pay:route:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9040, 9043, '测试下单', '', 'pay:order:testSubmit', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-09 00:00:00', '2026-06-09 00:00:00', NULL),
(9043, 9000, '支付测试', 'PayOrderTest', '', 2, 'order-test', '/payment/order-test', NULL, 'ri:flask-line', 72, '', 2, 2, 1, 2, 2, 0, NULL, 1, '平台后台测试 submitOrder，免商户签名', 1, 1, '2026-06-09 00:00:00', '2026-06-10 23:48:57', NULL),
(9044, 9000, '订单管理', 'PayOrder', NULL, 2, 'order', '/payment/order', NULL, 'ri:file-list-3-line', 70, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9045, 9044, '数据列表', '', 'pay:order:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9046, 9044, '读取', '', 'pay:order:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9047, 9044, '补单', '', 'pay:order:reissue', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9048, 9044, '导出', '', 'pay:order:export', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9054, 9000, '提现审核', 'PayWithdraw', NULL, 2, 'withdraw', '/payment/withdraw', NULL, 'ri:hand-coin-line', 60, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9055, 9054, '数据列表', '', 'pay:withdraw:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9056, 9054, '读取', '', 'pay:withdraw:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9057, 9054, '审核', '', 'pay:withdraw:audit', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9064, 9000, '充值审核', 'PayRecharge', NULL, 2, 'recharge', '/payment/recharge', NULL, 'ri:wallet-3-line', 50, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9065, 9064, '数据列表', '', 'pay:recharge:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9066, 9064, '读取', '', 'pay:recharge:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9067, 9064, '审核', '', 'pay:recharge:audit', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9074, 9000, '资金流水', 'PayCapital', NULL, 2, 'capital', '/payment/capital', NULL, 'ri:exchange-funds-line', 40, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9075, 9074, '数据列表', '', 'pay:capital:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9076, 9074, '导出', '', 'pay:capital:export', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9084, 9000, '银行卡', 'PayBankCard', NULL, 2, 'bank-card', '/payment/bank-card', NULL, 'ri:bank-line', 30, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9085, 9084, '数据列表', '', 'pay:bankCard:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9086, 9084, '添加', '', 'pay:bankCard:save', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9087, 9084, '修改', '', 'pay:bankCard:update', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9088, 9084, '删除', '', 'pay:bankCard:destroy', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9094, 9000, '通知日志', 'PayNotifyLog', NULL, 2, 'notify-log', '/payment/notify-log', NULL, 'ri:notification-3-line', 20, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9095, 9094, '数据列表', '', 'pay:notify:index', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9096, 9094, '读取', '', 'pay:notify:read', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL),
(9097, 9094, '重发通知', '', 'pay:notify:resend', 3, '', '', NULL, '', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, 1, 1, '2026-06-08 00:00:00', '2026-06-08 00:00:00', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_oper_log`
--

CREATE TABLE `sa_system_oper_log` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `username` varchar(20) DEFAULT NULL COMMENT '用户名',
  `app` varchar(50) DEFAULT NULL COMMENT '应用名称',
  `method` varchar(20) DEFAULT NULL COMMENT '请求方式',
  `router` varchar(500) DEFAULT NULL COMMENT '请求路由',
  `service_name` varchar(30) DEFAULT NULL COMMENT '业务名称',
  `ip` varchar(45) DEFAULT NULL COMMENT '请求IP地址',
  `ip_location` varchar(255) DEFAULT NULL COMMENT 'IP所属地',
  `request_data` text COMMENT '请求数据',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '更新时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='操作日志表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_oper_log`
--

INSERT INTO `sa_system_oper_log` (`id`, `username`, `app`, `method`, `router`, `service_name`, `ip`, `ip_location`, `request_data`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 'admin', 'saiadmin', 'POST', '/tool/crontab/run', '定时任务执行', '203.160.86.82', '香港-0:联通', '{\"id\":9102}', NULL, 1, 1, '2026-06-09 23:30:23', '2026-06-09 23:30:23', NULL),
(2, 'admin', 'saiadmin', 'POST', '/tool/crontab/run', '定时任务执行', '203.160.86.82', '香港-0:联通', '{\"id\":9101}', NULL, 1, 1, '2026-06-09 23:30:34', '2026-06-09 23:30:34', NULL),
(3, 'admin', 'saiadmin', 'DELETE', '/tool/crontab/destroy', '定时任务删除', '203.160.86.82', '香港-0:联通', '{\"ids\":[1]}', NULL, 1, 1, '2026-06-09 23:31:56', '2026-06-09 23:31:56', NULL),
(4, 'admin', 'saiadmin', 'DELETE', '/tool/crontab/destroy', '定时任务删除', '203.160.86.82', '香港-0:联通', '{\"ids\":[2]}', NULL, 1, 1, '2026-06-09 23:31:59', '2026-06-09 23:31:59', NULL),
(5, 'admin', 'saiadmin', 'DELETE', '/tool/crontab/destroy', '定时任务删除', '203.160.86.82', '香港-0:联通', '{\"ids\":[3]}', NULL, 1, 1, '2026-06-09 23:32:15', '2026-06-09 23:32:15', NULL),
(6, 'admin', 'saiadmin', 'POST', '/core/config/batchUpdate', '系统设置修改', '203.160.86.40', '香港-0:联通', '{\"group_id\":2,\"config\":[{\"id\":8,\"group_id\":2,\"key\":\"upload_mode\",\"value\":\"1\",\"name\":\"上传模式\",\"input_type\":\"select\",\"config_select_data\":[{\"label\":\"本地上传\",\"value\":\"1\"},{\"label\":\"阿里云OSS\",\"value\":\"2\"},{\"label\":\"七牛云\",\"value\":\"3\"},{\"label\":\"腾讯云COS\",\"value\":\"4\"},{\"label\":\"亚马逊S3\",\"value\":\"5\"}],\"sort\":99,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":10,\"group_id\":2,\"key\":\"upload_size\",\"value\":\"52428800\",\"name\":\"上传大小\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":88,\"remark\":\"单位Byte,1MB=1024*1024Byte\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":43,\"group_id\":2,\"key\":\"s3_dirname\",\"value\":\"\",\"name\":\"dirname\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":26,\"group_id\":2,\"key\":\"oss_accessKeySecret\",\"value\":\"\",\"name\":\"阿里Secret\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储accessKeySecret\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":27,\"group_id\":2,\"key\":\"oss_bucket\",\"value\":\"\",\"name\":\"阿里bucket\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储bucket\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":28,\"group_id\":2,\"key\":\"oss_dirname\",\"value\":\"\",\"name\":\"阿里dirname\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储dirname\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":29,\"group_id\":2,\"key\":\"oss_domain\",\"value\":\"\",\"name\":\"阿里domain\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储domain\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":30,\"group_id\":2,\"key\":\"oss_endpoint\",\"value\":\"\",\"name\":\"阿里endpoint\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储endpoint\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":40,\"group_id\":2,\"key\":\"s3_key\",\"value\":\"\",\"name\":\"key\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":41,\"group_id\":2,\"key\":\"s3_secret\",\"value\":\"\",\"name\":\"secret\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":42,\"group_id\":2,\"key\":\"s3_bucket\",\"value\":\"\",\"name\":\"bucket\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":24,\"group_id\":2,\"key\":\"cos_region\",\"value\":\"\",\"name\":\"腾讯region\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储region\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":44,\"group_id\":2,\"key\":\"s3_domain\",\"value\":\"\",\"name\":\"domain\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":45,\"group_id\":2,\"key\":\"s3_region\",\"value\":\"\",\"name\":\"region\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":46,\"group_id\":2,\"key\":\"s3_version\",\"value\":\"\",\"name\":\"version\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":47,\"group_id\":2,\"key\":\"s3_use_path_style_endpoint\",\"value\":\"\",\"name\":\"path_style_endpoint\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":48,\"group_id\":2,\"key\":\"s3_endpoint\",\"value\":\"\",\"name\":\"endpoint\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":49,\"group_id\":2,\"key\":\"s3_acl\",\"value\":\"\",\"name\":\"acl\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":25,\"group_id\":2,\"key\":\"oss_accessKeyId\",\"value\":\"\",\"name\":\"阿里Id\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储accessKeyId\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":6,\"group_id\":2,\"key\":\"upload_allow_file\",\"value\":\"txt,doc,docx,xls,xlsx,ppt,pptx,rar,zip,7z,gz,pdf,wps,md,jpg,png,jpeg,mp4,pem,crt\",\"name\":\"文件类型\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":23,\"group_id\":2,\"key\":\"cos_domain\",\"value\":\"\",\"name\":\"腾讯domain\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储domain\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":22,\"group_id\":2,\"key\":\"cos_dirname\",\"value\":\"\",\"name\":\"腾讯dirname\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储dirname\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":21,\"group_id\":2,\"key\":\"cos_bucket\",\"value\":\"\",\"name\":\"腾讯bucket\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储bucket\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":20,\"group_id\":2,\"key\":\"cos_secretKey\",\"value\":\"\",\"name\":\"腾讯key\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云secretKey\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":19,\"group_id\":2,\"key\":\"cos_secretId\",\"value\":\"\",\"name\":\"腾讯Id\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储secretId\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":18,\"group_id\":2,\"key\":\"qiniu_domain\",\"value\":\"\",\"name\":\"七牛domain\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储domain\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":17,\"group_id\":2,\"key\":\"qiniu_dirname\",\"value\":\"\",\"name\":\"七牛dirname\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储dirname\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":16,\"group_id\":2,\"key\":\"qiniu_bucket\",\"value\":\"\",\"name\":\"七牛bucket\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储bucket\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":15,\"group_id\":2,\"key\":\"qiniu_secretKey\",\"value\":\"\",\"name\":\"七牛secret\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储secretKey\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":14,\"group_id\":2,\"key\":\"qiniu_accessKey\",\"value\":\"\",\"name\":\"七牛key\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储secretId\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":13,\"group_id\":2,\"key\":\"local_uri\",\"value\":\"\\/storage\\/\",\"name\":\"本地访问路径\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"访问是通过domain + uri\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":12,\"group_id\":2,\"key\":\"local_domain\",\"value\":\"https:\\/\\/api.starfusionx.com\",\"name\":\"本地存储域名\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"http:\\/\\/127.0.0.1:8787\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":11,\"group_id\":2,\"key\":\"local_root\",\"value\":\"public\\/storage\\/\",\"name\":\"本地存储路径\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"本地存储文件路径\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":7,\"group_id\":2,\"key\":\"upload_allow_image\",\"value\":\"jpg,jpeg,png,gif,svg,bmp\",\"name\":\"图片类型\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true}]}', NULL, 1, 1, '2026-06-10 15:28:27', '2026-06-10 15:28:27', NULL),
(7, 'admin', 'saiadmin', 'POST', '/core/config/batchUpdate', '系统设置修改', '203.160.86.40', '香港-0:联通', '{\"group_id\":2,\"config\":[{\"id\":8,\"group_id\":2,\"key\":\"upload_mode\",\"value\":\"1\",\"name\":\"上传模式\",\"input_type\":\"select\",\"config_select_data\":[{\"label\":\"本地上传\",\"value\":\"1\"},{\"label\":\"阿里云OSS\",\"value\":\"2\"},{\"label\":\"七牛云\",\"value\":\"3\"},{\"label\":\"腾讯云COS\",\"value\":\"4\"},{\"label\":\"亚马逊S3\",\"value\":\"5\"}],\"sort\":99,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":10,\"group_id\":2,\"key\":\"upload_size\",\"value\":\"52428800\",\"name\":\"上传大小\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":88,\"remark\":\"单位Byte,1MB=1024*1024Byte\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":43,\"group_id\":2,\"key\":\"s3_dirname\",\"value\":\"\",\"name\":\"dirname\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":26,\"group_id\":2,\"key\":\"oss_accessKeySecret\",\"value\":\"\",\"name\":\"阿里Secret\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储accessKeySecret\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":27,\"group_id\":2,\"key\":\"oss_bucket\",\"value\":\"\",\"name\":\"阿里bucket\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储bucket\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":28,\"group_id\":2,\"key\":\"oss_dirname\",\"value\":\"\",\"name\":\"阿里dirname\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储dirname\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":29,\"group_id\":2,\"key\":\"oss_domain\",\"value\":\"\",\"name\":\"阿里domain\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储domain\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":30,\"group_id\":2,\"key\":\"oss_endpoint\",\"value\":\"\",\"name\":\"阿里endpoint\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储endpoint\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":40,\"group_id\":2,\"key\":\"s3_key\",\"value\":\"\",\"name\":\"key\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":41,\"group_id\":2,\"key\":\"s3_secret\",\"value\":\"\",\"name\":\"secret\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":42,\"group_id\":2,\"key\":\"s3_bucket\",\"value\":\"\",\"name\":\"bucket\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":24,\"group_id\":2,\"key\":\"cos_region\",\"value\":\"\",\"name\":\"腾讯region\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储region\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":44,\"group_id\":2,\"key\":\"s3_domain\",\"value\":\"\",\"name\":\"domain\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":45,\"group_id\":2,\"key\":\"s3_region\",\"value\":\"\",\"name\":\"region\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":46,\"group_id\":2,\"key\":\"s3_version\",\"value\":\"\",\"name\":\"version\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":47,\"group_id\":2,\"key\":\"s3_use_path_style_endpoint\",\"value\":\"\",\"name\":\"path_style_endpoint\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":48,\"group_id\":2,\"key\":\"s3_endpoint\",\"value\":\"\",\"name\":\"endpoint\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":49,\"group_id\":2,\"key\":\"s3_acl\",\"value\":\"\",\"name\":\"acl\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":25,\"group_id\":2,\"key\":\"oss_accessKeyId\",\"value\":\"\",\"name\":\"阿里Id\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"阿里云存储accessKeyId\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":6,\"group_id\":2,\"key\":\"upload_allow_file\",\"value\":\"txt,doc,docx,xls,xlsx,ppt,pptx,rar,zip,7z,gz,pdf,wps,md,jpg,png,jpeg,mp4,pem,crt\",\"name\":\"文件类型\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":23,\"group_id\":2,\"key\":\"cos_domain\",\"value\":\"\",\"name\":\"腾讯domain\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储domain\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":22,\"group_id\":2,\"key\":\"cos_dirname\",\"value\":\"\",\"name\":\"腾讯dirname\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储dirname\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":21,\"group_id\":2,\"key\":\"cos_bucket\",\"value\":\"\",\"name\":\"腾讯bucket\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储bucket\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":20,\"group_id\":2,\"key\":\"cos_secretKey\",\"value\":\"\",\"name\":\"腾讯key\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云secretKey\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":19,\"group_id\":2,\"key\":\"cos_secretId\",\"value\":\"\",\"name\":\"腾讯Id\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"腾讯云存储secretId\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":18,\"group_id\":2,\"key\":\"qiniu_domain\",\"value\":\"\",\"name\":\"七牛domain\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储domain\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":17,\"group_id\":2,\"key\":\"qiniu_dirname\",\"value\":\"\",\"name\":\"七牛dirname\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储dirname\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":16,\"group_id\":2,\"key\":\"qiniu_bucket\",\"value\":\"\",\"name\":\"七牛bucket\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储bucket\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":15,\"group_id\":2,\"key\":\"qiniu_secretKey\",\"value\":\"\",\"name\":\"七牛secret\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储secretKey\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":14,\"group_id\":2,\"key\":\"qiniu_accessKey\",\"value\":\"\",\"name\":\"七牛key\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"七牛云存储secretId\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":false},{\"id\":13,\"group_id\":2,\"key\":\"local_uri\",\"value\":\"\\/storage\\/\",\"name\":\"本地访问路径\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"访问是通过domain + uri\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":12,\"group_id\":2,\"key\":\"local_domain\",\"value\":\"https:\\/\\/api.starfusionx.com\",\"name\":\"本地存储域名\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"http:\\/\\/127.0.0.1:8787\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":11,\"group_id\":2,\"key\":\"local_root\",\"value\":\"public\\/storage\\/\",\"name\":\"本地存储路径\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":\"本地存储文件路径\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":7,\"group_id\":2,\"key\":\"upload_allow_image\",\"value\":\"jpg,jpeg,png,gif,svg,bmp\",\"name\":\"图片类型\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":0,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true}]}', NULL, 1, 1, '2026-06-10 15:28:28', '2026-06-10 15:28:28', NULL),
(8, 'admin', 'saiadmin', 'POST', '/core/system/uploadImage', '上传图片', '203.160.86.40', '香港-0:联通', '[]', NULL, 1, 1, '2026-06-10 15:29:22', '2026-06-10 15:29:22', NULL),
(9, 'admin', 'saiadmin', 'POST', '/core/user/updateInfo', '用户修改资料', '203.160.86.40', '香港-0:联通', '{\"id\":1,\"avatar\":\"https:\\/\\/api.starfusionx.com\\/storage\\/20260610\\/4d5daced6849ac60b69cb0238740e9618369467b.svg\"}', NULL, 1, 1, '2026-06-10 15:29:23', '2026-06-10 15:29:23', NULL),
(10, 'admin', 'saiadmin', 'POST', '/core/system/uploadImage', '上传图片', '156.146.51.79', '美国', '[]', NULL, 1, 1, '2026-06-10 17:50:45', '2026-06-10 17:50:45', NULL),
(11, 'admin', 'saiadmin', 'POST', '/core/user/updateInfo', '用户修改资料', '156.146.51.79', '美国', '{\"id\":1,\"avatar\":\"https:\\/\\/api.starfusionx.com\\/storage\\/20260610\\/9d61daca475a7d2e2798bb9de5515d4398a2d474.png\"}', NULL, 1, 1, '2026-06-10 17:50:46', '2026-06-10 17:50:46', NULL),
(12, 'admin', 'saiadmin', 'POST', '/core/config/batchUpdate', '系统设置修改', '156.146.51.87', '美国', '{\"group_id\":1,\"config\":[{\"id\":4,\"group_id\":1,\"key\":\"site_name\",\"value\":\"LQPAY\",\"name\":\"网站名称\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":99,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":3,\"group_id\":1,\"key\":\"site_keywords\",\"value\":\"LQPAY\",\"name\":\"网站关键字\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":98,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":2,\"group_id\":1,\"key\":\"site_desc\",\"value\":\"LQPAY\",\"name\":\"网站描述\",\"input_type\":\"textarea\",\"config_select_data\":null,\"sort\":97,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":1,\"group_id\":1,\"key\":\"site_copyright\",\"value\":\"Copyright © 2024 LQPAY\",\"name\":\"版权信息\",\"input_type\":\"textarea\",\"config_select_data\":null,\"sort\":96,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":5,\"group_id\":1,\"key\":\"site_record_number\",\"value\":\"\",\"name\":\"网站备案号\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":95,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true}]}', NULL, 1, 1, '2026-06-10 23:38:52', '2026-06-10 23:38:52', NULL),
(13, 'admin', 'saiadmin', 'POST', '/core/config/batchUpdate', '系统设置修改', '156.146.51.87', '美国', '{\"group_id\":1,\"config\":[{\"id\":4,\"group_id\":1,\"key\":\"site_name\",\"value\":\"LQPAY\",\"name\":\"网站名称\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":99,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":3,\"group_id\":1,\"key\":\"site_keywords\",\"value\":\"LQPAY\",\"name\":\"网站关键字\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":98,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":2,\"group_id\":1,\"key\":\"site_desc\",\"value\":\"LQPAY\",\"name\":\"网站描述\",\"input_type\":\"textarea\",\"config_select_data\":null,\"sort\":97,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":1,\"group_id\":1,\"key\":\"site_copyright\",\"value\":\"Copyright © 2024 LQPAY\",\"name\":\"版权信息\",\"input_type\":\"textarea\",\"config_select_data\":null,\"sort\":96,\"remark\":\"\",\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true},{\"id\":5,\"group_id\":1,\"key\":\"site_record_number\",\"value\":\"\",\"name\":\"网站备案号\",\"input_type\":\"input\",\"config_select_data\":null,\"sort\":95,\"remark\":null,\"created_by\":1,\"updated_by\":1,\"create_time\":\"2026-01-01 00:00:00\",\"update_time\":\"2026-01-01 00:00:00\",\"display\":true}]}', NULL, 1, 1, '2026-06-10 23:38:55', '2026-06-10 23:38:55', NULL),
(14, 'admin', 'saiadmin', 'DELETE', '/core/user/destroy', '用户数据删除', '156.146.51.87', '美国', '{\"ids\":[101]}', NULL, 1, 1, '2026-06-10 23:39:59', '2026-06-10 23:39:59', NULL),
(15, 'admin', 'saiadmin', 'DELETE', '/core/user/destroy', '用户数据删除', '156.146.51.87', '美国', '{\"ids\":[100]}', NULL, 1, 1, '2026-06-10 23:40:02', '2026-06-10 23:40:02', NULL),
(16, 'admin', 'saiadmin', 'DELETE', '/core/user/destroy', '用户数据删除', '156.146.51.87', '美国', '{\"ids\":[10]}', NULL, 1, 1, '2026-06-10 23:40:04', '2026-06-10 23:40:04', NULL),
(17, 'admin', 'saiadmin', 'DELETE', '/core/user/destroy', '用户数据删除', '156.146.51.87', '美国', '{\"ids\":[5]}', NULL, 1, 1, '2026-06-10 23:40:07', '2026-06-10 23:40:07', NULL),
(18, 'admin', 'saiadmin', 'DELETE', '/core/user/destroy', '用户数据删除', '156.146.51.87', '美国', '{\"ids\":[4]}', NULL, 1, 1, '2026-06-10 23:40:09', '2026-06-10 23:40:09', NULL),
(19, 'admin', 'saiadmin', 'DELETE', '/core/user/destroy', '用户数据删除', '156.146.51.87', '美国', '{\"ids\":[3]}', NULL, 1, 1, '2026-06-10 23:40:12', '2026-06-10 23:40:12', NULL),
(20, 'admin', 'saiadmin', 'DELETE', '/core/user/destroy', '用户数据删除', '156.146.51.87', '美国', '{\"ids\":[2]}', NULL, 1, 1, '2026-06-10 23:40:15', '2026-06-10 23:40:15', NULL),
(21, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[2]}', NULL, 1, 1, '2026-06-10 23:40:28', '2026-06-10 23:40:28', NULL),
(22, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[101]}', NULL, 1, 1, '2026-06-10 23:40:32', '2026-06-10 23:40:32', NULL),
(23, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[102]}', NULL, 1, 1, '2026-06-10 23:40:34', '2026-06-10 23:40:34', NULL),
(24, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[10]}', NULL, 1, 1, '2026-06-10 23:40:37', '2026-06-10 23:40:37', NULL),
(25, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[1111]}', NULL, 1, 1, '2026-06-10 23:40:40', '2026-06-10 23:40:40', NULL),
(26, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[1112]}', NULL, 1, 1, '2026-06-10 23:40:46', '2026-06-10 23:40:46', NULL),
(27, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[111]}', NULL, 1, 1, '2026-06-10 23:40:51', '2026-06-10 23:40:51', NULL),
(28, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[112]}', NULL, 1, 1, '2026-06-10 23:40:56', '2026-06-10 23:40:56', NULL),
(29, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[11]}', NULL, 1, 1, '2026-06-10 23:40:59', '2026-06-10 23:40:59', NULL),
(30, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[121]}', NULL, 1, 1, '2026-06-10 23:41:03', '2026-06-10 23:41:03', NULL),
(31, 'admin', 'saiadmin', 'DELETE', '/core/dept/destroy', '部门数据删除', '156.146.51.87', '美国', '{\"ids\":[12]}', NULL, 1, 1, '2026-06-10 23:41:09', '2026-06-10 23:41:09', NULL),
(32, 'admin', 'saiadmin', 'PUT', '/core/dept/update', '部门数据修改', '156.146.51.87', '美国', '{\"id\":1,\"parent_id\":0,\"level\":\"0,\",\"name\":\"LQPAY\",\"code\":\"GROUP\",\"leader_id\":1,\"remark\":\"\",\"sort\":100,\"status\":1}', NULL, 1, 1, '2026-06-10 23:41:41', '2026-06-10 23:41:41', NULL),
(33, 'admin', 'saiadmin', 'DELETE', '/core/menu/destroy', '菜单数据删除', '156.146.51.87', '美国', '{\"ids\":[19]}', NULL, 1, 1, '2026-06-10 23:45:47', '2026-06-10 23:45:47', NULL),
(34, 'admin', 'saiadmin', 'PUT', '/core/menu/update', '菜单数据修改', '156.146.51.87', '美国', '{\"id\":88,\"parent_id\":0,\"type\":2,\"component\":\"\\/plugin\\/saipackage\\/install\\/index\",\"name\":\"插件市场\",\"slug\":\"\",\"path\":\"\\/plugin\",\"icon\":\"ri:apps-2-ai-line\",\"code\":\"Plugin\",\"remark\":\"\",\"link_url\":\"\",\"is_iframe\":2,\"is_keep_alive\":2,\"is_hidden\":1,\"is_fixed_tab\":2,\"is_full_page\":2,\"sort\":100,\"status\":2}', NULL, 1, 1, '2026-06-10 23:45:57', '2026-06-10 23:45:57', NULL),
(35, 'admin', 'saiadmin', 'PUT', '/core/menu/update', '菜单数据修改', '156.146.51.87', '美国', '{\"id\":1,\"parent_id\":0,\"type\":1,\"component\":\"\",\"name\":\"仪表盘\",\"slug\":\"\",\"path\":\"\\/dashboard\",\"icon\":\"ri:pie-chart-line\",\"code\":\"Dashboard\",\"remark\":\"\",\"link_url\":\"\",\"is_iframe\":2,\"is_keep_alive\":2,\"is_hidden\":2,\"is_fixed_tab\":2,\"is_full_page\":2,\"sort\":1001,\"status\":1}', NULL, 1, 1, '2026-06-10 23:46:33', '2026-06-10 23:46:33', NULL),
(36, 'admin', 'saiadmin', 'PUT', '/core/menu/update', '菜单数据修改', '156.146.51.87', '美国', '{\"id\":9000,\"parent_id\":0,\"type\":1,\"component\":\"\",\"name\":\"支付管理\",\"slug\":\"\",\"path\":\"\\/payment\",\"icon\":\"ri:bank-card-line\",\"code\":\"Payment\",\"remark\":\"四方支付渠道系统\",\"link_url\":\"\",\"is_iframe\":2,\"is_keep_alive\":2,\"is_hidden\":2,\"is_fixed_tab\":2,\"is_full_page\":2,\"sort\":200,\"status\":1}', NULL, 1, 1, '2026-06-10 23:46:42', '2026-06-10 23:46:42', NULL),
(37, 'admin', 'saiadmin', 'PUT', '/core/menu/update', '菜单数据修改', '156.146.51.87', '美国', '{\"id\":9020,\"parent_id\":9000,\"type\":2,\"component\":\"\\/payment\\/channel\",\"name\":\"支付通道\",\"slug\":\"\",\"path\":\"channel\",\"icon\":\"ri:route-line\",\"code\":\"PayChannel\",\"remark\":\"列表 scope channel_biz IN 1,3\",\"link_url\":\"\",\"is_iframe\":2,\"is_keep_alive\":2,\"is_hidden\":2,\"is_fixed_tab\":2,\"is_full_page\":2,\"sort\":90,\"status\":1}', NULL, 1, 1, '2026-06-10 23:48:27', '2026-06-10 23:48:27', NULL),
(38, 'admin', 'saiadmin', 'PUT', '/core/menu/update', '菜单数据修改', '156.146.51.87', '美国', '{\"id\":9027,\"parent_id\":9000,\"type\":2,\"component\":\"\\/payment\\/transfer-channel\",\"name\":\"代付通道\",\"slug\":\"\",\"path\":\"transfer-channel\",\"icon\":\"ri:swap-line\",\"code\":\"PayTransferChannel\",\"remark\":\"列表 scope channel_biz IN 2,3\",\"link_url\":\"\",\"is_iframe\":2,\"is_keep_alive\":2,\"is_hidden\":2,\"is_fixed_tab\":2,\"is_full_page\":2,\"sort\":85,\"status\":1}', NULL, 1, 1, '2026-06-10 23:48:37', '2026-06-10 23:48:37', NULL),
(39, 'admin', 'saiadmin', 'PUT', '/core/menu/update', '菜单数据修改', '156.146.51.87', '美国', '{\"id\":9043,\"parent_id\":9000,\"type\":2,\"component\":\"\\/payment\\/order-test\",\"name\":\"支付测试\",\"slug\":\"\",\"path\":\"order-test\",\"icon\":\"ri:flask-line\",\"code\":\"PayOrderTest\",\"remark\":\"平台后台测试 submitOrder，免商户签名\",\"link_url\":\"\",\"is_iframe\":2,\"is_keep_alive\":2,\"is_hidden\":2,\"is_fixed_tab\":2,\"is_full_page\":2,\"sort\":72,\"status\":1}', NULL, 1, 1, '2026-06-10 23:48:49', '2026-06-10 23:48:49', NULL),
(40, 'admin', 'saiadmin', 'PUT', '/core/menu/update', '菜单数据修改', '156.146.51.87', '美国', '{\"id\":9043,\"parent_id\":9000,\"type\":2,\"component\":\"\\/payment\\/order-test\",\"name\":\"支付测试\",\"slug\":\"\",\"path\":\"order-test\",\"icon\":\"ri:flask-line\",\"code\":\"PayOrderTest\",\"remark\":\"平台后台测试 submitOrder，免商户签名\",\"link_url\":\"\",\"is_iframe\":2,\"is_keep_alive\":2,\"is_hidden\":1,\"is_fixed_tab\":2,\"is_full_page\":2,\"sort\":72,\"status\":1}', NULL, 1, 1, '2026-06-10 23:48:57', '2026-06-10 23:48:57', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_post`
--

CREATE TABLE `sa_system_post` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `name` varchar(50) DEFAULT NULL COMMENT '岗位名称',
  `code` varchar(100) DEFAULT NULL COMMENT '岗位代码',
  `sort` smallint UNSIGNED DEFAULT '0' COMMENT '排序',
  `status` smallint DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='岗位信息表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_post`
--

INSERT INTO `sa_system_post` (`id`, `name`, `code`, `sort`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, '司机岗', 'driver', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(2, '保安岗', 'security', 100, 1, '', 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_role`
--

CREATE TABLE `sa_system_role` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(64) NOT NULL COMMENT '角色名称',
  `code` varchar(64) NOT NULL COMMENT '角色标识(英文唯一)，如: hr_manager',
  `level` int DEFAULT '1' COMMENT '角色级别(1-100)：用于行政控制，不可操作级别>=自己的角色',
  `data_scope` tinyint DEFAULT '1' COMMENT '数据范围: 1全部, 2本部门及下属, 3本部门, 4仅本人, 5自定义',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `sort` int DEFAULT '100',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态: 1启用, 0禁用',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='角色表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_role`
--

INSERT INTO `sa_system_role` (`id`, `name`, `code`, `level`, `data_scope`, `remark`, `sort`, `status`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, '超级管理员', 'super_admin', 100, 1, '系统维护者，拥有所有权限', 100, 1, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(2, '集团总裁', 'ceo', 90, 1, '查看全集团数据', 100, 1, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(3, 'BG总裁', 'bg_president', 80, 2, '', 100, 1, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(4, '部门总经理', 'gm', 60, 2, '', 100, 1, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(5, '组长', 'team_leader', 30, 3, '', 100, 1, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL),
(6, '普通员工', 'staff', 10, 4, '', 100, 1, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_role_dept`
--

CREATE TABLE `sa_system_role_dept` (
  `id` bigint UNSIGNED NOT NULL,
  `role_id` bigint UNSIGNED NOT NULL,
  `dept_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='角色-自定义数据权限关联' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_role_menu`
--

CREATE TABLE `sa_system_role_menu` (
  `id` bigint UNSIGNED NOT NULL,
  `role_id` bigint UNSIGNED NOT NULL,
  `menu_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='角色权限关联' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_user`
--

CREATE TABLE `sa_system_user` (
  `id` int UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL COMMENT '登录账号',
  `password` varchar(255) NOT NULL COMMENT '加密密码',
  `realname` varchar(64) DEFAULT NULL COMMENT '真实姓名',
  `gender` varchar(10) DEFAULT NULL COMMENT '性别',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像',
  `email` varchar(128) DEFAULT NULL COMMENT '邮箱',
  `phone` varchar(20) DEFAULT NULL COMMENT '手机号',
  `signed` varchar(255) DEFAULT NULL COMMENT '个性签名',
  `dashboard` varchar(255) DEFAULT 'work' COMMENT '工作台',
  `dept_id` bigint UNSIGNED DEFAULT NULL COMMENT '主归属部门',
  `is_super` tinyint(1) DEFAULT '0' COMMENT '是否超级管理员: 1是(跳过权限检查), 0否',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态: 1启用, 0禁用',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `login_time` timestamp NULL DEFAULT NULL COMMENT '最后登录时间',
  `login_ip` varchar(45) DEFAULT NULL COMMENT '最后登录IP',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='用户表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_user`
--

INSERT INTO `sa_system_user` (`id`, `username`, `password`, `realname`, `gender`, `avatar`, `email`, `phone`, `signed`, `dashboard`, `dept_id`, `is_super`, `status`, `remark`, `login_time`, `login_ip`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 'admin', '$2y$10$wnixh48uDnaW/6D9EygDd.OHJK0vQY/4nHaTjMKBCVDBP2NiTatqS', '祭道之上', '2', 'https://api.starfusionx.com/storage/20260610/9d61daca475a7d2e2798bb9de5515d4398a2d474.png', 'saiadmin@admin.com', '15888888888', 'SaiAdmin是兼具设计美学与高效开发的后台系统!', 'statistics', 1, 1, 1, NULL, '2026-06-11 09:05:23', '203.160.86.40', 1, 1, '2026-01-01 00:00:00', '2026-06-11 17:05:23', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_user_post`
--

CREATE TABLE `sa_system_user_post` (
  `id` bigint UNSIGNED NOT NULL COMMENT '主键',
  `user_id` bigint UNSIGNED NOT NULL COMMENT '用户主键',
  `post_id` bigint UNSIGNED NOT NULL COMMENT '岗位主键'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='用户与岗位关联表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_system_user_role`
--

CREATE TABLE `sa_system_user_role` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `role_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='用户角色关联' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_system_user_role`
--

INSERT INTO `sa_system_user_role` (`id`, `user_id`, `role_id`) VALUES
(1, 1, 1);

-- --------------------------------------------------------

--
-- 表的结构 `sa_tool_crontab`
--

CREATE TABLE `sa_tool_crontab` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `name` varchar(100) DEFAULT NULL COMMENT '任务名称',
  `type` smallint DEFAULT '4' COMMENT '任务类型',
  `target` varchar(500) DEFAULT NULL COMMENT '调用任务字符串',
  `parameter` varchar(1000) DEFAULT NULL COMMENT '调用任务参数',
  `task_style` tinyint(1) DEFAULT NULL COMMENT '执行类型',
  `rule` varchar(32) DEFAULT NULL COMMENT '任务执行表达式',
  `singleton` smallint DEFAULT '1' COMMENT '是否单次执行 (1 是 2 不是)',
  `status` smallint DEFAULT '1' COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='定时任务信息表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_tool_crontab`
--

INSERT INTO `sa_tool_crontab` (`id`, `name`, `type`, `target`, `parameter`, `task_style`, `rule`, `singleton`, `status`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`) VALUES
(9101, '支付-商户通知重试', 3, '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 5, '*/10 * * * * *', 2, 1, '扫描 sa_pay_notify_log 到期通知并重发', 1, 1, '2026-06-09 23:29:36', '2026-06-09 23:29:36', NULL),
(9102, '支付-订单超时关闭', 3, '\\plugin\\paymentchannel\\app\\crontab\\OrderTimeoutCrontab', '{\"limit\":200}', 5, '*/60 * * * * *', 2, 1, '关闭 expire_time 已过的待支付代收订单', 1, 1, '2026-06-09 23:29:36', '2026-06-09 23:29:36', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_tool_crontab_log`
--

CREATE TABLE `sa_tool_crontab_log` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `crontab_id` int UNSIGNED DEFAULT NULL COMMENT '任务ID',
  `name` varchar(255) DEFAULT NULL COMMENT '任务名称',
  `target` varchar(500) DEFAULT NULL COMMENT '任务调用目标字符串',
  `parameter` varchar(1000) DEFAULT NULL COMMENT '任务调用参数',
  `exception_info` varchar(2000) DEFAULT NULL COMMENT '异常信息',
  `status` smallint DEFAULT '1' COMMENT '执行状态 (1成功 2失败)',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='定时任务执行日志表' ROW_FORMAT=DYNAMIC;

--
-- 转存表中的数据 `sa_tool_crontab_log`
--

INSERT INTO `sa_tool_crontab_log` (`id`, `crontab_id`, `name`, `target`, `parameter`, `exception_info`, `status`, `create_time`, `update_time`, `delete_time`) VALUES
(1, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:38:20', '2026-06-11 17:38:20', NULL),
(2, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:38:30', '2026-06-11 17:38:30', NULL),
(3, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:38:40', '2026-06-11 17:38:40', NULL),
(4, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:38:50', '2026-06-11 17:38:50', NULL),
(5, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:39:00', '2026-06-11 17:39:00', NULL),
(6, 9102, '支付-订单超时关闭', '\\plugin\\paymentchannel\\app\\crontab\\OrderTimeoutCrontab', '{\"limit\":200}', 'order_timeout closed=0, limit=200', 1, '2026-06-11 17:39:00', '2026-06-11 17:39:00', NULL),
(7, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:39:10', '2026-06-11 17:39:10', NULL),
(8, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:39:20', '2026-06-11 17:39:20', NULL),
(9, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:39:30', '2026-06-11 17:39:30', NULL),
(10, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:39:40', '2026-06-11 17:39:40', NULL),
(11, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:39:50', '2026-06-11 17:39:50', NULL),
(12, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:40:00', '2026-06-11 17:40:00', NULL),
(13, 9102, '支付-订单超时关闭', '\\plugin\\paymentchannel\\app\\crontab\\OrderTimeoutCrontab', '{\"limit\":200}', 'order_timeout closed=0, limit=200', 1, '2026-06-11 17:40:00', '2026-06-11 17:40:00', NULL),
(14, 9101, '支付-商户通知重试', '\\plugin\\paymentchannel\\app\\crontab\\NotifyRetryCrontab', '{\"limit\":100}', 'notify_retry processed=0, limit=100', 1, '2026-06-11 17:40:10', '2026-06-11 17:40:10', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sa_tool_generate_columns`
--

CREATE TABLE `sa_tool_generate_columns` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `table_id` int UNSIGNED DEFAULT NULL COMMENT '所属表ID',
  `column_name` varchar(200) DEFAULT NULL COMMENT '字段名称',
  `column_comment` varchar(255) DEFAULT NULL COMMENT '字段注释',
  `column_type` varchar(50) DEFAULT NULL COMMENT '字段类型',
  `default_value` varchar(50) DEFAULT NULL COMMENT '默认值',
  `is_pk` smallint DEFAULT '1' COMMENT '1 非主键 2 主键',
  `is_required` smallint DEFAULT '1' COMMENT '1 非必填 2 必填',
  `is_insert` smallint DEFAULT '1' COMMENT '1 非插入字段 2 插入字段',
  `is_edit` smallint DEFAULT '1' COMMENT '1 非编辑字段 2 编辑字段',
  `is_list` smallint DEFAULT '1' COMMENT '1 非列表显示字段 2 列表显示字段',
  `is_query` smallint DEFAULT '1' COMMENT '1 非查询字段 2 查询字段',
  `is_sort` smallint DEFAULT '1' COMMENT '1 非排序 2 排序',
  `query_type` varchar(100) DEFAULT 'eq' COMMENT '查询方式 eq 等于, neq 不等于, gt 大于, lt 小于, like 范围',
  `view_type` varchar(100) DEFAULT 'text' COMMENT '页面控件,text, textarea, password, select, checkbox, radio, date, upload, ma-upload(封装的上传控件)',
  `dict_type` varchar(200) DEFAULT NULL COMMENT '字典类型',
  `allow_roles` varchar(255) DEFAULT NULL COMMENT '允许查看该字段的角色',
  `options` varchar(1000) DEFAULT NULL COMMENT '字段其他设置',
  `sort` tinyint UNSIGNED DEFAULT '0' COMMENT '排序',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='代码生成业务字段表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `sa_tool_generate_tables`
--

CREATE TABLE `sa_tool_generate_tables` (
  `id` int UNSIGNED NOT NULL COMMENT '主键',
  `table_name` varchar(200) DEFAULT NULL COMMENT '表名称',
  `table_comment` varchar(500) DEFAULT NULL COMMENT '表注释',
  `stub` varchar(50) DEFAULT NULL COMMENT 'stub类型',
  `template` varchar(50) DEFAULT NULL COMMENT '模板名称',
  `namespace` varchar(255) DEFAULT NULL COMMENT '命名空间',
  `package_name` varchar(100) DEFAULT NULL COMMENT '控制器包名',
  `business_name` varchar(50) DEFAULT NULL COMMENT '业务名称',
  `class_name` varchar(50) DEFAULT NULL COMMENT '类名称',
  `menu_name` varchar(100) DEFAULT NULL COMMENT '生成菜单名',
  `belong_menu_id` int DEFAULT NULL COMMENT '所属菜单',
  `tpl_category` varchar(100) DEFAULT NULL COMMENT '生成类型,single 单表CRUD,tree 树表CRUD,parent_sub父子表CRUD',
  `generate_type` smallint DEFAULT '1' COMMENT '1 压缩包下载 2 生成到模块',
  `generate_path` varchar(100) DEFAULT 'saiadmin-artd' COMMENT '前端根目录',
  `generate_model` smallint DEFAULT '1' COMMENT '1 软删除 2 非软删除',
  `generate_menus` varchar(255) DEFAULT NULL COMMENT '生成菜单列表',
  `build_menu` smallint DEFAULT '1' COMMENT '是否构建菜单',
  `component_type` smallint DEFAULT '1' COMMENT '组件显示方式',
  `options` varchar(1500) DEFAULT NULL COMMENT '其他业务选项',
  `form_width` int DEFAULT '800' COMMENT '表单宽度',
  `is_full` tinyint(1) DEFAULT '1' COMMENT '是否全屏',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `source` varchar(255) DEFAULT NULL COMMENT '数据源',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='代码生成业务表' ROW_FORMAT=DYNAMIC;

--
-- 转储表的索引
--

--
-- 表的索引 `sa_article`
--
ALTER TABLE `sa_article`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_category_id` (`category_id`) USING BTREE;

--
-- 表的索引 `sa_article_banner`
--
ALTER TABLE `sa_article_banner`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- 表的索引 `sa_article_category`
--
ALTER TABLE `sa_article_category`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- 表的索引 `sa_pay_bank_card`
--
ALTER TABLE `sa_pay_bank_card`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_merchant_id` (`merchant_id`) USING BTREE;

--
-- 表的索引 `sa_pay_capital_flow`
--
ALTER TABLE `sa_pay_capital_flow`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_idempotent_key` (`idempotent_key`) USING BTREE,
  ADD KEY `idx_merchant_id` (`merchant_id`) USING BTREE,
  ADD KEY `idx_biz_no` (`biz_no`) USING BTREE;

--
-- 表的索引 `sa_pay_channel`
--
ALTER TABLE `sa_pay_channel`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_code` (`code`) USING BTREE,
  ADD KEY `idx_pay_type` (`pay_type`) USING BTREE;

--
-- 表的索引 `sa_pay_channel_log`
--
ALTER TABLE `sa_pay_channel_log`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_biz_no` (`biz_no`) USING BTREE,
  ADD KEY `idx_channel_id` (`channel_id`) USING BTREE;

--
-- 表的索引 `sa_pay_merchant`
--
ALTER TABLE `sa_pay_merchant`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_mch_id` (`mch_id`) USING BTREE,
  ADD UNIQUE KEY `uk_login_name` (`login_name`) USING BTREE;

--
-- 表的索引 `sa_pay_merchant_channel`
--
ALTER TABLE `sa_pay_merchant_channel`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_merchant_channel` (`merchant_id`,`channel_id`) USING BTREE;

--
-- 表的索引 `sa_pay_merchant_route`
--
ALTER TABLE `sa_pay_merchant_route`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_merchant_route` (`merchant_id`,`route_id`) USING BTREE,
  ADD KEY `idx_merchant_id` (`merchant_id`) USING BTREE,
  ADD KEY `idx_route_id` (`route_id`) USING BTREE;

--
-- 表的索引 `sa_pay_notify_log`
--
ALTER TABLE `sa_pay_notify_log`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_order_no` (`order_no`) USING BTREE,
  ADD KEY `idx_status` (`status`) USING BTREE;

--
-- 表的索引 `sa_pay_order`
--
ALTER TABLE `sa_pay_order`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_order_no` (`order_no`) USING BTREE,
  ADD UNIQUE KEY `uk_merchant_out_trade` (`merchant_id`,`out_trade_no`) USING BTREE,
  ADD KEY `idx_status` (`status`) USING BTREE,
  ADD KEY `idx_create_time` (`create_time`) USING BTREE,
  ADD KEY `idx_upstream_no` (`upstream_no`) USING BTREE;

--
-- 表的索引 `sa_pay_recharge`
--
ALTER TABLE `sa_pay_recharge`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_recharge_no` (`recharge_no`) USING BTREE,
  ADD KEY `idx_merchant_id` (`merchant_id`) USING BTREE;

--
-- 表的索引 `sa_pay_route`
--
ALTER TABLE `sa_pay_route`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_pay_type` (`pay_type`) USING BTREE;

--
-- 表的索引 `sa_pay_route_channel`
--
ALTER TABLE `sa_pay_route_channel`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_route_id` (`route_id`) USING BTREE,
  ADD KEY `idx_channel_id` (`channel_id`) USING BTREE;

--
-- 表的索引 `sa_pay_transfer`
--
ALTER TABLE `sa_pay_transfer`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_transfer_no` (`transfer_no`) USING BTREE,
  ADD UNIQUE KEY `uk_merchant_out_trade` (`merchant_id`,`out_trade_no`) USING BTREE,
  ADD KEY `idx_status` (`status`) USING BTREE;

--
-- 表的索引 `sa_pay_withdraw`
--
ALTER TABLE `sa_pay_withdraw`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_withdraw_no` (`withdraw_no`) USING BTREE,
  ADD KEY `idx_merchant_id` (`merchant_id`) USING BTREE,
  ADD KEY `idx_status` (`status`) USING BTREE;

--
-- 表的索引 `sa_system_attachment`
--
ALTER TABLE `sa_system_attachment`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `hash` (`hash`) USING BTREE,
  ADD KEY `idx_url` (`url`) USING BTREE,
  ADD KEY `idx_create_time` (`create_time`) USING BTREE,
  ADD KEY `idx_category_id` (`category_id`) USING BTREE;

--
-- 表的索引 `sa_system_category`
--
ALTER TABLE `sa_system_category`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `pid` (`parent_id`) USING BTREE,
  ADD KEY `sort` (`sort`) USING BTREE;

--
-- 表的索引 `sa_system_config`
--
ALTER TABLE `sa_system_config`
  ADD PRIMARY KEY (`id`,`key`) USING BTREE,
  ADD KEY `group_id` (`group_id`) USING BTREE;

--
-- 表的索引 `sa_system_config_group`
--
ALTER TABLE `sa_system_config_group`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- 表的索引 `sa_system_dept`
--
ALTER TABLE `sa_system_dept`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_parent_id` (`parent_id`) USING BTREE,
  ADD KEY `idx_path` (`level`) USING BTREE;

--
-- 表的索引 `sa_system_dict_data`
--
ALTER TABLE `sa_system_dict_data`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `type_id` (`type_id`) USING BTREE,
  ADD KEY `idx_code` (`code`) USING BTREE;

--
-- 表的索引 `sa_system_dict_type`
--
ALTER TABLE `sa_system_dict_type`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_code` (`code`) USING BTREE,
  ADD KEY `idx_name` (`name`) USING BTREE;

--
-- 表的索引 `sa_system_login_log`
--
ALTER TABLE `sa_system_login_log`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `username` (`username`) USING BTREE,
  ADD KEY `idx_create_time` (`create_time`) USING BTREE,
  ADD KEY `idx_login_time` (`login_time`) USING BTREE;

--
-- 表的索引 `sa_system_mail`
--
ALTER TABLE `sa_system_mail`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_create_time` (`create_time`) USING BTREE;

--
-- 表的索引 `sa_system_menu`
--
ALTER TABLE `sa_system_menu`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_parent_id` (`parent_id`) USING BTREE,
  ADD KEY `idx_slug` (`slug`) USING BTREE;

--
-- 表的索引 `sa_system_oper_log`
--
ALTER TABLE `sa_system_oper_log`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `username` (`username`) USING BTREE,
  ADD KEY `idx_create_time` (`create_time`) USING BTREE;

--
-- 表的索引 `sa_system_post`
--
ALTER TABLE `sa_system_post`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- 表的索引 `sa_system_role`
--
ALTER TABLE `sa_system_role`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_slug` (`code`) USING BTREE;

--
-- 表的索引 `sa_system_role_dept`
--
ALTER TABLE `sa_system_role_dept`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_role_id` (`role_id`) USING BTREE,
  ADD KEY `idx_dept_id` (`dept_id`) USING BTREE;

--
-- 表的索引 `sa_system_role_menu`
--
ALTER TABLE `sa_system_role_menu`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_menu_id` (`menu_id`) USING BTREE,
  ADD KEY `idx_role_id` (`role_id`) USING BTREE;

--
-- 表的索引 `sa_system_user`
--
ALTER TABLE `sa_system_user`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `uk_username` (`username`) USING BTREE,
  ADD KEY `idx_dept_id` (`dept_id`) USING BTREE;

--
-- 表的索引 `sa_system_user_post`
--
ALTER TABLE `sa_system_user_post`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_user_id` (`user_id`) USING BTREE,
  ADD KEY `idx_post_id` (`post_id`) USING BTREE;

--
-- 表的索引 `sa_system_user_role`
--
ALTER TABLE `sa_system_user_role`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `idx_role_id` (`role_id`) USING BTREE,
  ADD KEY `idx_user_id` (`user_id`) USING BTREE;

--
-- 表的索引 `sa_tool_crontab`
--
ALTER TABLE `sa_tool_crontab`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- 表的索引 `sa_tool_crontab_log`
--
ALTER TABLE `sa_tool_crontab_log`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- 表的索引 `sa_tool_generate_columns`
--
ALTER TABLE `sa_tool_generate_columns`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- 表的索引 `sa_tool_generate_tables`
--
ALTER TABLE `sa_tool_generate_tables`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `sa_article`
--
ALTER TABLE `sa_article`
  MODIFY `id` int NOT NULL AUTO_INCREMENT COMMENT '编号';

--
-- 使用表AUTO_INCREMENT `sa_article_banner`
--
ALTER TABLE `sa_article_banner`
  MODIFY `id` int NOT NULL AUTO_INCREMENT COMMENT '编号';

--
-- 使用表AUTO_INCREMENT `sa_article_category`
--
ALTER TABLE `sa_article_category`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号';

--
-- 使用表AUTO_INCREMENT `sa_pay_bank_card`
--
ALTER TABLE `sa_pay_bank_card`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_capital_flow`
--
ALTER TABLE `sa_pay_capital_flow`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_channel`
--
ALTER TABLE `sa_pay_channel`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=14;

--
-- 使用表AUTO_INCREMENT `sa_pay_channel_log`
--
ALTER TABLE `sa_pay_channel_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_merchant`
--
ALTER TABLE `sa_pay_merchant`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=18;

--
-- 使用表AUTO_INCREMENT `sa_pay_merchant_channel`
--
ALTER TABLE `sa_pay_merchant_channel`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_merchant_route`
--
ALTER TABLE `sa_pay_merchant_route`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_notify_log`
--
ALTER TABLE `sa_pay_notify_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_order`
--
ALTER TABLE `sa_pay_order`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_recharge`
--
ALTER TABLE `sa_pay_recharge`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=7;

--
-- 使用表AUTO_INCREMENT `sa_pay_route`
--
ALTER TABLE `sa_pay_route`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=6;

--
-- 使用表AUTO_INCREMENT `sa_pay_route_channel`
--
ALTER TABLE `sa_pay_route_channel`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_transfer`
--
ALTER TABLE `sa_pay_transfer`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_pay_withdraw`
--
ALTER TABLE `sa_pay_withdraw`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_system_attachment`
--
ALTER TABLE `sa_system_attachment`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=5;

--
-- 使用表AUTO_INCREMENT `sa_system_category`
--
ALTER TABLE `sa_system_category`
  MODIFY `id` int NOT NULL AUTO_INCREMENT COMMENT '分类ID', AUTO_INCREMENT=6;

--
-- 使用表AUTO_INCREMENT `sa_system_config`
--
ALTER TABLE `sa_system_config`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号', AUTO_INCREMENT=302;

--
-- 使用表AUTO_INCREMENT `sa_system_config_group`
--
ALTER TABLE `sa_system_config_group`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=4;

--
-- 使用表AUTO_INCREMENT `sa_system_dept`
--
ALTER TABLE `sa_system_dept`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1114;

--
-- 使用表AUTO_INCREMENT `sa_system_dict_data`
--
ALTER TABLE `sa_system_dict_data`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=50;

--
-- 使用表AUTO_INCREMENT `sa_system_dict_type`
--
ALTER TABLE `sa_system_dict_type`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=24;

--
-- 使用表AUTO_INCREMENT `sa_system_login_log`
--
ALTER TABLE `sa_system_login_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=21;

--
-- 使用表AUTO_INCREMENT `sa_system_mail`
--
ALTER TABLE `sa_system_mail`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号';

--
-- 使用表AUTO_INCREMENT `sa_system_menu`
--
ALTER TABLE `sa_system_menu`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9098;

--
-- 使用表AUTO_INCREMENT `sa_system_oper_log`
--
ALTER TABLE `sa_system_oper_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=41;

--
-- 使用表AUTO_INCREMENT `sa_system_post`
--
ALTER TABLE `sa_system_post`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=87;

--
-- 使用表AUTO_INCREMENT `sa_system_role`
--
ALTER TABLE `sa_system_role`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- 使用表AUTO_INCREMENT `sa_system_role_dept`
--
ALTER TABLE `sa_system_role_dept`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `sa_system_role_menu`
--
ALTER TABLE `sa_system_role_menu`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `sa_system_user`
--
ALTER TABLE `sa_system_user`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

--
-- 使用表AUTO_INCREMENT `sa_system_user_post`
--
ALTER TABLE `sa_system_user_post`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_system_user_role`
--
ALTER TABLE `sa_system_user_role`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- 使用表AUTO_INCREMENT `sa_tool_crontab`
--
ALTER TABLE `sa_tool_crontab`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=9103;

--
-- 使用表AUTO_INCREMENT `sa_tool_crontab_log`
--
ALTER TABLE `sa_tool_crontab_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键', AUTO_INCREMENT=15;

--
-- 使用表AUTO_INCREMENT `sa_tool_generate_columns`
--
ALTER TABLE `sa_tool_generate_columns`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';

--
-- 使用表AUTO_INCREMENT `sa_tool_generate_tables`
--
ALTER TABLE `sa_tool_generate_tables`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键';
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
