-- 商户「API 代付」收款人直传支持：下游用户在下游平台提现，每单收款人/卡号/手机号都不同，
-- 由下游平台随 /pay/transfer 请求直传收款人信息，落库为本单快照，不再强依赖预绑银行卡。
-- account_phone：收款人手机号（申请时快照，API 直传代付；UI 人工提现可为 NULL）
ALTER TABLE `sa_pay_withdraw`
    ADD COLUMN `account_phone` varchar(20) NULL DEFAULT NULL COMMENT '收款人手机号（申请时快照，API 直传代付）' AFTER `branch_name`;

-- 兼容请求直传收款人：bank_card_id 允许为空（仅预绑卡场景才有值）
ALTER TABLE `sa_pay_withdraw`
    MODIFY COLUMN `bank_card_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '收款银行卡ID（预绑卡场景；API 直传代付为 NULL，展示/代付以快照字段为准）';
