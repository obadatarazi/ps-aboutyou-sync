(function () {
  'use strict';

  var API = 'api.php';
  var LS = {
    settings: 'prestasync_ui_settings',
    activity: 'prestasync_activity',
    audit: 'prestasync_audit_log',
    lastSync: 'prestasync_last_sync_at',
    webhook: 'prestasync_webhook_enabled',
    orderAuto: 'prestasync_order_auto',
  };
  var THEME_KEY = 'prestasync_theme';
  var productsSource = 'mapping';
  var ordersSource = 'mapping';
  var productsHasMore = false;
  var ordersHasMore = false;

  var boot = window.__BOOTSTRAP__ || {};
  var csrf = boot.csrf || '';
  var productsPage = 1;
  var productsPerPage = 20;
  var productsRows = [];
  var selectedProductId = 0;
  var ordersPage = 1;
  var ordersPerPage = 20;
  var ordersRows = [];
  var webhookTimer = null;
  var webhookLogsTimer = null;
  var autoSyncTimer = null;
  var statsTimer = null;
  var liveTraceTimer = null;
  var logsLines = [];
  var uiBound = false;

  function $(id) {
    return document.getElementById(id);
  }

  function showScreen(login) {
    var loginEl = $('screen-login');
    var appEl = $('screen-app');
    if (!loginEl || !appEl) return;
    loginEl.hidden = !login;
    appEl.hidden = login;
  }

  function showToast(message) {
    var root = $('toast-root');
    if (!root) return;
    var t = document.createElement('div');
    t.className = 'toast';
    t.textContent = message;
    root.appendChild(t);
    setTimeout(function () {
      t.remove();
    }, 4000);
  }

  function loadJson(key, fallback) {
    try {
      var raw = localStorage.getItem(key);
      if (!raw) return fallback;
      return JSON.parse(raw);
    } catch (e) {
      return fallback;
    }
  }

  function saveJson(key, val) {
    localStorage.setItem(key, JSON.stringify(val));
  }

  function loadSettings() {
    return loadJson(LS.settings, {});
  }

  function saveSettings() {
    var s = {
      storeUrl: $('set-store-url') ? $('set-store-url').value : '',
      apiKey: $('set-api-key') ? $('set-api-key').value : '',
      apiSecret: $('set-api-secret') ? $('set-api-secret').value : '',
      interval: $('set-interval') ? $('set-interval').value : '15',
      autoSync: $('set-auto-sync') ? $('set-auto-sync').checked : false,
      webhookSecret: $('set-webhook-secret') ? $('set-webhook-secret').value : '',
      autoCommand: $('set-auto-command') ? $('set-auto-command').value : 'stock',
    };
    saveJson(LS.settings, s);
    showToast('Settings saved locally');
    appendAudit('settings', 'ui', 'success', 'Settings saved to localStorage');
    restartAutoSync();
  }

  function applySettingsToForm() {
    var s = loadSettings();
    if ($('set-store-url')) $('set-store-url').value = s.storeUrl || '';
    if ($('set-api-key')) $('set-api-key').value = s.apiKey || '';
    if ($('set-api-secret')) $('set-api-secret').value = s.apiSecret || '';
    if ($('set-interval')) $('set-interval').value = s.interval || '15';
    if ($('set-auto-sync')) $('set-auto-sync').checked = !!s.autoSync;
    if ($('set-webhook-secret')) $('set-webhook-secret').value = s.webhookSecret || '';
    if ($('set-auto-command')) $('set-auto-command').value = s.autoCommand || 'stock';
  }

  function appendActivity(message) {
    var list = loadJson(LS.activity, []);
    list.unshift({ t: new Date().toISOString(), msg: message });
    if (list.length > 40) list.length = 40;
    saveJson(LS.activity, list);
    renderActivity();
  }

  function appendAudit(action, entity, status, message) {
    var list = loadJson(LS.audit, []);
    list.unshift({
      timestamp: new Date().toISOString(),
      action: action,
      entity: entity,
      status: status,
      message: message,
    });
    if (list.length > 200) list.length = 200;
    saveJson(LS.audit, list);
  }

  function renderActivity() {
    var el = $('activity-feed');
    if (!el) return;
    var list = loadJson(LS.activity, []);
    el.innerHTML = '';
    list.forEach(function (row) {
      var div = document.createElement('div');
      div.className = 'feed-item';
      var time = document.createElement('time');
      time.dateTime = row.t;
      time.textContent = row.t.replace('T', ' ').replace(/\.\d{3}Z$/, ' UTC');
      var p = document.createElement('div');
      p.textContent = row.msg;
      div.appendChild(time);
      div.appendChild(p);
      el.appendChild(div);
    });
    if (!list.length) {
      el.innerHTML = '<p class="muted">No recent activity yet.</p>';
    }
  }

  function applyTheme() {
    var dark = localStorage.getItem(THEME_KEY) === 'dark';
    if (!dark) {
      document.documentElement.setAttribute('data-theme', 'light');
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    document.querySelectorAll('.btn-theme-toggle').forEach(function (btn) {
      btn.textContent = dark ? 'Light mode' : 'Dark mode';
    });
  }

  function setSidebarOpen(open) {
    document.body.classList.toggle('sidebar-open', !!open);
    var bd = $('sidebar-backdrop');
    if (bd) {
      bd.hidden = !open;
      bd.setAttribute('aria-hidden', open ? 'false' : 'true');
    }
  }

  function api(action, body) {
    body = body || {};
    var payload = Object.assign({ action: action }, body);
    if (csrf && action !== 'login') payload.csrf = csrf;
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json().then(function (data) {
        return { ok: r.ok, status: r.status, data: data };
      }).catch(function () {
        return { ok: false, status: r.status, data: {} };
      });
    });
  }

  function setProgress(mode, pct, label) {
    var wrap = $('sync-progress-wrap');
    var fill = $('sync-progress-fill');
    var lbl = $('sync-progress-label');
    var p = $('sync-progress-pct');
    if (!wrap || !fill) return;
    wrap.classList.toggle('indeterminate', mode === 'indeterminate');
    fill.style.width = mode === 'indeterminate' ? '40%' : Math.min(100, pct) + '%';
    if (lbl) lbl.textContent = label || '';
    if (p) p.textContent = mode === 'indeterminate' ? '…' : Math.round(pct) + '%';
  }

  function refreshSafetyPills(tm, dr) {
    var pt = $('pill-test-val');
    var pd = $('pill-dry-val');
    var pillTest = $('pill-test');
    var pillDry = $('pill-dry');
    if (pt) pt.textContent = tm ? 'ON' : 'OFF';
    if (pd) pd.textContent = dr ? 'ON' : 'OFF';
    if (pillTest) pillTest.className = 'pill' + (tm ? ' warn' : ' ok');
    if (pillDry) pillDry.className = 'pill' + (dr ? ' warn' : ' ok');
  }

  function refreshStats() {
    return api('status', {}).then(function (res) {
      if (!res.ok || !res.data.ok) return;
      var d = res.data.data || res.data;
      var stats = (d && d.stats) ? d.stats : {};
      var pm = $('stat-products');
      var om = $('stat-orders');
      if (pm) pm.textContent = String(stats.products_mapped != null ? stats.products_mapped : '—');
      if (om) om.textContent = String(stats.orders_mapped != null ? stats.orders_mapped : '—');
      var last = stats.last_sync || {};
      var parts = [];
      ['products', 'stock', 'orders'].forEach(function (k) {
        if (last[k]) parts.push(k + ': ' + last[k]);
      });
      var elLast = $('stat-last');
      var ls = localStorage.getItem(LS.lastSync);
      if (elLast) {
        elLast.textContent = (parts.join(' · ') || '—') + (ls ? '\nBrowser: ' + ls : '');
      }
      var wh = $('stat-webhook');
      if (wh) wh.textContent = localStorage.getItem(LS.webhook) === '1' ? 'Listener on' : 'Off';
      refreshSafetyPills(!!boot.test_mode, !!boot.dry_run);
      renderCurrentJob(stats.runtime || {});
      return stats;
    });
  }

  function renderCurrentJob(runtime) {
    runtime = runtime || {};
    var cmd = $('job-command');
    var phase = $('job-phase');
    var product = $('job-product');
    var progress = $('job-progress');
    var counters = $('job-counters');
    var time = $('job-time');
    var msg = $('job-message');
    if (cmd) cmd.textContent = runtime.command || '—';
    if (phase) phase.textContent = runtime.phase || '—';
    if (product) product.textContent = runtime.current_product_id ? ('#' + runtime.current_product_id) : '—';
    var done = Number(runtime.done_items || 0);
    var total = Number(runtime.total_items || 0);
    if (progress) progress.textContent = total > 0 ? (done + ' / ' + total) : '—';
    if (counters) counters.textContent = 'pushed ' + Number(runtime.pushed_items || 0) + ' · failed ' + Number(runtime.failed_items || 0);
    var startedAt = runtime.started_at ? new Date(runtime.started_at) : null;
    var elapsedSec = 0;
    if (startedAt && !isNaN(startedAt.getTime())) {
      elapsedSec = Math.max(0, Math.round((Date.now() - startedAt.getTime()) / 1000));
    } else if (runtime.elapsed) {
      elapsedSec = Math.round(Number(runtime.elapsed) || 0);
    }
    var etaText = '—';
    if (total > 0 && done > 0 && done < total && elapsedSec > 0) {
      var avg = elapsedSec / done;
      etaText = Math.round((total - done) * avg) + 's';
    }
    if (time) time.textContent = (elapsedSec ? (elapsedSec + 's') : '—') + ' / ' + etaText;
    if (msg) {
      if (runtime.active) {
        msg.textContent = runtime.last_message || 'Sync is running.';
      } else {
        msg.textContent = runtime.last_message || 'No active sync job.';
      }
    }
  }

  function runSync(command, label, extra) {
    extra = extra || {};
    setProgress('indeterminate', 0, label || ('Running ' + command + '…'));
    startLiveTracePolling();
    return api('sync', Object.assign({ command: command }, extra)).then(function (res) {
      var payload = res.data && res.data.data !== undefined ? res.data.data : res.data;
      var ok = res.status !== 409 && res.ok && payload && payload.ok !== false;
      if (res.status === 409) {
        setProgress('done', 0, 'Locked');
        showToast(payload && payload.error ? payload.error : 'Another sync is running');
        appendAudit('sync', command, 'warning', 'Lock / concurrent run');
        appendActivity('Sync skipped: ' + (payload && payload.error ? payload.error : 'busy'));
        stopLiveTracePolling();
        return payload;
      }
      setProgress('done', 100, ok ? 'Done' : 'Finished with errors');
      if (ok) {
        localStorage.setItem(LS.lastSync, new Date().toISOString());
        showToast('Sync completed: ' + command);
        appendAudit('sync', command, 'success', (payload && payload.message) || 'OK');
        appendActivity('Sync finished: ' + command + ' (' + ((payload && payload.elapsed) || '?') + 's)');
      } else {
        showToast('Sync failed or reported errors');
        appendAudit('sync', command, 'error', (payload && payload.message) || 'Error');
        appendActivity('Sync failed: ' + command);
      }
      refreshStats();
      loadLogs();
      stopLiveTracePolling();
      return payload;
    }).catch(function () {
      setProgress('done', 0, 'Error');
      showToast('Network error');
      appendAudit('sync', command, 'error', 'Network');
      stopLiveTracePolling();
    });
  }

  function activateTab(id) {
    if (!id) return;
    var tabs = document.querySelectorAll('.sidebar-link[data-tab]');
    var target = null;
    tabs.forEach(function (b) {
      if (b.getAttribute('data-tab') === id) target = b;
    });
    if (!target) return;
    setSidebarOpen(false);
    tabs.forEach(function (b) {
      b.setAttribute('aria-selected', b === target ? 'true' : 'false');
    });
    document.querySelectorAll('.tab-panel').forEach(function (p) {
      p.classList.remove('active');
      p.hidden = true;
    });
    var panel = $('tab-' + id);
    if (panel) {
      panel.classList.add('active');
      panel.hidden = false;
    }
    if (id === 'products') loadProducts();
    if (id === 'orders') loadOrders();
    if (id === 'logs') loadLogs();
  }

  function initTabs() {
    var tabs = document.querySelectorAll('.sidebar-link[data-tab]');
    tabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activateTab(btn.getAttribute('data-tab'));
      });
    });
  }

  function updateProductSourceButtons() {
    var m = $('products-src-mapping');
    var p = $('products-src-ps');
    var f = $('products-src-failed');
    if (m) m.classList.toggle('active', productsSource === 'mapping');
    if (p) p.classList.toggle('active', productsSource === 'prestashop');
    if (f) f.classList.toggle('active', productsSource === 'failed');
  }

  function updateOrdersSourceButtons() {
    var m = $('orders-src-mapping');
    var p = $('orders-src-ps');
    if (m) m.classList.toggle('active', ordersSource === 'mapping');
    if (p) p.classList.toggle('active', ordersSource === 'prestashop');
  }

  function parseLogLine(line) {
    var m = line.match(/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*[^.]+\.(\w+):\s*(.*)$/);
    if (!m) {
      return { ts: '', level: 'info', msg: line };
    }
    return { ts: m[1], level: (m[2] || '').toLowerCase(), msg: m[3] || '' };
  }

  function loadLogs() {
    return api('logs', { max_lines: 200 }).then(function (res) {
      if (!res.ok || !res.data.ok) return;
      var d = res.data.data || {};
      logsLines = d.lines || [];
      renderLogs();
      renderLiveTrace();
    });
  }

  function renderLiveTrace() {
    var el = $('dashboard-live-trace');
    if (!el) return;
    el.innerHTML = '';
    var lines = (logsLines || []).slice(-25);
    if (!lines.length) {
      el.innerHTML = '<p class="muted">No sync trace yet.</p>';
      return;
    }
    lines.forEach(function (line) {
      var div = document.createElement('div');
      div.className = 'live-trace-line';
      div.textContent = line;
      el.appendChild(div);
    });
    el.scrollTop = el.scrollHeight;
  }

  function startLiveTracePolling() {
    stopLiveTracePolling();
    loadLogs();
    liveTraceTimer = setInterval(loadLogs, 2000);
  }

  function stopLiveTracePolling() {
    if (liveTraceTimer) {
      clearInterval(liveTraceTimer);
      liveTraceTimer = null;
    }
  }

  function renderLogs() {
    var filter = ($('logs-filter') && $('logs-filter').value) || 'all';
    var tbody = $('logs-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    var merged = logsLines.map(function (line, i) {
      var p = parseLogLine(line);
      return { raw: line, i: i, ts: p.ts, level: p.level, msg: p.msg };
    });
    var client = loadJson(LS.audit, []);
    client.forEach(function (row) {
      merged.unshift({
        raw: '',
        ts: row.timestamp.replace('T', ' ').replace(/\.\d{3}Z$/, ''),
        level: row.status === 'error' ? 'error' : (row.status === 'warning' ? 'warning' : 'info'),
        msg: '[' + row.action + '] ' + row.entity + ': ' + row.message,
      });
    });
    if (merged.length > 200) merged.length = 200;
    merged.forEach(function (row) {
      if (filter !== 'all' && row.level !== filter) return;
      var tr = document.createElement('tr');
      var badge = 'badge-muted';
      if (row.level === 'error' || row.level === 'critical') badge = 'badge-error';
      else if (row.level === 'warning' || row.level === 'notice') badge = 'badge-warning';
      else if (row.level === 'info' || row.level === 'debug') badge = 'badge-success';
      tr.innerHTML =
        '<td class="mono">' + esc(row.ts || '—') + '</td>' +
        '<td><span class="badge ' + badge + '">' + esc(row.level) + '</span></td>' +
        '<td class="mono" style="white-space:pre-wrap;max-width:520px">' + esc(row.msg) + '</td>';
      tbody.appendChild(tr);
    });
    var sc = $('logs-scroll');
    if (sc) sc.scrollTop = 0;
  }

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function loadProducts() {
    return api('products', {
      page: productsPage,
      per_page: productsPerPage,
      source: productsSource,
    }).then(function (res) {
      if (!res.ok || !res.data.ok) {
        showToast('Failed to load products');
        return;
      }
      var d = res.data.data || {};
      productsRows = d.rows || [];
      productsHasMore = !!d.has_more;
      var emptyEl = $('products-empty');
      if (emptyEl) {
        var showEmpty = productsSource === 'mapping' && Number(d.total) === 0;
        emptyEl.hidden = !showEmpty;
      }
      renderProductsTable();
      var lab = $('products-page-label');
      if (lab) {
        if (productsSource === 'prestashop') {
          lab.textContent = 'Page ' + d.page + (productsHasMore ? ' (more available)' : ' (end)');
        } else if (productsSource === 'failed') {
          lab.textContent = 'Page ' + d.page + ' · ' + d.total + ' failed products';
        } else {
          lab.textContent = 'Page ' + d.page + ' · ' + d.total + ' mapped';
        }
      }
      var nextBtn = $('products-next');
      if (nextBtn) {
        if (productsSource === 'prestashop') {
          nextBtn.disabled = !productsHasMore;
        } else {
          var total = parseInt(String(d.total || 0), 10) || 0;
          nextBtn.disabled = (d.page * (d.per_page || productsPerPage)) >= total;
        }
      }
    });
  }

  function renderProductsTable() {
    var tbody = $('products-tbody');
    if (!tbody) return;
    var q = (($('products-search') && $('products-search').value) || '').toLowerCase();
    var st = ($('products-filter-status') && $('products-filter-status').value) || 'all';
    tbody.innerHTML = '';
    productsRows.forEach(function (row) {
      if (q && String(row.name).toLowerCase().indexOf(q) === -1) return;
      if (st !== 'all' && row.status !== st) return;
      var tr = document.createElement('tr');
      var badge = 'badge-muted';
      if (row.status === 'error') badge = 'badge-error';
      else if (row.status === 'not_mapped') badge = 'badge-warning';
      else if (row.status === 'synced') badge = 'badge-success';
      tr.innerHTML =
        '<td><input type="checkbox" class="pr-check" data-id="' + row.id + '"></td>' +
        '<td>' + row.id + '</td>' +
        '<td>' + esc(row.name) + '</td>' +
        '<td>' + esc(row.price) + '</td>' +
        '<td>' + esc(row.stock) + '</td>' +
        '<td><span class="badge ' + badge + '">' + esc(row.status) + '</span></td>' +
        '<td class="mono">' + esc(row.last_synced || '—') + '</td>';
      if (row.failure && row.failure.reason) {
        tr.title = row.failure.reason;
      }
      tr.setAttribute('data-product-id', String(row.id));
      tr.className = 'product-row' + (selectedProductId === row.id ? ' is-selected' : '');
      tr.addEventListener('click', function (evt) {
        if (evt.target && evt.target.tagName === 'INPUT') return;
        loadProductDetail(row.id);
      });
      tbody.appendChild(tr);
    });
  }

  function loadProductDetail(productId) {
    selectedProductId = productId;
    renderProductsTable();
    var card = $('product-detail-card');
    var content = $('product-detail-content');
    var summary = $('product-detail-summary');
    if (card) card.hidden = false;
    if (summary) summary.textContent = 'Loading product #' + productId + '…';
    if (content) content.innerHTML = '<p class="muted">Loading diagnostics…</p>';
    return api('product_detail', { product_id: productId }).then(function (res) {
      if (!res.ok || !res.data.ok) {
        if (summary) summary.textContent = 'Failed to load product diagnostics';
        if (content) content.innerHTML = '<p class="muted">' + esc((res.data && res.data.error) || 'Unknown error') + '</p>';
        showToast('Failed to load product diagnostics');
        return;
      }
      renderProductDetail(res.data.data || {});
    });
  }

  function renderProductDetail(d) {
    var card = $('product-detail-card');
    var content = $('product-detail-content');
    var summary = $('product-detail-summary');
    if (!card || !content || !summary) return;
    summary.textContent =
      'Product #' + esc(d.product_id || '—') + ' · ' +
      esc(d.name || '—') +
      (d.reference ? ' · Ref ' + esc(d.reference) : '');

    var checks = d.checks || {};
    var features = d.feature_diagnostics || [];
    var variants = d.variant_diagnostics || [];
    var rawImages = d.raw_image_urls || [];
    var normalizedImages = d.normalized_image_urls || [];
    var payload = d.aboutyou_payload || {};
    var payloadJson = JSON.stringify(payload, null, 2);

    content.innerHTML =
      '<div class="detail-grid">' +
        '<div class="detail-block">' +
          '<h3>Checks</h3>' +
          '<div class="detail-chips">' +
            detailChip('Images', checks.has_images) +
            detailChip('Normalized', checks.has_normalized_images) +
            detailChip('Textile material', checks.has_material_textile) +
            detailChip('Variants', checks.has_variants) +
            detailChip('EAN complete', checks.has_ean_on_all_variants) +
          '</div>' +
          '<p class="muted">Category: ' + esc(d.category_name || '—') + ' · Active: ' + esc(d.active ? 'yes' : 'no') + '</p>' +
        '</div>' +
        '<div class="detail-block">' +
          '<h3>Material Features</h3>' +
          (features.length
            ? '<ul class="detail-list">' + features.map(function (row) {
                return '<li><strong>Feature ' + esc(row.feature_id) + '</strong> / value ' + esc(row.feature_value_id) + ': ' + esc(row.text || '—') + '</li>';
              }).join('') + '</ul>'
            : '<p class="muted">No product feature diagnostics available.</p>') +
        '</div>' +
      '</div>' +
      '<div class="detail-grid">' +
        '<div class="detail-block">' +
          '<h3>Original Images</h3>' +
          renderImageLinks(rawImages) +
        '</div>' +
        '<div class="detail-block">' +
          '<h3>Normalized Images</h3>' +
          renderImageLinks(normalizedImages) +
        '</div>' +
      '</div>' +
      '<div class="detail-block">' +
        '<h3>Variants</h3>' +
        (variants.length
          ? '<div class="table-wrap"><table class="data"><thead><tr><th>ID</th><th>Reference</th><th>Qty</th><th>EAN</th><th>Options</th></tr></thead><tbody>' +
            variants.map(function (row) {
              var options = (row.option_values || []).map(function (opt) {
                return esc((opt.group || 'Option') + ': ' + (opt.name || ('#' + opt.id)));
              }).join('<br>');
              return '<tr><td>' + esc(row.combination_id) + '</td><td>' + esc(row.reference || '—') + '</td><td>' + esc(row.quantity) + '</td><td>' + esc(row.ean13 || '—') + '</td><td>' + (options || '—') + '</td></tr>';
            }).join('') +
            '</tbody></table></div>'
          : '<p class="muted">No combinations. Product is treated as a single variant.</p>') +
      '</div>' +
      '<div class="detail-grid">' +
        '<div class="detail-block">' +
          '<h3>Resolved Textile Composition</h3>' +
          '<pre class="detail-pre">' + esc(JSON.stringify(d.material_composition_textile || null, null, 2)) + '</pre>' +
        '</div>' +
        '<div class="detail-block">' +
          '<h3>ABOUT YOU Payload Preview</h3>' +
          '<pre class="detail-pre">' + esc(payloadJson) + '</pre>' +
        '</div>' +
      '</div>';
  }

  function detailChip(label, ok) {
    return '<span class="badge ' + (ok ? 'badge-success' : 'badge-warning') + '">' + esc(label) + ': ' + (ok ? 'OK' : 'Missing') + '</span>';
  }

  function renderImageLinks(urls) {
    if (!urls || !urls.length) return '<p class="muted">No images.</p>';
    return '<div class="detail-image-list">' + urls.map(function (url) {
      return '<a class="detail-image-link mono" href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">' + esc(url) + '</a>';
    }).join('') + '</div>';
  }

  function loadOrders() {
    return api('orders', {
      page: ordersPage,
      per_page: ordersPerPage,
      source: ordersSource,
    }).then(function (res) {
      if (!res.ok || !res.data.ok) {
        showToast('Failed to load orders');
        return;
      }
      var d = res.data.data || {};
      ordersRows = d.rows || [];
      ordersHasMore = !!d.has_more;
      var emptyEl = $('orders-empty');
      if (emptyEl) {
        var showEmpty = ordersSource === 'mapping' && Number(d.total) === 0;
        emptyEl.hidden = !showEmpty;
      }
      renderOrdersTable();
      var lab = $('orders-page-label');
      if (lab) {
        if (ordersSource === 'prestashop') {
          lab.textContent = 'Page ' + d.page + (ordersHasMore ? ' (more available)' : ' (end)');
        } else {
          lab.textContent = 'Page ' + d.page + ' · ' + d.total + ' rows';
        }
      }
      var nextBtn = $('orders-next');
      if (nextBtn) {
        if (ordersSource === 'prestashop') {
          nextBtn.disabled = !ordersHasMore;
        } else {
          var total = parseInt(String(d.total || 0), 10) || 0;
          nextBtn.disabled = (d.page * (d.per_page || ordersPerPage)) >= total;
        }
      }
    });
  }

  function orderAutoMap() {
    return loadJson(LS.orderAuto, {});
  }

  function setOrderAuto(ayId, on) {
    var m = orderAutoMap();
    m[ayId] = !!on;
    saveJson(LS.orderAuto, m);
  }

  function renderOrdersTable() {
    var tbody = $('orders-tbody');
    if (!tbody) return;
    var stf = ($('orders-filter-sync') && $('orders-filter-sync').value) || 'all';
    var autoM = orderAutoMap();
    tbody.innerHTML = '';
    ordersRows.forEach(function (row) {
      if (stf !== 'all' && row.sync_status !== stf) return;
      var tr = document.createElement('tr');
      var badge = 'badge-muted';
      if (row.sync_status === 'error') badge = 'badge-error';
      else if (row.sync_status === 'pending') badge = 'badge-warning';
      else if (row.sync_status === 'not_mapped') badge = 'badge-warning';
      else if (row.sync_status === 'synced') badge = 'badge-success';
      var ayRaw = String(row.ay_order_id || '');
      var aySafeAttr = ayRaw.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
      var ayCell = ayRaw ? esc(ayRaw) : '—';
      var checked = ayRaw && autoM[ayRaw] ? ' checked' : '';
      var psId = row.ps_order_id;
      tr.innerHTML =
        '<td><input type="checkbox" class="ord-check" data-ps="' + psId + '"></td>' +
        '<td>' + psId + '</td>' +
        '<td class="mono">' + ayCell + '</td>' +
        '<td>' + esc(row.customer) + '</td>' +
        '<td>' + esc(row.total) + '</td>' +
        '<td>' + esc(row.status) + '</td>' +
        '<td><span class="badge ' + badge + '">' + esc(row.sync_status) + '</span></td>' +
        '<td class="mono">' + esc(row.date) + '</td>' +
        '<td><label class="switch" style="vertical-align:middle"><input type="checkbox" class="ord-auto" data-ay="' + aySafeAttr + '"' + checked + (ayRaw ? '' : ' disabled') + '><span class="slider"></span></label></td>';
      tbody.appendChild(tr);
    });
    tbody.querySelectorAll('.ord-auto').forEach(function (cb) {
      if (cb.disabled) return;
      cb.addEventListener('change', function () {
        setOrderAuto(cb.getAttribute('data-ay'), cb.checked);
      });
    });
  }

  function webhookUrl() {
    var loc = window.location;
    var base = loc.origin + loc.pathname.replace(/\/[^/]*$/, '/');
    return base + 'webhook.php';
  }

  function randomHex(n) {
    var s = '';
    var hex = '0123456789abcdef';
    for (var i = 0; i < n; i++) s += hex[Math.floor(Math.random() * 16)];
    return s;
  }

  function pushWebhookEvent(type, preview) {
    var log = $('webhook-event-log');
    if (!log) return;
    var div = document.createElement('div');
    div.className = 'ev';
    div.innerHTML =
      '<div><strong>' + esc(type) + '</strong> · ' + new Date().toISOString() + '</div>' +
      '<div class="muted" style="margin-top:4px">' + esc(preview) + '</div>';
    log.insertBefore(div, log.firstChild);
    while (log.children.length > 50) log.removeChild(log.lastChild);
  }

  function simulateWebhook() {
    if (localStorage.getItem(LS.webhook) !== '1') return;
    var types = ['order.created', 'order.updated', 'product.updated', 'stock.updated'];
    var type = types[Math.floor(Math.random() * types.length)];
    var preview = JSON.stringify({ event: type, id: String(Math.floor(Math.random() * 1e6)) });
    pushWebhookEvent(type, preview);
    if (type === 'order.created') {
      showToast('New order received via webhook — syncing…');
      runSync('orders', 'Importing orders…');
    }
  }

  function restartWebhookTimer() {
    if (webhookTimer) clearInterval(webhookTimer);
    webhookTimer = setInterval(simulateWebhook, 30000);
  }

  function loadWebhookLogs() {
    return api('webhook_logs', { max_lines: 50 }).then(function (res) {
      if (!res.ok || !res.data.ok) return;
      var d = res.data.data || {};
      var lines = d.lines || [];
      var log = $('webhook-event-log');
      if (!log) return;
      log.innerHTML = '';
      lines.slice().reverse().forEach(function (line) {
        var div = document.createElement('div');
        div.className = 'ev';
        div.innerHTML = '<div class="mono" style="white-space:pre-wrap">' + esc(line) + '</div>';
        log.appendChild(div);
      });
      if (!lines.length) {
        log.innerHTML = '<p class="muted">No webhook deliveries logged yet.</p>';
      }
    });
  }

  function restartWebhookLogsTimer() {
    if (webhookLogsTimer) clearInterval(webhookLogsTimer);
    loadWebhookLogs();
    webhookLogsTimer = setInterval(loadWebhookLogs, 15000);
  }

  function restartAutoSync() {
    if (autoSyncTimer) clearInterval(autoSyncTimer);
    var s = loadSettings();
    if (!s.autoSync) return;
    var min = parseInt(s.interval, 10) || 15;
    if (min < 5) {
      showToast('Warning: intervals under 5 minutes are not recommended');
    }
    var ms = Math.max(1, min) * 60 * 1000;
    autoSyncTimer = setInterval(function () {
      if (!document.hidden) {
        var cmd = s.autoCommand || 'stock';
        runSync(cmd, 'Auto: ' + cmd);
      }
    }, ms);
  }

  /** Build merged log rows (same logic as renderLogs) for CSV export. */
  function buildMergedLogRows() {
    var merged = logsLines.map(function (line) {
      var p = parseLogLine(line);
      return { ts: p.ts, level: p.level, msg: p.msg };
    });
    var client = loadJson(LS.audit, []);
    client.forEach(function (row) {
      merged.unshift({
        ts: row.timestamp.replace('T', ' ').replace(/\.\d{3}Z$/, ''),
        level: row.status === 'error' ? 'error' : (row.status === 'warning' ? 'warning' : 'info'),
        msg: '[' + row.action + '] ' + row.entity + ': ' + row.message,
      });
    });
    if (merged.length > 200) merged.length = 200;
    return merged;
  }

  function exportLogsCsv() {
    var filter = ($('logs-filter') && $('logs-filter').value) || 'all';
    var rows = [];
    rows.push(['timestamp', 'level', 'message'].join(','));
    buildMergedLogRows().forEach(function (row) {
      if (filter !== 'all' && row.level !== filter) return;
      rows.push([csv(row.ts || ''), csv(row.level || ''), csv(row.msg || '')].join(','));
    });
    var blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'sync-logs-export.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    showToast('Exported ' + (rows.length - 1) + ' rows');
  }

  function csv(s) {
    s = String(s);
    if (/[",\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }

  function initLogin() {
    var form = $('login-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var err = $('login-error');
      if (err) err.textContent = '';
      var user = $('login-user').value;
      var pass = $('login-pass').value;
      api('login', { username: user, password: pass }).then(function (res) {
        if (!res.ok || !res.data.ok) {
          if (err) err.textContent = (res.data && res.data.error) || 'Login failed';
          return;
        }
        var d = res.data.data || {};
        csrf = d.csrf || csrf;
        boot.test_mode = d.test_mode;
        boot.dry_run = d.dry_run;
        showScreen(false);
        initAppAfterLogin();
        appendActivity('Logged in');
        showToast('Welcome');
      });
    });
  }

  function stopDashboardTimers() {
    if (webhookTimer) {
      clearInterval(webhookTimer);
      webhookTimer = null;
    }
    if (webhookLogsTimer) {
      clearInterval(webhookLogsTimer);
      webhookLogsTimer = null;
    }
    if (autoSyncTimer) {
      clearInterval(autoSyncTimer);
      autoSyncTimer = null;
    }
    if (statsTimer) {
      clearInterval(statsTimer);
      statsTimer = null;
    }
    stopLiveTracePolling();
  }

  function initLogout() {
    var btn = $('btn-logout');
    if (!btn || btn.dataset.bound) return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      api('logout', {}).then(function () {
        csrf = '';
        stopDashboardTimers();
        showScreen(true);
        showToast('Logged out');
      });
    });
  }

  /** Wire tab panel buttons once (safe across login / logout cycles). */
  function bindDashboardUiOnce() {
    if (uiBound) return;
    uiBound = true;
    initLogout();
    applyTheme();
    document.querySelectorAll('.btn-theme-toggle').forEach(function (el) {
      el.addEventListener('click', function () {
        if (localStorage.getItem(THEME_KEY) === 'dark') {
          localStorage.removeItem(THEME_KEY);
        } else {
          localStorage.setItem(THEME_KEY, 'dark');
        }
        applyTheme();
      });
    });
    document.querySelectorAll('.dashboard-tab-go').forEach(function (el) {
      el.addEventListener('click', function () {
        activateTab(el.getAttribute('data-tab'));
      });
    });
    $('btn-sidebar-open') && $('btn-sidebar-open').addEventListener('click', function () {
      setSidebarOpen(true);
    });
    $('sidebar-backdrop') && $('sidebar-backdrop').addEventListener('click', function () {
      setSidebarOpen(false);
    });
    $('btn-sidebar-collapse') && $('btn-sidebar-collapse').addEventListener('click', function () {
      setSidebarOpen(!document.body.classList.contains('sidebar-open'));
    });

    $('btn-sync-now') && $('btn-sync-now').addEventListener('click', function () {
      runSync('all', 'Full sync…');
    });
    $('btn-current-job-refresh') && $('btn-current-job-refresh').addEventListener('click', refreshStats);
    $('btn-products-refresh') && $('btn-products-refresh').addEventListener('click', loadProducts);
    $('products-src-mapping') && $('products-src-mapping').addEventListener('click', function () {
      productsSource = 'mapping';
      productsPage = 1;
      updateProductSourceButtons();
      loadProducts();
    });
    $('products-src-ps') && $('products-src-ps').addEventListener('click', function () {
      productsSource = 'prestashop';
      productsPage = 1;
      updateProductSourceButtons();
      loadProducts();
    });
    $('products-src-failed') && $('products-src-failed').addEventListener('click', function () {
      productsSource = 'failed';
      productsPage = 1;
      updateProductSourceButtons();
      loadProducts();
    });
    $('btn-products-fetch-ps') && $('btn-products-fetch-ps').addEventListener('click', function () {
      productsSource = 'prestashop';
      productsPage = 1;
      updateProductSourceButtons();
      loadProducts();
    });
    $('btn-products-push-all-mapped') && $('btn-products-push-all-mapped').addEventListener('click', function () {
      runSync('stock', 'Stock & price (all mapped)…');
    });
    $('btn-products-retry-selected') && $('btn-products-retry-selected').addEventListener('click', function () {
      var ids = [];
      document.querySelectorAll('.pr-check:checked').forEach(function (c) {
        var id = parseInt(c.getAttribute('data-id'), 10);
        if (id > 0) ids.push(id);
      });
      if (!ids.length) {
        showToast('Select at least one product');
        return;
      }
      runSync('products', 'Retry product sync (selected)…', { ps_product_ids: ids }).then(function () {
        loadProducts();
      });
    });
    $('btn-products-push-selected') && $('btn-products-push-selected').addEventListener('click', function () {
      var ids = [];
      document.querySelectorAll('.pr-check:checked').forEach(function (c) {
        var id = parseInt(c.getAttribute('data-id'), 10);
        if (id > 0) ids.push(id);
      });
      if (!ids.length) {
        showToast('Select at least one product');
        return;
      }
      runSync('stock', 'Stock & price (selected)…', { ps_product_ids: ids });
    });
    $('products-search') && $('products-search').addEventListener('input', renderProductsTable);
    $('products-filter-status') && $('products-filter-status').addEventListener('change', renderProductsTable);
    $('products-check-all') && $('products-check-all').addEventListener('change', function () {
      var on = $('products-check-all').checked;
      document.querySelectorAll('.pr-check').forEach(function (c) { c.checked = on; });
    });
    $('products-prev') && $('products-prev').addEventListener('click', function () {
      if (productsPage > 1) { productsPage--; loadProducts(); }
    });
    $('products-next') && $('products-next').addEventListener('click', function () {
      if (productsSource === 'prestashop' && !productsHasMore) return;
      productsPage++;
      loadProducts();
    });
    $('btn-product-detail-close') && $('btn-product-detail-close').addEventListener('click', function () {
      selectedProductId = 0;
      var card = $('product-detail-card');
      var content = $('product-detail-content');
      if (card) card.hidden = true;
      if (content) content.innerHTML = '';
      renderProductsTable();
    });

    $('orders-src-mapping') && $('orders-src-mapping').addEventListener('click', function () {
      ordersSource = 'mapping';
      ordersPage = 1;
      updateOrdersSourceButtons();
      loadOrders();
    });
    $('orders-src-ps') && $('orders-src-ps').addEventListener('click', function () {
      ordersSource = 'prestashop';
      ordersPage = 1;
      updateOrdersSourceButtons();
      loadOrders();
    });
    $('btn-orders-fetch-ps') && $('btn-orders-fetch-ps').addEventListener('click', function () {
      ordersSource = 'prestashop';
      ordersPage = 1;
      updateOrdersSourceButtons();
      loadOrders();
    });
    $('btn-orders-refresh') && $('btn-orders-refresh').addEventListener('click', loadOrders);
    $('btn-orders-import') && $('btn-orders-import').addEventListener('click', function () {
      runSync('orders', 'Import orders from AboutYou…');
    });
    $('btn-orders-import-empty') && $('btn-orders-import-empty').addEventListener('click', function () {
      runSync('orders', 'Import orders from AboutYou…');
    });
    $('btn-orders-push-all') && $('btn-orders-push-all').addEventListener('click', function () {
      runSync('order-status', 'Push order status (all modified)…');
    });
    $('btn-orders-push-selected') && $('btn-orders-push-selected').addEventListener('click', function () {
      var ids = [];
      document.querySelectorAll('.ord-check:checked').forEach(function (c) {
        var id = parseInt(c.getAttribute('data-ps'), 10);
        if (id > 0) ids.push(id);
      });
      if (!ids.length) {
        showToast('Select at least one PrestaShop order');
        return;
      }
      runSync('order-status', 'Push order status (selected)…', { ps_order_ids: ids });
    });
    $('orders-filter-sync') && $('orders-filter-sync').addEventListener('change', renderOrdersTable);
    $('orders-check-all') && $('orders-check-all').addEventListener('change', function () {
      var on = $('orders-check-all').checked;
      document.querySelectorAll('.ord-check').forEach(function (c) { c.checked = on; });
    });
    $('orders-prev') && $('orders-prev').addEventListener('click', function () {
      if (ordersPage > 1) { ordersPage--; loadOrders(); }
    });
    $('orders-next') && $('orders-next').addEventListener('click', function () {
      if (ordersSource === 'prestashop' && !ordersHasMore) return;
      ordersPage++;
      loadOrders();
    });
    var wurl = $('webhook-url');
    if (wurl) wurl.textContent = webhookUrl();
    $('btn-webhook-copy') && $('btn-webhook-copy').addEventListener('click', function () {
      navigator.clipboard.writeText(webhookUrl()).then(function () {
        showToast('Webhook URL copied');
      });
    });
    var whEn = $('webhook-enabled');
    if (whEn) {
      whEn.addEventListener('change', function () {
        localStorage.setItem(LS.webhook, whEn.checked ? '1' : '0');
        refreshStats();
        showToast(whEn.checked ? 'Webhook listener on' : 'Webhook listener off');
      });
    }
    $('wizard-webhook-url');
    $('btn-settings-save') && $('btn-settings-save').addEventListener('click', saveSettings);
    $('btn-toggle-test') && $('btn-toggle-test').addEventListener('click', function () {
      api('toggle', { key: 'TEST_MODE', value: !boot.test_mode }).then(function (res) {
        if (res.ok && res.data && res.data.ok) {
          var d = res.data.data || {};
          boot.test_mode = !!d.test_mode;
          refreshSafetyPills(!!boot.test_mode, !!boot.dry_run);
          showToast('TEST_MODE updated — reload if needed');
        } else showToast('Toggle failed');
      });
    });
    $('btn-toggle-dry') && $('btn-toggle-dry').addEventListener('click', function () {
      api('toggle', { key: 'DRY_RUN', value: !boot.dry_run }).then(function (res) {
        if (res.ok && res.data && res.data.ok) {
          var d = res.data.data || {};
          boot.dry_run = !!d.dry_run;
          refreshSafetyPills(!!boot.test_mode, !!boot.dry_run);
          showToast('DRY_RUN updated');
        } else showToast('Toggle failed');
      });
    });
    $('btn-settings-test') && $('btn-settings-test').addEventListener('click', function () {
      var out = $('settings-test-result');
      if (out) out.textContent = 'Testing…';
      api('test_connection', {}).then(function (res) {
        var d = res.data && res.data.data !== undefined ? res.data.data : res.data;
        if (out) {
          out.textContent = (d && d.message) || (res.data && res.data.error) || '';
          out.style.color = d && d.ok ? 'var(--success)' : 'var(--danger)';
        }
      });
    });
    $('logs-filter') && $('logs-filter').addEventListener('change', renderLogs);
    $('btn-logs-export') && $('btn-logs-export').addEventListener('click', exportLogsCsv);
    $('btn-live-trace-refresh') && $('btn-live-trace-refresh').addEventListener('click', loadLogs);
    initWizard();
  }

  /** Refresh session, stats, timers (call after login or on first authenticated load). */
  function startDashboardSession() {
    stopDashboardTimers();
    var whEn = $('webhook-enabled');
    if (whEn) whEn.checked = localStorage.getItem(LS.webhook) === '1';
    if ($('webhook-url')) $('webhook-url').textContent = webhookUrl();
    if ($('wizard-webhook-url')) $('wizard-webhook-url').textContent = webhookUrl();
    loadWebhookLogs();
    refreshSafetyPills(!!boot.test_mode, !!boot.dry_run);
    api('bootstrap', {}).then(function (res) {
      if (res.ok && res.data && res.data.ok) {
        var d = res.data.data || {};
        if (d.csrf) csrf = d.csrf;
        boot.test_mode = d.test_mode;
        boot.dry_run = d.dry_run;
        boot.ps_base_url_configured = !!d.ps_base_url_configured;
        boot.ps_base_url_hint = d.ps_base_url_hint || boot.ps_base_url_hint;
        refreshSafetyPills(!!boot.test_mode, !!boot.dry_run);
      }
    });
    refreshStats();
    loadLogs();
    renderActivity();
    applySettingsToForm();
    updateProductSourceButtons();
    updateOrdersSourceButtons();
    restartAutoSync();
    restartWebhookTimer();
    restartWebhookLogsTimer();
    statsTimer = setInterval(function () {
      var panel = $('tab-dashboard');
      if (panel && panel.classList.contains('active')) refreshStats();
    }, 60000);
  }

  function initAppAfterLogin() {
    bindDashboardUiOnce();
    startDashboardSession();
  }

  var wizardStep = 1;
  var wizardBound = false;
  function initWizard() {
    if (wizardBound) return;
    wizardBound = true;
    var next = $('wizard-next');
    var back = $('wizard-back');
    function dots() {
      document.querySelectorAll('.wizard-steps .step-dot').forEach(function (d) {
        d.classList.toggle('active', d.getAttribute('data-step') === String(wizardStep));
      });
    }
    function showStep() {
      for (var i = 1; i <= 4; i++) {
        var p = $('wizard-' + i);
        if (p) p.hidden = i !== wizardStep;
      }
      dots();
      var hint = $('setup-env-hint');
      if (hint) hint.textContent = boot.ps_base_url_configured
        ? 'Server reports PS_BASE_URL is set (' + (boot.ps_base_url_hint || '') + ').'
        : 'PS_BASE_URL is not set in .env on the server.';
    }
    next && next.addEventListener('click', function () {
      if (wizardStep === 1 && !boot.ps_base_url_configured) {
        showToast('Set PS_BASE_URL in server .env first');
        return;
      }
      if (wizardStep < 4) wizardStep++;
      showStep();
    });
    back && back.addEventListener('click', function () {
      if (wizardStep > 1) wizardStep--;
      showStep();
    });
    $('wizard-test-btn') && $('wizard-test-btn').addEventListener('click', function () {
      var msg = $('wizard-test-msg');
      api('test_connection', {}).then(function (res) {
        var d = res.data && res.data.data !== undefined ? res.data.data : res.data;
        if (msg) msg.textContent = (d && d.message) || '';
      });
    });
    $('wizard-sync-btn') && $('wizard-sync-btn').addEventListener('click', function () {
      var msg = $('wizard-sync-msg');
      runSync('all', 'First sync…').then(function () {
        if (msg) msg.textContent = 'First sync request finished — check logs.';
      });
    });
    showStep();
  }

  function start() {
    applyTheme();
    initTabs();
    initLogin();
    bindDashboardUiOnce();
    if (boot.authenticated) {
      showScreen(false);
      startDashboardSession();
    } else {
      showScreen(true);
    }
  }

  start();
})();
