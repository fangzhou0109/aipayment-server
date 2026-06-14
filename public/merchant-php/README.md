# SaiPayment 商户 PHP 对接 Demo

面向 PHP 技术栈商户的**可运行**代收对接示例，含 Web 控制台、签名库与网关直连测试。签名规则与平台 `SignService` / 历史 GpayDome **字节级一致**。

## 目录结构

```
demo/merchant-php/
├── config.example.php   # 配置模板（复制为 config.php）
├── index.php            # Web 控制台（概览 / 下单 / 查单 / 签名 / 回调 / 文档）
├── assets/
│   ├── console.css
│   └── console.js
├── submit_order.php     # 下单 API → POST {gateway_base}/submitOrder
├── query_order.php      # 查单 API → POST {gateway_base}/query
├── health.php           # 网关健康检查 + 配置摘要
├── build_sign.php       # 生成 MD5 待签串与 curl 示例
├── notify_logs.php      # 读取 notify 演示日志
├── notify_url.php       # 异步通知接收（须回 SUCCESS）
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
| 签名工具 | POST `build_sign.php`，输出待签串与 curl |
| 回调日志 | GET `notify_logs.php`，解析 `logs/notify.log` |
| 对接文档 | 参数与签名规则摘要 |

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

## 安全提示

- **不要**将 `config.php` 提交到公开仓库
- 生产环境 notify 处理须**幂等**（同一 `order_no` 只入账一次）
- RSA 模式：请求用商户私钥签名，异步通知用**平台 RSA 公钥**验签

## 相关文档

- 商户门户 → **API 对接** 页（在线文档与沙箱测试）
- 仓库根目录 `README.md` Phase 3 网关说明
