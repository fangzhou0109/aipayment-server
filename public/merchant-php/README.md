# SaiPayment 商户 PHP 对接 Demo

面向 PHP 技术栈商户的**可运行**代收 + 代付（提现）对接示例，含 Web 控制台、签名库与网关直连测试。签名规则与平台 `SignService` / 历史 GpayDome **字节级一致**。

## 目录结构

```
demo/merchant-php/
├── config.example.php   # 配置模板（复制为 config.php）
├── index.php            # Web 控制台（概览 / 下单 / 查单 / 签名 / 回调 / 文档）
├── assets/
│   ├── console.css
│   └── console.js
├── submit_order.php     # 代收下单 API → POST {gateway_base}/submitOrder
├── query_order.php      # 代收查单 API → POST {gateway_base}/query
├── submit_transfer.php  # 代付下单 API → POST {gateway_base}/transfer
├── query_transfer.php   # 代付查单 API → POST {gateway_base}/transferQuery
├── health.php           # 网关健康检查 + 配置摘要
├── build_sign.php       # 生成 MD5 待签串与 curl 示例
├── notify_logs.php      # 读取 notify 演示日志（?type=transfer 读代付日志）
├── notify_url.php       # 代收异步通知接收（须回 SUCCESS）
├── transfer_notify_url.php # 代付异步通知接收（须回 SUCCESS）
├── return_url.php       # 同步跳转页
├── lib/
│   ├── PaySign.php      # 签名/验签（建议拷贝到生产代码）
│   ├── HttpClient.php   # HTTP GET/POST 工具
│   └── bootstrap.php    # 配置加载与公共函数
└── logs/                # notify 演示日志（运行时生成，已 gitignore）
```

## 使用步骤

1. `cp config.example.php config.php`
2. 填写 `gateway_base`（**须含反代前缀**，如 `https://api.starfusionx.com/prod/pay`）、`mch_id`、`secret_key`、`notify_url`、`return_url`
3. 将 Demo 部署到 **公网可访问** 的 PHP 环境（或 `php -S` + 内网穿透）
4. 平台若启用 IP 白名单，添加**服务器出站 IP**
5. 浏览器打开 `index.php`，在控制台完成下单、查单与签名调试

## Web 控制台 Tab

| Tab | 说明 |
|-----|------|
| 概览 | 配置脱敏展示、网关 `/health` 连通性探测 |
| 测试下单 | 表单 POST `submit_order.php`，展示请求/响应与 pay_url |
| 测试查单 | POST `query_order.php`，支持记住最近下单单号 |
| 测试代付 | 代付下单 POST `submit_transfer.php` + 代付查单 POST `query_transfer.php` |
| 签名工具 | POST `build_sign.php`，输出待签串与 curl |
| 回调日志 | GET `notify_logs.php`，代收/代付可切换 |
| 对接文档 | 代收 + 代付参数与签名规则摘要 |

## 与门户「接口测试」的区别

| 项目 | 本 PHP Demo | 商户门户接口测试 |
|------|-------------|------------------|
| 入口 | `index.php` 控制台 | `/merchant/integration` |
| 后端 | 直连支付网关 `/prod/pay/*` | `/mapi/integration/*` 沙箱 |
| 签名 | 真实 MD5/RSA | 门户代签，免网关 |

二者 **URL 不同**，`config.php` 的 `gateway_base` 须与门户「API 对接」页网关基址一致。

## 与旧版 Demo 差异

| 项目 | 本 Demo | 旧 GpayDome |
|------|---------|-------------|
| 查单地址 | `/pay/query` | `/pay/selOrder` |
| 查单签名 | 必须带 `time` + `sign` | 旧示例不完整 |
| 响应格式 | `{ code:200, data:{ pay_url,... } }` | 部分场景直接返回 URL |

## 支付类型 pay_type

