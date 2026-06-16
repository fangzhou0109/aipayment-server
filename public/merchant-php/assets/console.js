/**
 * SaiPayment PHP Demo 控制台交互
 */
(function () {
  'use strict';

  var LS_KEY_ORDER = 'saipayment_demo_last_order_id';
  var LS_KEY_TRANSFER = 'saipayment_demo_last_transfer_no';
  var configOk = window.DEMO_CONFIG && window.DEMO_CONFIG.configOk;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatJson(obj) {
    try {
      return JSON.stringify(obj, null, 2);
    } catch (e) {
      return String(obj);
    }
  }

  function genOrderId() {
    var d = new Date();
    var pad = function (n, w) {
      var s = String(n);
      while (s.length < w) s = '0' + s;
      return s;
    };
    return (
      'DEMO' +
      d.getFullYear() +
      pad(d.getMonth() + 1, 2) +
      pad(d.getDate(), 2) +
      pad(d.getHours(), 2) +
      pad(d.getMinutes(), 2) +
      pad(d.getSeconds(), 2) +
      String(Math.floor(1000 + Math.random() * 9000))
    );
  }

  function getLastOrderId() {
    try {
      return localStorage.getItem(LS_KEY_ORDER) || '';
    } catch (e) {
      return '';
    }
  }

  function saveLastOrderId(id) {
    if (!id) return;
    try {
      localStorage.setItem(LS_KEY_ORDER, id);
    } catch (e) { /* ignore */ }
  }

  function genTransferNo() {
    return 'DEMOT' + genOrderId().replace(/^DEMO/, '');
  }

  function getLastTransferNo() {
    try {
      return localStorage.getItem(LS_KEY_TRANSFER) || '';
    } catch (e) {
      return '';
    }
  }

  function saveLastTransferNo(id) {
    if (!id) return;
    try {
      localStorage.setItem(LS_KEY_TRANSFER, id);
    } catch (e) { /* ignore */ }
  }

  function copyText(text, btn) {
    if (!text) return;
    var done = function () {
      if (btn) {
        var orig = btn.textContent;
        btn.textContent = '已复制';
        setTimeout(function () {
          btn.textContent = orig;
        }, 1500);
      }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {
        window.prompt('复制以下内容：', text);
      });
    } else {
      window.prompt('复制以下内容：', text);
      done();
    }
  }

  function renderResult(container, data, ok) {
    if (!container) return;
    var payUrl =
      data && data.pay_url
        ? data.pay_url
        : data && data.response && data.response.data && data.response.data.pay_url
          ? data.response.data.pay_url
          : null;

    var html =
      '<div class="result-block">' +
      '<div class="result-head">' +
      '<span>响应结果</span>' +
      '<span class="badge ' +
      (ok ? 'badge-ok' : 'badge-fail') +
      '">' +
      (ok ? '成功' : '失败') +
      '</span></div>' +
      '<pre class="json-view">' +
      escHtml(formatJson(data)) +
      '</pre>';

    if (payUrl) {
      html +=
        '<div class="pay-url-box">' +
        '<strong>支付链接：</strong> ' +
        '<a href="' +
        escHtml(payUrl) +
        '" target="_blank" rel="noopener">' +
        escHtml(payUrl) +
        '</a></div>';
    }

    html += '</div>';
    container.innerHTML = html;
  }

  function fetchJson(url, options) {
    return fetch(url, options || {})
      .then(function (res) {
        return res.json().then(function (body) {
          return { httpOk: res.ok, status: res.status, body: body };
        });
      })
      .catch(function (err) {
        return { httpOk: false, status: 0, body: { ok: false, message: err.message || '网络错误' } };
      });
  }

  function requireConfigAlert() {
    alert('请先复制 config.example.php 为 config.php 并填写商户配置。');
  }

  function setupTabs() {
    var buttons = $$('.tab-btn');
    var panels = $$('.panel');

    function activate(name) {
      buttons.forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.tab === name);
      });
      panels.forEach(function (p) {
        p.classList.toggle('active', p.id === 'panel-' + name);
      });
      if (name === 'overview') loadOverview();
      if (name === 'notify') loadNotifyLogs();
      try {
        history.replaceState(null, '', '#' + name);
      } catch (e) { /* ignore */ }
    }

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activate(btn.dataset.tab);
      });
    });

    var hash = (location.hash || '#overview').replace('#', '');
    if (!$('#panel-' + hash)) hash = 'overview';
    activate(hash);
  }

  function loadOverview() {
    var el = $('#overview-content');
    if (!el) return;

    if (!configOk) {
      el.innerHTML =
        '<div class="alert alert-warn">尚未配置 <code>config.php</code>，请先复制 <code>config.example.php</code> 并填写网关与密钥。</div>';
      return;
    }

    el.innerHTML = '<p class="loading-inline">正在探测网关连通性…</p>';

    fetchJson('health.php').then(function (res) {
      var d = res.body;
      var health = d.health || {};
      var reachable = health.reachable === true;
      var cfg = d.config || {};

      var html =
        '<table class="kv-table">' +
        '<tr><th>网关基址</th><td class="mono">' +
        escHtml(d.gateway_base || '') +
        '</td></tr>' +
        '<tr><th>商户号</th><td class="mono">' +
        escHtml(cfg.mch_id || '') +
        '</td></tr>' +
        '<tr><th>密钥</th><td class="mono">' +
        escHtml(cfg.secret_key_masked || '') +
        '</td></tr>' +
        '<tr><th>签名类型</th><td>' +
        (cfg.sign_type === 2 ? 'RSA (2)' : 'MD5 (1)') +
        '</td></tr>' +
        '<tr><th>默认 pay_type</th><td>' +
        escHtml(String(cfg.default_pay_type || '')) +
        '</td></tr>' +
        '<tr><th>notify_url</th><td class="mono">' +
        escHtml(cfg.notify_url || '') +
        '</td></tr>' +
        '<tr><th>return_url</th><td class="mono">' +
        escHtml(cfg.return_url || '') +
        '</td></tr>' +
        '<tr><th>网关健康</th><td>' +
        '<span class="status-dot ' +
        (reachable ? 'ok' : 'fail') +
        '"></span>' +
        (reachable ? '可达' : '不可达') +
        (health.message ? ' — ' + escHtml(health.message) : '') +
        '</td></tr>' +
        '<tr><th>PHP 版本</th><td>' +
        escHtml(d.php_version || '') +
        '</td></tr>' +
        '<tr><th>本机 IP</th><td class="mono">' +
        escHtml(d.client_ip || '') +
        '（请求出口，白名单须包含实际出站 IP）</td></tr>' +
        '</table>';

      if (health.response) {
        html +=
          '<h3>健康检查响应</h3><pre class="json-view">' +
          escHtml(formatJson(health.response)) +
          '</pre>';
      } else if (health.raw) {
        html +=
          '<h3>原始响应（截断）</h3><pre class="json-view">' +
          escHtml(String(health.raw)) +
          '</pre>';
      }

      el.innerHTML = html;
    });
  }

  function setupSubmitForm() {
    var form = $('#form-submit');
    var result = $('#submit-result');
    if (!form) return;

    var orderInput = $('#submit-order-id');
    var genBtn = $('#btn-gen-order-id');
    var lastBtn = $('#btn-use-last-order');

    if (orderInput && !orderInput.value) {
      orderInput.placeholder = '留空自动生成';
    }

    if (genBtn) {
      genBtn.addEventListener('click', function () {
        if (orderInput) orderInput.value = genOrderId();
      });
    }

    if (lastBtn) {
      var last = getLastOrderId();
      if (!last) lastBtn.disabled = true;
      lastBtn.addEventListener('click', function () {
        if (orderInput) orderInput.value = getLastOrderId();
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!configOk) return requireConfigAlert();

      var fd = new FormData(form);
      var btn = $('#btn-submit');
      if (btn) btn.disabled = true;
      if (result) result.innerHTML = '<p class="loading-inline">请求中…</p>';

      fetchJson('submit_order.php', { method: 'POST', body: fd }).then(function (res) {
        if (btn) btn.disabled = false;
        var body = res.body;
        var ok = body.ok === true;
        if (ok && body.request && body.request.order_id) {
          saveLastOrderId(body.request.order_id);
          if (lastBtn) lastBtn.disabled = false;
          var q = $('#query-order-id');
          if (q && !q.value) q.value = body.request.order_id;
        }
        renderResult(result, body, ok);
      });
    });
  }

  function setupQueryForm() {
    var form = $('#form-query');
    var result = $('#query-result');
    if (!form) return;

    var orderInput = $('#query-order-id');
    var fillBtn = $('#btn-fill-last-order');
    if (fillBtn) {
      var last = getLastOrderId();
      if (!last) fillBtn.disabled = true;
      fillBtn.addEventListener('click', function () {
        if (orderInput) orderInput.value = getLastOrderId();
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!configOk) return requireConfigAlert();

      var fd = new FormData(form);
      var btn = $('#btn-query');
      if (btn) btn.disabled = true;
      if (result) result.innerHTML = '<p class="loading-inline">查询中…</p>';

      fetchJson('query_order.php', { method: 'POST', body: fd }).then(function (res) {
        if (btn) btn.disabled = false;
        renderResult(result, res.body, res.body.ok === true);
      });
    });
  }

  function setupSignForm() {
    var form = $('#form-sign');
    var result = $('#sign-result');
    if (!form) return;

    var actionRadios = $$('input[name="action"]', form);
    var submitFields = $('#sign-submit-fields');
    var amountRow = $('#sign-amount-row');
    var orderInput = $('#sign-order-id');

    function syncAction() {
      var action = 'submit';
      actionRadios.forEach(function (r) {
        if (r.checked) action = r.value;
      });
      var isQuery = action === 'query';
      if (submitFields) submitFields.style.display = isQuery ? 'none' : '';
      if (amountRow) amountRow.style.display = isQuery ? 'none' : '';
      if (orderInput) {
        orderInput.placeholder = isQuery ? '查单必填' : '留空自动生成';
      }
    }

    actionRadios.forEach(function (r) {
      r.addEventListener('change', syncAction);
    });
    syncAction();

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!configOk) return requireConfigAlert();

      var fd = new FormData(form);
      var btn = $('#btn-sign');
      if (btn) btn.disabled = true;
      if (result) result.innerHTML = '<p class="loading-inline">生成中…</p>';

      fetchJson('build_sign.php', { method: 'POST', body: fd }).then(function (res) {
        if (btn) btn.disabled = false;
        var body = res.body;
        if (!body.ok) {
          renderResult(result, body, false);
          return;
        }

        var html =
          '<div class="result-block">' +
          '<div class="result-head"><span>签名示例</span>' +
          '<button type="button" class="btn btn-link" id="copy-curl">复制 curl</button></div>' +
          '<h3 style="margin:12px 14px 6px;font-size:13px">请求地址</h3>' +
          '<pre class="json-view" style="max-height:60px;background:#1e293b">' +
          escHtml(body.url || '') +
          '</pre>' +
          '<h3 style="margin:12px 14px 6px;font-size:13px">待签串</h3>' +
          '<pre class="json-view" style="max-height:120px;background:#1e293b">' +
          escHtml(body.sign_string || '') +
          '</pre>' +
          '<h3 style="margin:12px 14px 6px;font-size:13px">curl 命令</h3>' +
          '<pre class="json-view" id="curl-pre">' +
          escHtml(body.curl_example || '') +
          '</pre>' +
          '<h3 style="margin:12px 14px 6px;font-size:13px">完整参数</h3>' +
          '<pre class="json-view">' +
          escHtml(formatJson(body.params)) +
          '</pre></div>';

        result.innerHTML = html;
        var copyBtn = $('#copy-curl', result);
        if (copyBtn) {
          copyBtn.addEventListener('click', function () {
            copyText(body.curl_example, copyBtn);
          });
        }
      });
    });
  }

  function setupTransferForm() {
    var form = $('#form-transfer');
    var result = $('#transfer-result');
    if (!form) return;

    var bizInput = $('#transfer-out-biz-no');
    var genBtn = $('#btn-gen-out-biz-no');

    if (genBtn) {
      genBtn.addEventListener('click', function () {
        if (bizInput) bizInput.value = genTransferNo();
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!configOk) return requireConfigAlert();

      var fd = new FormData(form);
      var btn = $('#btn-transfer');
      if (btn) btn.disabled = true;
      if (result) result.innerHTML = '<p class="loading-inline">代付提交中…</p>';

      fetchJson('submit_transfer.php', { method: 'POST', body: fd }).then(function (res) {
        if (btn) btn.disabled = false;
        var body = res.body;
        var ok = body.ok === true;
        if (ok && body.request && body.request.out_biz_no) {
          saveLastTransferNo(body.request.out_biz_no);
          var q = $('#transfer-query-out-biz-no');
          if (q && !q.value) q.value = body.request.out_biz_no;
        }
        renderResult(result, body, ok);
      });
    });
  }

  function setupTransferQueryForm() {
    var form = $('#form-transfer-query');
    var result = $('#transfer-query-result');
    if (!form) return;

    var bizInput = $('#transfer-query-out-biz-no');
    var fillBtn = $('#btn-fill-last-transfer');
    if (fillBtn) {
      var last = getLastTransferNo();
      if (!last) fillBtn.disabled = true;
      fillBtn.addEventListener('click', function () {
        if (bizInput) bizInput.value = getLastTransferNo();
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!configOk) return requireConfigAlert();

      var fd = new FormData(form);
      var btn = $('#btn-transfer-query');
      if (btn) btn.disabled = true;
      if (result) result.innerHTML = '<p class="loading-inline">查询中…</p>';

      fetchJson('query_transfer.php', { method: 'POST', body: fd }).then(function (res) {
        if (btn) btn.disabled = false;
        renderResult(result, res.body, res.body.ok === true);
      });
    });
  }

  function loadNotifyLogs() {
    var wrap = $('#notify-table-wrap');
    if (!wrap) return;

    wrap.innerHTML = '<p class="loading-inline">加载中…</p>';

    var filter = ($('#notify-filter') && $('#notify-filter').value) || '';
    var type = ($('#notify-type') && $('#notify-type').value) || '';
    var url = 'notify_logs.php?limit=50' + (type ? '&type=' + encodeURIComponent(type) : '');

    fetchJson(url).then(function (res) {
      var items = (res.body.items || []).filter(function (row) {
        if (!filter) return true;
        return String(row.order_id || '').indexOf(filter) !== -1;
      });

      if (!items.length) {
        wrap.innerHTML =
          '<p class="empty-text">暂无回调记录。请确保 notify_url 指向本目录的 notify_url.php，并完成一笔支付。</p>';
        return;
      }

      var rows = items
        .map(function (row) {
          var timeMatch = (row.header || '').match(/^\[([^\]]+)\]/);
          var receivedAt = timeMatch ? timeMatch[1] : row.header || '—';
          return (
            '<tr>' +
            '<td>' +
            escHtml(receivedAt) +
            '</td>' +
            '<td class="mono">' +
            escHtml(row.order_id) +
            '</td>' +
            '<td class="mono">' +
            escHtml(row.order_no) +
            '</td>' +
            '<td>' +
            escHtml(row.money) +
            '</td>' +
            '<td>' +
            escHtml(row.status) +
            '</td>' +
            '<td>' +
            (row.sign_ok === true
              ? '<span class="tag tag-ok">通过</span>'
              : row.sign_ok === false
                ? '<span class="tag tag-fail">失败</span>'
                : '—') +
            '</td>' +
            '<td><button type="button" class="btn btn-link btn-notify-detail" data-idx="' +
            items.indexOf(row) +
            '">详情</button></td>' +
            '</tr>'
          );
        })
        .join('');

      wrap.innerHTML =
        '<table class="notify-table"><thead><tr>' +
        '<th>时间</th><th>商户订单号</th><th>平台订单号</th><th>金额(分)</th><th>status</th><th>验签</th><th></th>' +
        '</tr></thead><tbody>' +
        rows +
        '</tbody></table>' +
        '<div id="notify-detail"></div>';

      // 缓存供详情展示
      wrap._notifyItems = items;

      $$('.btn-notify-detail', wrap).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var idx = parseInt(btn.dataset.idx, 10);
          var item = wrap._notifyItems[idx];
          var detail = $('#notify-detail');
          if (detail && item) {
            renderResult(detail, item.payload || item, item.sign_ok !== false);
          }
        });
      });
    });
  }

  function setupNotifyPanel() {
    var refresh = $('#btn-refresh-notify');
    var filter = $('#notify-filter');
    var typeSel = $('#notify-type');
    if (refresh) refresh.addEventListener('click', loadNotifyLogs);
    if (typeSel) typeSel.addEventListener('change', loadNotifyLogs);
    if (filter) {
      filter.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          loadNotifyLogs();
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    setupTabs();
    setupSubmitForm();
    setupQueryForm();
    setupTransferForm();
    setupTransferQueryForm();
    setupSignForm();
    setupNotifyPanel();

    // 预填查单订单号
    var last = getLastOrderId();
    var q = $('#query-order-id');
    if (q && last && !q.value) q.value = last;
  });
})();
