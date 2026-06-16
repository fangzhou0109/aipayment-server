<?php
/**
 * 商户 PHP 对接 Demo — 控制台入口
 *
 * Tab：概览 / 测试下单 / 测试查单 / 签名工具 / 回调日志 / 对接文档
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$config = demo_load_config();
$configOk = $config !== null;
$configPreview = demo_config_preview();
$payTypeMap = demo_pay_type_map();
$gatewayBase = $configOk ? rtrim((string) ($config['gateway_base'] ?? ''), '/') : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>商户 PHP 对接 Demo</title>
  <link rel="stylesheet" href="assets/console.css">
</head>
<body>
  <header class="app-header">
    <h1>商户 PHP 对接 Demo</h1>
    <p class="subtitle">纯 PHP 7.4+ / 8.x · 签名与平台 SignService 一致 · 直连网关（非门户 /mapi 沙箱）</p>
  </header>

  <main class="app-body">
    <?php if (!$configOk) : ?>
      <div class="alert alert-warn">
        <strong>尚未配置：</strong>请复制 <code>config.example.php</code> 为 <code>config.php</code>，
        填写 <code>gateway_base</code>（须含反代前缀，如 <code>https://api.starfusionx.com/prod/pay</code>）、
        <code>mch_id</code>、<code>secret_key</code> 与回调 URL。
      </div>
    <?php else : ?>
      <div class="alert alert-ok">已加载 <code>config.php</code>，网关基址：<code><?= htmlspecialchars($gatewayBase, ENT_QUOTES, 'UTF-8') ?></code></div>
    <?php endif; ?>

    <div class="alert alert-info">
      <strong>注意：</strong>本 Demo 请求真实支付网关；商户门户「接口测试」走 <code>/mapi/integration/*</code> 沙箱，URL 不同。
      代收对接传 <code>pay_type</code>（1~7），勿传内部通道编码。若启用 IP 白名单，须添加<strong>服务器出站 IP</strong>。
    </div>

    <nav class="tabs" role="tablist">
      <button type="button" class="tab-btn active" data-tab="overview">概览</button>
      <button type="button" class="tab-btn" data-tab="submit">测试下单</button>
      <button type="button" class="tab-btn" data-tab="query">测试查单</button>
      <button type="button" class="tab-btn" data-tab="transfer">测试代付</button>
      <button type="button" class="tab-btn" data-tab="sign">签名工具</button>
      <button type="button" class="tab-btn" data-tab="notify">回调日志</button>
      <button type="button" class="tab-btn" data-tab="docs">对接文档</button>
    </nav>

    <!-- 概览 -->
    <section id="panel-overview" class="panel active">
      <div class="card">
        <h2>环境与网关</h2>
        <div id="overview-content">
          <?php if ($configOk && $configPreview) : ?>
            <table class="kv-table">
              <tr><th>网关基址</th><td class="mono"><?= htmlspecialchars($configPreview['gateway_base'], ENT_QUOTES, 'UTF-8') ?></td></tr>
              <tr><th>商户号</th><td class="mono"><?= htmlspecialchars($configPreview['mch_id'], ENT_QUOTES, 'UTF-8') ?></td></tr>
              <tr><th>密钥</th><td class="mono"><?= htmlspecialchars($configPreview['secret_key_masked'], ENT_QUOTES, 'UTF-8') ?></td></tr>
              <tr><th>签名类型</th><td><?= (int) $configPreview['sign_type'] === 2 ? 'RSA (2)' : 'MD5 (1)' ?></td></tr>
              <tr><th>notify_url</th><td class="mono"><?= htmlspecialchars($configPreview['notify_url'], ENT_QUOTES, 'UTF-8') ?></td></tr>
            </table>
            <p class="loading-inline" style="margin-top:12px">正在探测网关…</p>
          <?php else : ?>
            <p class="empty-text">配置完成后将显示网关连通性探测结果。</p>
          <?php endif; ?>
        </div>
        <div class="btn-row" style="margin-top:14px">
          <button type="button" class="btn btn-secondary" onclick="document.querySelector('[data-tab=overview]').click()">刷新探测</button>
        </div>
      </div>

      <div class="card">
        <h2>源码文件</h2>
        <table class="kv-table">
          <tr><th><code>lib/PaySign.php</code></th><td>签名/验签，可拷贝至生产项目</td></tr>
          <tr><th><code>submit_order.php</code></th><td>POST <?= htmlspecialchars($gatewayBase ? $gatewayBase . '/submitOrder' : '/pay/submitOrder', ENT_QUOTES, 'UTF-8') ?></td></tr>
          <tr><th><code>query_order.php</code></th><td>POST <?= htmlspecialchars($gatewayBase ? $gatewayBase . '/query' : '/pay/query', ENT_QUOTES, 'UTF-8') ?></td></tr>
          <tr><th><code>notify_url.php</code></th><td>异步通知，验签后响应 <code>SUCCESS</code></td></tr>
          <tr><th><code>return_url.php</code></th><td>支付完成浏览器跳转</td></tr>
          <tr><th><code>submit_transfer.php</code></th><td>代付下单，POST <?= htmlspecialchars($gatewayBase ? $gatewayBase . '/transfer' : '/pay/transfer', ENT_QUOTES, 'UTF-8') ?></td></tr>
          <tr><th><code>query_transfer.php</code></th><td>代付查单，POST <?= htmlspecialchars($gatewayBase ? $gatewayBase . '/transferQuery' : '/pay/transferQuery', ENT_QUOTES, 'UTF-8') ?></td></tr>
          <tr><th><code>transfer_notify_url.php</code></th><td>代付异步通知，验签后响应 <code>SUCCESS</code></td></tr>
          <tr><th><code>health.php</code></th><td>网关健康检查 API（控制台用）</td></tr>
          <tr><th><code>build_sign.php</code></th><td>生成待签串与 curl 示例</td></tr>
          <tr><th><code>notify_logs.php</code></th><td>读取 <code>logs/notify.log</code></td></tr>
        </table>
      </div>
    </section>

    <!-- 测试下单 -->
    <section id="panel-submit" class="panel">
      <div class="card">
        <h2>测试下单</h2>
        <form id="form-submit" class="form-grid">
          <div class="form-row">
            <label for="submit-pay-type">支付类型 pay_type</label>
            <select id="submit-pay-type" name="pay_type">
              <?php foreach ($payTypeMap as $val => $label) : ?>
                <option value="<?= (int) $val ?>"<?= $configOk && (int) ($configPreview['default_pay_type'] ?? 3) === (int) $val ? ' selected' : '' ?>>
                  <?= (int) $val ?> · <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="form-hint">以商户门户「代收通道」中已开通的 pay_type 为准</p>
          </div>
          <div class="form-row">
            <label for="submit-amount">金额（元）</label>
            <input type="number" id="submit-amount" name="amount" value="1" min="0.01" step="0.01" required>
            <p class="form-hint">提交时自动转为分（money 字段）</p>
          </div>
          <div class="form-row">
            <label for="submit-order-id">商户订单号</label>
            <div>
              <input type="text" id="submit-order-id" name="order_id" placeholder="留空自动生成" style="max-width:320px">
              <div class="btn-row" style="margin-top:6px">
                <button type="button" class="btn btn-secondary" id="btn-gen-order-id">生成单号</button>
              </div>
            </div>
          </div>
          <div class="form-row">
            <label for="submit-notify">notify_url</label>
            <input type="url" id="submit-notify" name="notify_url" placeholder="留空使用 config.php 默认值"
              value="<?= $configOk ? htmlspecialchars((string) $configPreview['notify_url'], ENT_QUOTES, 'UTF-8') : '' ?>">
          </div>
          <div class="form-row">
            <label for="submit-return">return_url</label>
            <input type="url" id="submit-return" name="return_url" placeholder="留空使用 config.php 默认值"
              value="<?= $configOk ? htmlspecialchars((string) $configPreview['return_url'], ENT_QUOTES, 'UTF-8') : '' ?>">
          </div>
          <div class="form-row">
            <label for="submit-commodity">商品名称</label>
            <input type="text" id="submit-commodity" name="commodity_name" value="PHP Demo 测试商品">
          </div>
          <div class="form-row">
            <label for="submit-extra">extra</label>
            <input type="text" id="submit-extra" name="extra" value="php_demo">
          </div>
          <div class="form-row">
            <label></label>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary" id="btn-submit">提交下单</button>
            </div>
          </div>
        </form>
        <div id="submit-result"></div>
      </div>
    </section>

    <!-- 测试查单 -->
    <section id="panel-query" class="panel">
      <div class="card">
        <h2>测试查单</h2>
        <form id="form-query" class="form-grid">
          <div class="form-row">
            <label for="query-order-id">商户订单号</label>
            <div>
              <input type="text" id="query-order-id" name="order_id" required placeholder="out_trade_no / order_id" style="max-width:360px">
              <div class="btn-row" style="margin-top:6px">
                <button type="button" class="btn btn-secondary" id="btn-fill-last-order">填入最近下单单号</button>
              </div>
            </div>
          </div>
          <div class="form-row">
            <label></label>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary" id="btn-query">查询订单</button>
            </div>
          </div>
        </form>
        <div id="query-result"></div>
      </div>
    </section>

    <!-- 测试代付 -->
    <section id="panel-transfer" class="panel">
      <div class="card">
        <h2>测试代付（提现下单）</h2>
        <div class="alert alert-info" style="margin-bottom:14px">
          收款人信息<strong>随请求直传</strong>（下游用户提现，每单户名/卡号/手机号都不同）：填
          <code>account_name</code> + <code>account_no</code> 即可，无需预先绑卡。代付需商户有可用余额且已授权代付通道。
        </div>
        <form id="form-transfer" class="form-grid">
          <div class="form-row">
            <label for="transfer-amount">金额（元）</label>
            <input type="number" id="transfer-amount" name="amount" value="1" min="0.01" step="0.01" required>
            <p class="form-hint">提交时自动转为分（money 字段）；实际到账 = 金额 − 手续费</p>
          </div>
          <div class="form-row">
            <label for="transfer-account-name">收款人姓名 account_name</label>
            <input type="text" id="transfer-account-name" name="account_name" placeholder="收款人真实姓名" required style="max-width:320px">
          </div>
          <div class="form-row">
            <label for="transfer-account-no">收款账号 account_no</label>
            <input type="text" id="transfer-account-no" name="account_no" placeholder="银行卡号 / 钱包账号" required style="max-width:320px">
          </div>
          <div class="form-row">
            <label for="transfer-bank-name">开户银行 bank_name</label>
            <input type="text" id="transfer-bank-name" name="bank_name" placeholder="选填，如 中国工商银行" style="max-width:320px">
          </div>
          <div class="form-row">
            <label for="transfer-account-phone">收款人手机号 account_phone</label>
            <input type="text" id="transfer-account-phone" name="account_phone" placeholder="选填" style="max-width:320px">
          </div>
          <div class="form-row">
            <label for="transfer-bank-card-id">bank_card_id（可选）</label>
            <input type="number" id="transfer-bank-card-id" name="bank_card_id" min="1" placeholder="预绑卡场景才填，留空走直传" style="max-width:320px">
            <p class="form-hint">仅当用商户门户预绑的自有收款卡时填写；与上面直传字段二选一</p>
          </div>
          <div class="form-row">
            <label for="transfer-out-biz-no">商户代付单号 out_biz_no</label>
            <div>
              <input type="text" id="transfer-out-biz-no" name="out_biz_no" placeholder="留空自动生成" style="max-width:320px">
              <div class="btn-row" style="margin-top:6px">
                <button type="button" class="btn btn-secondary" id="btn-gen-out-biz-no">生成单号</button>
              </div>
              <p class="form-hint">同商户幂等键：重复提交不会重复出款</p>
            </div>
          </div>
          <div class="form-row">
            <label for="transfer-notify">notify_url（代付回调）</label>
            <input type="url" id="transfer-notify" name="notify_url" placeholder="留空使用 config.php 的 transfer_notify_url"
              value="<?= $configOk ? htmlspecialchars((string) ($configPreview['transfer_notify_url'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>">
          </div>
          <div class="form-row">
            <label></label>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary" id="btn-transfer">提交代付</button>
            </div>
          </div>
        </form>
        <div id="transfer-result"></div>
      </div>

      <div class="card">
        <h2>代付查单</h2>
        <form id="form-transfer-query" class="form-grid">
          <div class="form-row">
            <label for="transfer-query-out-biz-no">商户代付单号</label>
            <div>
              <input type="text" id="transfer-query-out-biz-no" name="out_biz_no" required placeholder="out_biz_no" style="max-width:360px">
              <div class="btn-row" style="margin-top:6px">
                <button type="button" class="btn btn-secondary" id="btn-fill-last-transfer">填入最近代付单号</button>
              </div>
            </div>
          </div>
          <div class="form-row">
            <label></label>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary" id="btn-transfer-query">查询代付</button>
            </div>
          </div>
        </form>
        <div id="transfer-query-result"></div>
      </div>
    </section>

    <!-- 签名工具 -->
    <section id="panel-sign" class="panel">
      <div class="card">
        <h2>签名工具（MD5）</h2>
        <p style="color:var(--muted);margin:0 0 14px;font-size:13px">
          生成待签串与 curl 命令，便于对照商户门户文档或抓包调试。RSA 模式请在业务代码中使用商户私钥。
        </p>
        <form id="form-sign" class="form-grid">
          <div class="form-row">
            <label>示例类型</label>
            <div class="btn-row">
              <label><input type="radio" name="action" value="submit" checked> 下单</label>
              <label><input type="radio" name="action" value="query"> 查单</label>
            </div>
          </div>
          <div class="form-row" id="sign-submit-fields">
            <label for="sign-pay-type">pay_type</label>
            <select id="sign-pay-type" name="pay_type">
              <?php foreach ($payTypeMap as $val => $label) : ?>
                <option value="<?= (int) $val ?>"><?= (int) $val ?> · <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-row" id="sign-amount-row">
            <label for="sign-amount">金额（元）</label>
            <input type="number" id="sign-amount" name="amount" value="1" min="0.01" step="0.01">
          </div>
          <div class="form-row">
            <label for="sign-order-id">商户订单号</label>
            <input type="text" id="sign-order-id" name="order_id" placeholder="查单必填；下单留空自动生成">
          </div>
          <div class="form-row">
            <label></label>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary" id="btn-sign">生成 curl 示例</button>
            </div>
          </div>
        </form>
        <div id="sign-result"></div>
      </div>
    </section>

    <!-- 回调日志 -->
    <section id="panel-notify" class="panel">
      <div class="card">
        <h2>异步通知日志</h2>
        <p style="color:var(--muted);margin:0 0 14px;font-size:13px">
          读取 <code>notify_url.php</code> / <code>transfer_notify_url.php</code> 写入的 <code>logs/notify.log</code>、
          <code>logs/transfer_notify.log</code>。须将 notify_url 配置为公网可访问地址（本地可用 ngrok）。
        </p>
        <div class="btn-row" style="margin-bottom:14px">
          <select id="notify-type" style="padding:8px 10px;border:1px solid var(--border);border-radius:6px">
            <option value="">代收回调</option>
            <option value="transfer">代付回调</option>
          </select>
          <input type="text" id="notify-filter" placeholder="按商户订单号筛选" style="max-width:240px;padding:8px 10px;border:1px solid var(--border);border-radius:6px">
          <button type="button" class="btn btn-secondary" id="btn-refresh-notify">刷新</button>
        </div>
        <div id="notify-table-wrap">
          <p class="empty-text">切换到本 Tab 时自动加载</p>
        </div>
      </div>
    </section>

    <!-- 对接文档 -->
    <section id="panel-docs" class="panel">
      <div class="card">
        <h2>对接文档（摘要）</h2>
        <p style="color:var(--muted);margin:0 0 14px">完整说明见商户门户 → <strong>API 对接</strong>。以下为 PHP Demo 常用要点。</p>

        <details class="collapse" open>
          <summary>网关地址</summary>
          <div class="collapse-body">
            <table class="kv-table">
              <tr><th>网关基址</th><td class="mono"><?= htmlspecialchars($gatewayBase ?: '（见 config.php）', ENT_QUOTES, 'UTF-8') ?></td></tr>
              <tr><th>下单</th><td class="mono">POST <?= htmlspecialchars($gatewayBase ? $gatewayBase . '/submitOrder' : '{gateway_base}/submitOrder', ENT_QUOTES, 'UTF-8') ?></td></tr>
              <tr><th>查单</th><td class="mono">POST <?= htmlspecialchars($gatewayBase ? $gatewayBase . '/query' : '{gateway_base}/query', ENT_QUOTES, 'UTF-8') ?></td></tr>
              <tr><th>代付下单</th><td class="mono">POST <?= htmlspecialchars($gatewayBase ? $gatewayBase . '/transfer' : '{gateway_base}/transfer', ENT_QUOTES, 'UTF-8') ?></td></tr>
              <tr><th>代付查单</th><td class="mono">POST <?= htmlspecialchars($gatewayBase ? $gatewayBase . '/transferQuery' : '{gateway_base}/transferQuery', ENT_QUOTES, 'UTF-8') ?></td></tr>
            </table>
          </div>
        </details>

        <details class="collapse">
          <summary>签名规则（MD5 / RSA）</summary>
          <div class="collapse-body">
            <ol class="doc-list">
              <li>剔除 <code>sign</code>、<code>sign_type</code> 后，其余参数按 key 升序拼接 <code>key=value&amp;</code>（空值也参与）。</li>
              <li>末尾追加 <code>key={secret_key}</code> 得待签串。</li>
              <li><code>sign_type=1</code>：<code>sign = strtoupper(md5(待签串))</code>。</li>
              <li><code>sign_type=2</code>：商户私钥 SHA256 签名后 base64；异步通知用平台 RSA 公钥验签。</li>
              <li>请求为 <code>application/x-www-form-urlencoded</code> POST；参数 <code>time</code> 须在服务器时间窗内。</li>
            </ol>
          </div>
        </details>

        <details class="collapse">
          <summary>下单参数 POST /pay/submitOrder</summary>
          <div class="collapse-body">
            <table class="doc-table">
              <thead><tr><th>参数</th><th>必填</th><th>说明</th></tr></thead>
              <tbody>
                <tr><td>mch_id</td><td>是</td><td>商户号</td></tr>
                <tr><td>pay_type</td><td>是</td><td>支付类型 1~7，见代收通道列表</td></tr>
                <tr><td>money</td><td>是</td><td>金额，单位<strong>分</strong>（字符串）</td></tr>
                <tr><td>order_id</td><td>是</td><td>商户订单号，唯一</td></tr>
                <tr><td>time</td><td>是</td><td>Unix 时间戳（秒）</td></tr>
                <tr><td>notify_url</td><td>是</td><td>异步通知地址</td></tr>
                <tr><td>return_url</td><td>否</td><td>支付完成跳转</td></tr>
                <tr><td>commodity_name</td><td>否</td><td>商品名称</td></tr>
                <tr><td>extra</td><td>否</td><td>透传字段，回调原样带回</td></tr>
                <tr><td>client_ip</td><td>否</td><td>用户 IP</td></tr>
                <tr><td>sign / sign_type</td><td>是</td><td>签名</td></tr>
              </tbody>
            </table>
            <p>成功返回 <code>code=200</code>，<code>data.pay_url</code> 为支付链接。</p>
          </div>
        </details>

        <details class="collapse">
          <summary>查单参数 POST /pay/query</summary>
          <div class="collapse-body">
            <table class="doc-table">
              <thead><tr><th>参数</th><th>必填</th><th>说明</th></tr></thead>
              <tbody>
                <tr><td>mch_id</td><td>是</td><td>商户号</td></tr>
                <tr><td>order_id</td><td>是</td><td>商户订单号</td></tr>
                <tr><td>time</td><td>是</td><td>Unix 时间戳</td></tr>
                <tr><td>client_ip</td><td>否</td><td>客户端 IP</td></tr>
                <tr><td>sign / sign_type</td><td>是</td><td>签名</td></tr>
              </tbody>
            </table>
            <p>返回 <code>trade_status</code>：NOTPAY / SUCCESS / FAILED / CLOSED。</p>
          </div>
        </details>

        <details class="collapse">
          <summary>异步通知（平台 → notify_url）</summary>
          <div class="collapse-body">
            <table class="doc-table">
              <thead><tr><th>参数</th><th>说明</th></tr></thead>
              <tbody>
                <tr><td>order_id</td><td>商户订单号</td></tr>
                <tr><td>order_no</td><td>平台订单号</td></tr>
                <tr><td>money</td><td>金额（分）</td></tr>
                <tr><td>mch_id</td><td>商户号</td></tr>
                <tr><td>status</td><td>订单状态</td></tr>
                <tr><td>extra</td><td>透传</td></tr>
                <tr><td>time / sign / sign_type</td><td>时间与签名</td></tr>
              </tbody>
            </table>
            <p>验签通过后须响应纯文本 <code>SUCCESS</code>（大小写敏感），否则平台重试。处理须<strong>幂等</strong>。</p>
          </div>
        </details>

        <details class="collapse">
          <summary>代付（提现）下单 POST /pay/transfer</summary>
          <div class="collapse-body">
            <p style="margin:0 0 10px;color:var(--muted);font-size:13px">
              下游商户服务器发起出款（提现）。鉴权与代收一致（<code>mch_id</code> + <code>time</code> + <code>sign</code>）。
              <code>out_biz_no</code> 为同商户幂等键，重复提交相同单号不会重复出款。
            </p>
            <table class="doc-table">
              <thead><tr><th>参数</th><th>必填</th><th>说明</th></tr></thead>
              <tbody>
                <tr><td>mch_id</td><td>是</td><td>商户号</td></tr>
                <tr><td>out_biz_no</td><td>是</td><td>商户代付单号，同商户唯一（幂等键）</td></tr>
                <tr><td>money</td><td>是</td><td>出款金额，单位<strong>分</strong>（字符串）</td></tr>
                <tr><td>account_name</td><td>二选一</td><td>收款人姓名（下游用户提现，随单直传）</td></tr>
                <tr><td>account_no</td><td>二选一</td><td>收款账号/银行卡号（与 account_name 同时必填）</td></tr>
                <tr><td>bank_name</td><td>否</td><td>开户银行名称</td></tr>
                <tr><td>bank_code</td><td>否</td><td>银行编码（部分通道必填）</td></tr>
                <tr><td>branch_name</td><td>否</td><td>开户支行</td></tr>
                <tr><td>account_phone</td><td>否</td><td>收款人手机号（落库存档）</td></tr>
                <tr><td>bank_card_id</td><td>二选一</td><td>预绑卡 ID（商户自有收款卡；与直传字段二选一）</td></tr>
                <tr><td>notify_url</td><td>否</td><td>代付结果异步回调地址（留空用商户默认）</td></tr>
                <tr><td>time</td><td>是</td><td>Unix 时间戳（秒）</td></tr>
                <tr><td>client_ip</td><td>否</td><td>用户 IP</td></tr>
                <tr><td>sign / sign_type</td><td>是</td><td>签名（规则同代收）</td></tr>
              </tbody>
            </table>
            <p>成功返回 <code>code=200</code>，<code>data</code> 含
              <code>withdraw_no</code>（平台代付单号）、<code>amount</code>/<code>fee</code>/<code>real_amount</code>、
              <code>status</code> 与 <code>status_text</code>。手续费由平台按通道计算，<code>real_amount</code> 为实际到账。
            </p>
          </div>
        </details>

        <details class="collapse">
          <summary>代付查单 POST /pay/transferQuery</summary>
          <div class="collapse-body">
            <table class="doc-table">
              <thead><tr><th>参数</th><th>必填</th><th>说明</th></tr></thead>
              <tbody>
                <tr><td>mch_id</td><td>是</td><td>商户号</td></tr>
                <tr><td>out_biz_no</td><td>是</td><td>商户代付单号</td></tr>
                <tr><td>time</td><td>是</td><td>Unix 时间戳</td></tr>
                <tr><td>client_ip</td><td>否</td><td>客户端 IP</td></tr>
                <tr><td>sign / sign_type</td><td>是</td><td>签名</td></tr>
              </tbody>
            </table>
            <p>返回 <code>data.status_text</code>，取值见下「代付状态码」。仅能查本商户代付单。</p>
          </div>
        </details>

        <details class="collapse">
          <summary>代付异步通知（平台 → transfer_notify_url）</summary>
          <div class="collapse-body">
            <table class="doc-table">
              <thead><tr><th>参数</th><th>说明</th></tr></thead>
              <tbody>
                <tr><td>out_biz_no</td><td>商户代付单号</td></tr>
                <tr><td>transfer_no</td><td>平台代付单号</td></tr>
                <tr><td>money</td><td>金额（分）</td></tr>
                <tr><td>mch_id</td><td>商户号</td></tr>
                <tr><td>status</td><td><code>success</code>=出款成功 / <code>fail</code>=出款失败</td></tr>
                <tr><td>reason</td><td>失败原因（status=fail 时可能附带）</td></tr>
                <tr><td>time / sign / sign_type</td><td>时间与签名</td></tr>
              </tbody>
            </table>
            <p>验签通过后须响应纯文本 <code>SUCCESS</code>，否则平台重试。处理须<strong>幂等</strong>；
              <code>status=fail</code> 时应把出款金额退回给提现用户。</p>
          </div>
        </details>

        <details class="collapse">
          <summary>代付状态码 status_text</summary>
          <div class="collapse-body">
            <table class="doc-table">
              <thead><tr><th>值</th><th>含义</th><th>终态</th></tr></thead>
              <tbody>
                <tr><td>pending</td><td>待审核（金额超阈值转人工）</td><td>否</td></tr>
                <tr><td>approved</td><td>审核通过待下发</td><td>否</td></tr>
                <tr><td>paying</td><td>代付中（已提交上游，等回调）</td><td>否</td></tr>
                <tr><td>success</td><td>出款成功</td><td>是</td></tr>
                <tr><td>fail</td><td>出款失败（已退款解冻）</td><td>是</td></tr>
                <tr><td>rejected</td><td>审核拒绝（已退款解冻）</td><td>是</td></tr>
              </tbody>
            </table>
            <p style="color:var(--muted);font-size:13px">下单后多为 <code>pending</code> 或 <code>paying</code>，最终结果以异步通知/查单的终态为准。</p>
          </div>
        </details>

        <details class="collapse">
          <summary>pay_type 对照表</summary>
          <div class="collapse-body">
            <table class="doc-table">
              <thead><tr><th>值</th><th>含义</th></tr></thead>
              <tbody>
                <?php foreach ($payTypeMap as $val => $label) : ?>
                  <tr><td><?= (int) $val ?></td><td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>

        <details class="collapse">
          <summary>本地启动</summary>
          <div class="collapse-body">
            <pre class="json-view" style="background:#1e293b;max-height:120px">cd demo/merchant-php
cp config.example.php config.php
# 编辑 config.php 后：
php -S 0.0.0.0:8090</pre>
            <p style="margin-top:10px;color:var(--muted);font-size:13px">
              浏览器打开 <code>http://127.0.0.1:8090/</code>。网关请求仍须从已加白名单的服务器 IP 发出；notify 须公网可达。
            </p>
          </div>
        </details>
      </div>
    </section>
  </main>

  <script>
    window.DEMO_CONFIG = { configOk: <?= $configOk ? 'true' : 'false' ?> };
  </script>
  <script src="assets/console.js"></script>
</body>
</html>
