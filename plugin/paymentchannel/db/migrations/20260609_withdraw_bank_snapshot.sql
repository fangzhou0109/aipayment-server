-- 提现单落库收款银行卡快照（申请时固化，避免后续改卡影响历史单据）
ALTER TABLE `sa_pay_withdraw`
    ADD COLUMN `account_name` varchar(100) NULL DEFAULT NULL COMMENT '收款人姓名（申请时快照）' AFTER `bank_card_id`,
    ADD COLUMN `account_no` varchar(64) NULL DEFAULT NULL COMMENT '收款银行卡号（申请时快照）' AFTER `account_name`,
    ADD COLUMN `bank_name` varchar(100) NULL DEFAULT NULL COMMENT '开户银行（申请时快照）' AFTER `account_no`,
    ADD COLUMN `bank_code` varchar(32) NULL DEFAULT NULL COMMENT '银行编码（申请时快照）' AFTER `bank_name`,
    ADD COLUMN `branch_name` varchar(255) NULL DEFAULT NULL COMMENT '开户支行（申请时快照）' AFTER `bank_code`;