| 值 | 含义 |
|----|------|
| 1 | 支付宝 PC |
| 2 | 支付宝 H5 |
| 3 | 微信 PC |
| 4 | 微信 H5 |
| 5 | 银联快捷 |
| 6 | 银联扫码 |
| 7 | 其他 |

以商户门户「代收通道」中已开通的 `pay_type` 为准。

## 代付（提现）对接

下游商户服务器发起出款。鉴权与代收一致（`mch_id` + `time` + `sign`，签名规则完全相同）。

> **收款人信息随请求直传** —— 下游用户在下游平台提现，每单的收款人姓名/卡号/手机号都不同，由下游平台随 `/pay/transfer` 请求直传（`account_no` 必填，`account_name` 可选），无需预先绑卡。代付要求商户有可用余额且已授权代付通道。

### 代付下单 `POST /pay/transfer`

| 参数 | 必填 | 说明 |
|------|------|------|
| mch_id | 是 | 商户号 |
| out_biz_no | 是 | 商户代付单号，同商户唯一（幂等键，重复提交不重复出款） |
| money | 是 | 出款金额，单位**分**（字符串） |
| account_name | 可选 | 收款人姓名（随单直传；缅甸钱包/手机号代付等可留空） |
| account_no | 直传必填 | 收款账号/钱包号/手机号（与 bank_card_id 二选一） |
| bank_name | 否 | 开户银行名称 |
| bank_code | 否 | 银行编码（部分通道必填） |
| branch_name | 否 | 开户支行 |
| account_phone | 否 | 收款人手机号（落库存档） |
| bank_card_id | 二选一 | 预绑卡 ID（商户自有收款卡；与直传字段二选一） |
| notify_url | 否 | 代付结果异步回调地址（留空用商户默认） |
| time | 是 | Unix 时间戳（秒） |
| client_ip | 否 | 用户 IP |
| sign / sign_type | 是 | 签名（规则同代收） |

成功返回 `code=200`，`data` 含 `withdraw_no`（平台代付单号）、`amount`/`fee`/`real_amount`、`status` 与 `status_text`。手续费由平台按通道计算，`real_amount` 为实际到账。

### 代付查单 `POST /pay/transferQuery`

| 参数 | 必填 | 说明 |
|------|------|------|
| mch_id | 是 | 商户号 |
| out_biz_no | 是 | 商户代付单号 |
| time | 是 | Unix 时间戳 |
| client_ip | 否 | 客户端 IP |
| sign / sign_type | 是 | 签名 |

### 代付异步通知（平台 → `transfer_notify_url`）

| 参数 | 说明 |
|------|------|
| out_biz_no | 商户代付单号 |
| transfer_no | 平台代付单号 |
| money | 金额（分） |
| mch_id | 商户号 |
| status | `success`=出款成功 / `fail`=出款失败 |
| reason | 失败原因（status=fail 时可能附带） |
| time / sign / sign_type | 时间与签名 |

验签通过后须响应纯文本 `SUCCESS`，否则平台重试。处理须**幂等**；`status=fail` 时应把出款金额退回给提现用户。

### 代付状态码 status_text

| 值 | 含义 | 终态 |
|----|------|------|
| pending | 待审核（金额超阈值转人工） | 否 |
| approved | 审核通过待下发 | 否 |
| paying | 代付中（已提交上游，等回调） | 否 |
| success | 出款成功 | 是 |
| fail | 出款失败（已退款解冻） | 是 |
| rejected | 审核拒绝（已退款解冻） | 是 |

下单后多为 `pending` 或 `paying`，最终结果以异步通知/查单的终态为准。

## 安全提示

- **不要**将 `config.php` 提交到公开仓库
- 生产环境 notify 处理须**幂等**（同一 `order_no` 只入账一次）
- RSA 模式：请求用商户私钥签名，异步通知用**平台 RSA 公钥**验签

## 相关文档

- 商户门户 → **API 对接** 页（在线文档与沙箱测试）
- 仓库根目录 `README.md` Phase 3 网关说明
