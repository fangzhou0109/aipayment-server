-- 商户服务端「程序化代付 API」支持：提现单复用为代付单，新增来源/商户单号/下游回调地址
-- source        ：区分单据来源（1=商户门户人工提现，2=商户 API 代付下单）
-- out_biz_no    ：商户侧代付单号（API 幂等键，同商户内唯一；UI 提现为 NULL）
-- notify_url    ：下游商户异步通知地址（API 代付出款成功/失败后回调）
ALTER TABLE `sa_pay_withdraw`
    ADD COLUMN `source` smallint(6) NOT NULL DEFAULT 1 COMMENT '单据来源 (1商户提现 2API代付)' AFTER `mch_id`,
    ADD COLUMN `out_biz_no` varchar(64) NULL DEFAULT NULL COMMENT '商户代付单号（API 幂等键）' AFTER `withdraw_no`,
    ADD COLUMN `notify_url` varchar(500) NULL DEFAULT NULL COMMENT '下游商户异步通知地址（API 代付）' AFTER `branch_name`;

-- 幂等：同商户 + 同商户代付单号唯一（out_biz_no 为 NULL 的人工提现不参与约束，MySQL 允许多 NULL）
ALTER TABLE `sa_pay_withdraw`
    ADD UNIQUE INDEX `uk_merchant_out_biz_no`(`merchant_id`, `out_biz_no`) USING BTREE;
