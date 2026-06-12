-- 商户门户头像字段（sa_pay_merchant.avatar）
-- 执行：在目标库运行本脚本一次；新环境亦可合并进 paymentchannel.sql 全量建表

ALTER TABLE `sa_pay_merchant`
  ADD COLUMN `avatar` varchar(500) NULL DEFAULT NULL COMMENT '商户门户头像URL' AFTER `name`;
