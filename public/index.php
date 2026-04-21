<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Sync\Config\AppConfig;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$config = new AppConfig();
if (!$config->uiEnabled()) {
    http_response_code(403);
    echo 'UI is disabled. Set UI_ENABLED=true in .env to enable it.';
    exit;
}

session_start();

$authenticated = !empty($_SESSION['ui_authenticated']);
if ($authenticated && empty($_SESSION['ui_csrf'])) {
    $_SESSION['ui_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $authenticated ? (string) ($_SESSION['ui_csrf'] ?? '') : '';
$baseUrl = (string) ($_ENV['PS_BASE_URL'] ?? '');
$maskedBase = $baseUrl !== '' ? (preg_replace('#^(https?://[^/]+).*$#', '$1/…', $baseUrl) ?: 'configured') : '';

$bootstrap = [
    'authenticated' => $authenticated,
    'csrf' => $csrf,
    'test_mode' => $config->isTestMode(),
    'dry_run' => $config->isDryRun(),
    'ps_base_url_hint' => $maskedBase,
    'ps_base_url_configured' => trim($baseUrl) !== '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PrestaShop Sync Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;0,9..40,700;1,9..40,400&family=IBM+Plex+Mono:wght@400;500&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="dashboard.css?v=20260418c">
  <script>
    (function () {
      try {
        if (localStorage.getItem('prestasync_theme') !== 'dark') {
          document.documentElement.setAttribute('data-theme', 'light');
        }
      } catch (e) {}
    })();
  </script>
</head>
<body>
  <div id="screen-login" class="screen"<?= $authenticated ? ' hidden' : '' ?>>
    <div class="login-wrap">
      <div class="login-card">
        <h1>PrestaShop <span style="color:var(--accent)">Sync</span></h1>
        <p class="sub">Sign in to manage AboutYou ↔ PrestaShop sync.</p>
        <form id="login-form">
          <div class="field">
            <label for="login-user">Username</label>
            <input id="login-user" name="username" type="text" autocomplete="username" required>
          </div>
          <div class="field">
            <label for="login-pass">Password</label>
            <input id="login-pass" name="password" type="password" autocomplete="current-password" required>
          </div>
          <p id="login-error" class="muted" style="color:var(--danger);min-height:1.2em;margin:0 0 10px"></p>
          <button type="submit" class="btn btn-primary" style="width:100%">Log in</button>
        </form>
      </div>
    </div>
  </div>

  <div id="screen-app" class="screen"<?= $authenticated ? '' : ' hidden' ?>>
    <div class="app-layout">
      <button type="button" class="sidebar-fab" id="btn-sidebar-open" aria-label="Open navigation">☰</button>
      <div id="sidebar-backdrop" class="sidebar-backdrop" hidden aria-hidden="true"></div>
      <aside id="app-sidebar" class="app-sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
          <p class="brand">Presta<span>Sync</span></p>
          <p class="sidebar-tagline muted">AboutYou ↔ PrestaShop</p>
        </div>
        <nav class="sidebar-nav" role="tablist" aria-label="Main">
          <button type="button" class="sidebar-link" role="tab" data-tab="dashboard" aria-selected="true"><span class="sidebar-ico">◉</span> Dashboard</button>
          <button type="button" class="sidebar-link" role="tab" data-tab="products"><span class="sidebar-ico">▣</span> Products</button>
          <button type="button" class="sidebar-link" role="tab" data-tab="orders"><span class="sidebar-ico">☰</span> Orders</button>
          <button type="button" class="sidebar-link" role="tab" data-tab="webhooks"><span class="sidebar-ico">⚡</span> Webhooks</button>
          <button type="button" class="sidebar-link" role="tab" data-tab="settings"><span class="sidebar-ico">⚙</span> Settings</button>
          <button type="button" class="sidebar-link" role="tab" data-tab="logs"><span class="sidebar-ico">▤</span> Logs</button>
          <button type="button" class="sidebar-link" role="tab" data-tab="setup"><span class="sidebar-ico">✓</span> Setup</button>
        </nav>
        <div class="sidebar-footer">
          <button type="button" class="btn btn-sm btn-ghost btn-theme-toggle" id="btn-theme-toggle" title="Toggle light/dark">Dark mode</button>
        </div>
      </aside>
      <div class="app-frame">
        <header class="app-topbar">
          <button type="button" class="btn btn-sm btn-ghost sidebar-inline-toggle" id="btn-sidebar-collapse" aria-label="Toggle sidebar">☰</button>
          <div class="app-topbar-spacer"></div>
          <div class="topbar-right">
            <button type="button" class="btn btn-sm btn-ghost btn-theme-toggle topbar-theme" title="Toggle light/dark">Dark mode</button>
            <span class="pill" id="pill-test">TEST: <strong id="pill-test-val">—</strong></span>
            <span class="pill" id="pill-dry">DRY: <strong id="pill-dry-val">—</strong></span>
            <button type="button" class="btn btn-sm btn-ghost" id="btn-logout">Log out</button>
          </div>
        </header>

        <main class="app-main">
      <section id="tab-dashboard" class="tab-panel active" role="tabpanel">
        <div class="dashboard-hero">
          <h1 class="dashboard-title">Overview</h1>
          <p class="muted dashboard-lead">Mapping store health, sync controls, and quick access to catalog tools.</p>
          <div class="row wrap gap dashboard-quick" aria-label="Quick navigation">
            <button type="button" class="btn btn-sm btn-primary dashboard-tab-go" data-tab="products">Products</button>
            <button type="button" class="btn btn-sm btn-primary dashboard-tab-go" data-tab="orders">Orders</button>
            <button type="button" class="btn btn-sm btn-ghost dashboard-tab-go" data-tab="logs">Logs</button>
            <button type="button" class="btn btn-sm btn-ghost dashboard-tab-go" data-tab="settings">Settings</button>
          </div>
        </div>
        <div class="grid-stats">
          <div class="card"><h2>Total products (mapped)</h2><div class="stat-val" id="stat-products">—</div><p class="muted">From mapping store</p></div>
          <div class="card"><h2>Synced orders</h2><div class="stat-val" id="stat-orders">—</div><p class="muted">Mapped AY → PS orders</p></div>
          <div class="card"><h2>Last sync</h2><div class="stat-val" id="stat-last" style="font-size:1rem">—</div><p class="muted">From store + this browser</p></div>
          <div class="card"><h2>Webhook status</h2><div class="stat-val" id="stat-webhook" style="font-size:1rem">—</div><p class="muted">Listener (browser sim)</p></div>
        </div>
        <div class="ui-help" role="note" aria-label="Metric tiles help">
          <div class="ui-help-title">Metrics</div>
          <ul>
            <li><strong>Products / orders</strong> — counts from the server mapping store (linked items), not the full PrestaShop catalog.</li>
            <li><strong>Last sync</strong> — server-reported last runs plus an optional timestamp from this browser after a successful UI sync.</li>
            <li><strong>Webhook</strong> — whether the in-browser listener simulation is on (not your real PrestaShop webhook delivery).</li>
          </ul>
        </div>
        <div class="card">
          <div class="row spread">
            <h2 style="margin:0">Live sync</h2>
            <button type="button" class="btn btn-primary" id="btn-sync-now" title="Runs server command all: stock, orders import, order-status push">Sync now</button>
          </div>
          <div class="progress-wrap" id="sync-progress-wrap">
            <div class="progress-track"><div class="progress-fill" id="sync-progress-fill"></div></div>
            <div class="progress-meta"><span id="sync-progress-label">Idle</span><span id="sync-progress-pct">0%</span></div>
          </div>
          <div class="ui-help" role="note" aria-label="Live sync help">
            <div class="ui-help-title">What “Sync now” does</div>
            <p class="muted">Runs <code class="mono">all</code>: updates stock for mapped products, imports new AboutYou orders, pushes order status to AboutYou. It does <strong>not</strong> build product style mappings — run <code class="mono">products</code> or <code class="mono">products:inc</code> via CLI/cron, API <code class="mono">sync</code>, or Settings → auto-sync command.</p>
          </div>
        </div>
        <div class="card mt">
          <div class="row spread">
            <h2 style="margin:0">Current Sync Job</h2>
            <button type="button" class="btn btn-sm" id="btn-current-job-refresh">Refresh</button>
          </div>
          <div class="job-grid">
            <div><span class="muted">Command</span><div class="mono" id="job-command">—</div></div>
            <div><span class="muted">Phase</span><div class="mono" id="job-phase">—</div></div>
            <div><span class="muted">Current Product</span><div class="mono" id="job-product">—</div></div>
            <div><span class="muted">Progress</span><div class="mono" id="job-progress">—</div></div>
            <div><span class="muted">Counters</span><div class="mono" id="job-counters">—</div></div>
            <div><span class="muted">Elapsed / ETA</span><div class="mono" id="job-time">—</div></div>
          </div>
          <p class="muted" id="job-message" style="margin:12px 0 0">No active sync job.</p>
        </div>
        <div class="card mt">
          <h2>Recent activity</h2>
          <div class="ui-help" role="note" aria-label="Activity feed help">
            <div class="ui-help-title">Activity feed</div>
            <p class="muted">High-level messages from this browser (sync clicks, settings saves). For server-side detail use <strong>Logs</strong>.</p>
          </div>
          <div class="feed" id="activity-feed"></div>
        </div>
        <div class="card mt">
          <div class="row spread">
            <h2 style="margin:0">Live Sync Trace</h2>
            <button type="button" class="btn btn-sm" id="btn-live-trace-refresh">Refresh</button>
          </div>
          <p class="muted" style="margin:8px 0 12px">This updates while a sync is running so the admin can see each PrestaShop fetch and ABOUT YOU post in near real time.</p>
          <div class="live-trace" id="dashboard-live-trace"></div>
        </div>
      </section>

      <section id="tab-products" class="tab-panel" role="tabpanel" hidden>
        <div class="card mb" id="products-empty" hidden>
          <h2 style="margin:0 0 8px">No mapped products yet</h2>
          <p class="muted" style="margin:0 0 14px">The mapping store is empty. Load the PrestaShop catalog to browse products, then run a product sync from the CLI or Dashboard when you are ready to build mappings.</p>
          <button type="button" class="btn btn-primary" id="btn-products-fetch-ps" title="Switch list to PrestaShop IDs (catalog view)">Load from PrestaShop</button>
          <div class="ui-help" role="note" aria-label="Empty products help">
            <div class="ui-help-title">Next steps</div>
            <ul>
              <li><strong>Catalog</strong> — browse PrestaShop product IDs; <code>NOT_MAPPED</code> is normal until a product sync creates AY ↔ PS rows.</li>
              <li><strong>Mappings</strong> — run <code class="mono">products</code> or <code class="mono">products:inc</code> on the server (not included in Dashboard “Sync now”).</li>
            </ul>
          </div>
        </div>
        <div class="card mb">
          <div class="row spread">
            <h2 style="margin:0">Products</h2>
            <div class="row">
              <button type="button" class="btn btn-sm" id="btn-products-refresh" title="Reload current list from server">Refresh</button>
              <button type="button" class="btn btn-sm btn-primary" id="btn-products-push-selected" title="Runs stock sync for checked PrestaShop product IDs only">Push stock to AboutYou (selected)</button>
            </div>
          </div>
          <p class="muted" style="margin:8px 0 0">Pushes price and stock for <strong>mapped</strong> SKUs only. With rows selected, only those PrestaShop product IDs are sent; otherwise use “Push stock (all mapped)” from bulk actions or run a full <code class="mono">stock</code> sync from Dashboard.</p>
          <div class="row mt wrap gap">
            <div class="segmented" role="group" aria-label="Product list source" title="Mapped = mapping store. Catalog = all PS product IDs.">
              <button type="button" class="seg-btn active" id="products-src-mapping" data-products-source="mapping" title="Rows from mapping store (linked to AboutYou styles)">Mapped only</button>
              <button type="button" class="seg-btn" id="products-src-ps" data-products-source="prestashop" title="PrestaShop product IDs; mapping flags per row">PrestaShop catalog</button>
              <button type="button" class="seg-btn" id="products-src-failed" data-products-source="failed" title="Products that failed during sync and can be retried">Failed queue</button>
            </div>
            <input type="search" id="products-search" class="input-inline" placeholder="Search name…" title="Filters the current page client-side (name column)">
            <select id="products-filter-status" class="input-inline" title="Filters the current page client-side by sync status">
              <option value="all">All statuses</option>
              <option value="synced">Synced / mapped</option>
              <option value="not_mapped">Not mapped</option>
              <option value="pending">Pending</option>
              <option value="error">Error</option>
            </select>
            <button type="button" class="btn btn-sm" id="btn-products-push-all-mapped" title="Runs stock sync for all mapped products (no row selection)">Push stock (all mapped)</button>
            <button type="button" class="btn btn-sm" id="btn-products-retry-selected" title="Retry product sync for selected product IDs">Retry product sync (selected)</button>
          </div>
          <div class="ui-help" role="note" aria-label="Products list help">
            <div class="ui-help-title">List &amp; columns</div>
            <ul>
              <li><strong>Mapped only</strong> — products known to the sync mapping store; names/prices may be filled from PrestaShop when the API returns them.</li>
              <li><strong>PrestaShop catalog</strong> — paged PS IDs; <code>NOT_MAPPED</code> means no AY link yet. Dashes in name/price/stock usually mean empty PS webservice fields or key permissions.</li>
              <li><strong>Header checkbox</strong> — toggles all row checkboxes on this page for push actions; verify rows before pushing.</li>
            </ul>
          </div>
        </div>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th><input type="checkbox" id="products-check-all" aria-label="Select all"></th><th>ID</th><th>Name</th><th>Price</th><th>Stock</th><th>Status</th><th>Last synced</th></tr></thead>
            <tbody id="products-tbody"></tbody>
          </table>
        </div>
        <div class="row mt">
          <button type="button" class="btn btn-sm" id="products-prev" title="Previous page">Previous</button>
          <span class="muted" id="products-page-label"></span>
          <button type="button" class="btn btn-sm" id="products-next" title="Next page (catalog may indicate more available)">Next</button>
        </div>
        <p class="muted" style="margin:10px 0 0;font-size:0.8rem">Pagination is server-driven; search/status filters apply only to rows already loaded on this page.</p>
        <div class="card mt" id="product-detail-card" hidden>
          <div class="row spread">
            <h2 style="margin:0">Product Diagnostics</h2>
            <button type="button" class="btn btn-sm" id="btn-product-detail-close">Close</button>
          </div>
          <p class="muted" id="product-detail-summary" style="margin:8px 0 14px">Select a product row to inspect the resolved ABOUT YOU payload.</p>
          <div id="product-detail-content" class="product-detail-content"></div>
        </div>
      </section>

      <section id="tab-orders" class="tab-panel" role="tabpanel" hidden>
        <div class="card mb" id="orders-empty" hidden>
          <h2 style="margin:0 0 8px">No orders from AboutYou in the mapping store yet</h2>
          <p class="muted" style="margin:0 0 14px">This tab lists PrestaShop orders that are linked (or failed to link) to AboutYou. To pull <strong>new orders from AboutYou</strong> into PrestaShop, run import. To browse orders that already exist only in PrestaShop, open the catalog view.</p>
          <div class="row wrap gap">
            <button type="button" class="btn btn-primary" id="btn-orders-import-empty" title="Runs sync command orders: new AboutYou orders → PrestaShop">Import from AboutYou</button>
            <button type="button" class="btn btn-ghost" id="btn-orders-fetch-ps" title="Switch to PrestaShop catalog list">PrestaShop catalog</button>
          </div>
          <div class="ui-help" role="note" aria-label="Empty orders help">
            <div class="ui-help-title">Which button?</div>
            <p class="muted"><strong>Import from AboutYou</strong> creates new PrestaShop orders from AboutYou. <strong>PrestaShop catalog</strong> only switches the table to existing PS orders (no import).</p>
          </div>
        </div>
        <div class="card mb">
          <h2 style="margin:0 0 8px">Orders</h2>
          <p class="muted orders-help" style="margin:0"><strong>Import from AboutYou</strong> runs <code class="mono">orders</code> (AboutYou → PrestaShop, all new). <strong>Push status to AboutYou</strong> runs <code class="mono">order-status</code> for modified PrestaShop orders, or only the PS order IDs you select.</p>
          <div class="row mt wrap gap orders-toolbar">
            <div class="segmented" role="group" aria-label="Order list source" title="Mappings = sync state. Catalog = PrestaShop order IDs.">
              <button type="button" class="seg-btn active" id="orders-src-mapping" data-orders-source="mapping" title="Orders tracked by sync (linked / pending / error)">AY ↔ PS mappings</button>
              <button type="button" class="seg-btn" id="orders-src-ps" data-orders-source="prestashop" title="Paged list from PrestaShop webservice">PrestaShop catalog</button>
            </div>
            <select id="orders-filter-sync" class="input-inline" title="Client-side filter on sync column for rows on this page">
              <option value="all">All sync statuses</option>
              <option value="synced">Linked to AY</option>
              <option value="not_mapped">Not linked</option>
              <option value="pending">Pending</option>
              <option value="error">Error</option>
            </select>
            <button type="button" class="btn btn-sm" id="btn-orders-refresh" title="Reload order list from server">Refresh</button>
            <button type="button" class="btn btn-sm btn-primary" id="btn-orders-import" title="Runs sync command orders: new AboutYou orders → PrestaShop">Import from AboutYou</button>
            <button type="button" class="btn btn-sm" id="btn-orders-push-all" title="Runs order-status for all modified PrestaShop orders">Push status to AY (all)</button>
            <button type="button" class="btn btn-sm" id="btn-orders-push-selected" title="Runs order-status for selected PrestaShop order IDs only">Push status to AY (selected)</button>
          </div>
          <div class="ui-help" role="note" aria-label="Orders table help">
            <div class="ui-help-title">Table &amp; Auto column</div>
            <ul>
              <li><strong>Sync</strong> — mapping pipeline state (linked, pending, error, …), not the PrestaShop order state column.</li>
              <li><strong>Auto</strong> — stored in this browser only; optional hint for which AY orders you want included in local auto-runs (does not change server mapping by itself).</li>
              <li><strong>Checkboxes</strong> — select PrestaShop order IDs for “Push status to AY (selected)”.</li>
            </ul>
          </div>
        </div>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th><input type="checkbox" id="orders-check-all" aria-label="Select all orders" title="Select or clear all rows on this page"></th><th>PS Order</th><th>AY Order</th><th>Customer</th><th>Total</th><th>PS state</th><th>Sync</th><th>Date</th><th>Auto</th></tr></thead>
            <tbody id="orders-tbody"></tbody>
          </table>
        </div>
        <div class="row mt">
          <button type="button" class="btn btn-sm" id="orders-prev" title="Previous page">Previous</button>
          <span class="muted" id="orders-page-label"></span>
          <button type="button" class="btn btn-sm" id="orders-next" title="Next page">Next</button>
        </div>
      </section>

      <section id="tab-webhooks" class="tab-panel" role="tabpanel" hidden>
        <div class="card mb">
          <h2 style="margin:0 0 8px">Webhook endpoint</h2>
          <div class="ui-help" role="note" aria-label="Webhook help">
            <div class="ui-help-title">Production receiver</div>
            <p class="muted">Register this URL in ABOUT YOU or your relay. Real HTTP deliveries are written to the server webhook log. The listener toggle below only adds optional browser-side simulation for testing.</p>
          </div>
          <div class="row">
            <code id="webhook-url" class="mono" style="flex:1;word-break:break-all" title="Example webhook URL for this UI"></code>
            <button type="button" class="btn btn-sm" id="btn-webhook-copy" title="Copy URL to clipboard">Copy URL</button>
          </div>
          <div class="row mt">
            <label class="row" style="gap:10px;cursor:pointer" title="When on, the dashboard can inject sample webhook events for testing">
              <span class="switch"><input type="checkbox" id="webhook-enabled"><span class="slider"></span></span>
              <span>Enable webhook listener (simulated events)</span>
            </label>
          </div>
        </div>
        <div class="card mb">
          <h2>Supported events</h2>
          <p class="mono muted" style="margin:0">order.created · order.updated · product.updated · stock.updated</p>
          <div class="ui-help" role="note" aria-label="Event names help">
            <div class="ui-help-title">Event names</div>
            <p class="muted">These labels match what the UI simulator emits. Your PrestaShop module may use different paths or payloads — align names when integrating.</p>
          </div>
        </div>
        <div class="card">
          <h2>Event log</h2>
          <div class="ui-help" role="note" aria-label="Webhook log help">
            <div class="ui-help-title">Event log</div>
            <p class="muted">This panel now tails persisted server webhook deliveries from <code class="mono">webhook.php</code>. Sync execution details still appear in <strong>Logs</strong>.</p>
          </div>
          <div class="webhook-log" id="webhook-event-log"></div>
        </div>
      </section>

      <section id="tab-settings" class="tab-panel" role="tabpanel" hidden>
        <div class="card">
          <h2>Connection &amp; sync</h2>
          <p class="muted">Credentials are read from server <code class="mono">.env</code>. Fields below are saved in <strong>this browser</strong> for labels and intervals.</p>
          <div class="ui-help" role="note" aria-label="Settings storage help">
            <div class="ui-help-title">Server vs browser</div>
            <p class="muted">Real PrestaShop calls use <code class="mono">PS_BASE_URL</code> and <code class="mono">PS_API_KEY</code> from the server environment. Store URL / key fields here are reminders only unless your deployment reads them elsewhere.</p>
          </div>
          <div class="field"><label for="set-store-url">PrestaShop store URL (display)</label><input id="set-store-url" type="url" placeholder="https://shop.example.com" title="Not sent to server by this UI; for your own reference"></div>
          <div class="field"><label for="set-api-key">API key (display / not sent to server)</label><input id="set-api-key" type="password" autocomplete="off" placeholder="Not stored on server" title="Never uploaded by this dashboard"></div>
          <div class="field"><label for="set-api-secret">API secret (local only)</label><input id="set-api-secret" type="password" autocomplete="off" title="Saved in localStorage with other settings"></div>
          <div class="field"><label for="set-interval">Sync interval</label>
            <select id="set-interval" title="Delay between auto-sync runs while this tab is open">
              <option value="5">5 minutes</option>
              <option value="15">15 minutes</option>
              <option value="30">30 minutes</option>
              <option value="60">60 minutes</option>
            </select>
          </div>
          <div class="field"><label for="set-auto-command">Command for auto-sync</label>
            <select id="set-auto-command" title="Server sync command executed on each auto-sync tick">
              <option value="stock">stock (price + quantity)</option>
              <option value="orders">orders (import from AboutYou)</option>
              <option value="products:inc">products:inc (incremental)</option>
              <option value="order-status">order-status (push PS → AY)</option>
              <option value="all">all (full run — heavy)</option>
            </select>
          </div>
          <div class="ui-help" role="note" aria-label="Auto-sync command help">
            <div class="ui-help-title">Commands cheat sheet</div>
            <ul>
              <li><code class="mono">stock</code> — push price/qty for mapped products (AboutYou direction per sync code).</li>
              <li><code class="mono">orders</code> — import new AboutYou orders into PrestaShop.</li>
              <li><code class="mono">products:inc</code> — incremental product / mapping sync (good for schedules).</li>
              <li><code class="mono">order-status</code> — push PS order state changes toward AboutYou.</li>
              <li><code class="mono">all</code> — stock + orders + order-status; does not include full <code class="mono">products</code> mapping pass.</li>
            </ul>
          </div>
          <div class="field row">
            <label class="row" style="gap:10px;cursor:pointer" title="Runs the chosen command on the interval while this page stays open">
              <span class="switch"><input type="checkbox" id="set-auto-sync"><span class="slider"></span></span>
              <span>Auto-sync in this browser (uses server commands)</span>
            </label>
          </div>
          <div class="field"><label for="set-webhook-secret">Webhook secret (local)</label><input id="set-webhook-secret" type="text" autocomplete="off" title="Optional local label for webhook testing; not the server .env secret by itself"></div>
          <div class="row mt">
            <button type="button" class="btn btn-primary" id="btn-settings-save" title="Persist settings fields to localStorage">Save settings</button>
            <button type="button" class="btn" id="btn-settings-test" title="Calls server with PS_BASE_URL / PS_API_KEY from .env">Test connection</button>
            <span id="settings-test-result" class="muted"></span>
          </div>
          <div class="ui-help" role="note" aria-label="Test and dry run help">
            <div class="ui-help-title">TEST_MODE &amp; DRY_RUN</div>
            <p class="muted">Toggles ask the server to flip environment flags (when implemented). Use to validate flows without writing production data — confirm behavior in server logs after clicking.</p>
          </div>
          <div class="row mt">
            <button type="button" class="btn btn-sm" id="btn-toggle-test" title="Request server to toggle test mode in .env">Toggle TEST_MODE (.env)</button>
            <button type="button" class="btn btn-sm" id="btn-toggle-dry" title="Request server to toggle dry run in .env">Toggle DRY_RUN (.env)</button>
          </div>
        </div>
      </section>

      <section id="tab-logs" class="tab-panel" role="tabpanel" hidden>
        <div class="ui-help mb" role="note" aria-label="Logs help">
          <div class="ui-help-title">Server logs</div>
          <p class="muted">Tail of the sync log file from the server (truncated in the API). Level filter applies to loaded lines. Export downloads the currently loaded rows as CSV.</p>
        </div>
        <div class="card mb row spread">
          <div class="row">
            <label class="muted" for="logs-filter">Level</label>
            <select id="logs-filter" title="Show lines at or above this severity where parseable">
              <option value="all">All</option>
              <option value="debug">Debug</option>
              <option value="info">Info</option>
              <option value="notice">Notice</option>
              <option value="warning">Warning</option>
              <option value="error">Error</option>
              <option value="critical">Critical</option>
            </select>
          </div>
          <button type="button" class="btn btn-sm" id="btn-logs-export" title="Download visible log rows as CSV">Export CSV</button>
        </div>
        <div class="table-wrap" id="logs-scroll">
          <table class="data">
            <thead><tr><th>Timestamp</th><th>Level</th><th>Message</th></tr></thead>
            <tbody id="logs-tbody"></tbody>
          </table>
        </div>
      </section>

      <section id="tab-setup" class="tab-panel" role="tabpanel" hidden>
        <div class="card">
          <div class="wizard-steps" id="wizard-steps">
            <span class="step-dot active" data-step="1">1. Store</span>
            <span class="step-dot" data-step="2">2. Test</span>
            <span class="step-dot" data-step="3">3. Webhook</span>
            <span class="step-dot" data-step="4">4. First sync</span>
          </div>
          <div id="wizard-1" class="wizard-panel">
            <h2>Configure PrestaShop</h2>
            <p class="muted">Set <code class="mono">PS_BASE_URL</code> and <code class="mono">PS_API_KEY</code> in the server <code class="mono">.env</code>, then continue.</p>
            <p class="muted" id="setup-env-hint"></p>
          </div>
          <div id="wizard-2" class="wizard-panel" hidden>
            <h2>Test connection</h2>
            <p class="muted">Verifies the webservice using server environment variables.</p>
            <button type="button" class="btn btn-primary" id="wizard-test-btn">Run test</button>
            <p id="wizard-test-msg" class="mt muted"></p>
          </div>
          <div id="wizard-3" class="wizard-panel" hidden>
            <h2>Webhook in PrestaShop</h2>
            <p class="muted">Register this URL in your PrestaShop module or reverse proxy (example URL for local testing):</p>
            <code id="wizard-webhook-url" class="mono" style="display:block;padding:12px;background:var(--surface-2);border-radius:8px;word-break:break-all"></code>
          </div>
          <div id="wizard-4" class="wizard-panel" hidden>
            <h2>First sync</h2>
            <p class="muted">Runs server command <strong>all</strong> (may take several minutes).</p>
            <div class="ui-help" role="note" aria-label="First sync scope">
              <div class="ui-help-title">After this step</div>
              <p class="muted">Schedule or run <code class="mono">products</code> / <code class="mono">products:inc</code> separately if you need AboutYou ↔ product mappings; <code class="mono">all</code> does not replace that pass.</p>
            </div>
            <button type="button" class="btn btn-primary" id="wizard-sync-btn" title="Runs sync command all on the server">Run first sync</button>
            <p id="wizard-sync-msg" class="mt muted"></p>
          </div>
          <div class="row mt">
            <button type="button" class="btn" id="wizard-back">Back</button>
            <button type="button" class="btn btn-primary" id="wizard-next">Next</button>
          </div>
        </div>
      </section>
        </main>
      </div>
    </div>
  </div>

  <div id="toast-root" aria-live="polite"></div>

  <script>
    window.__BOOTSTRAP__ = <?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  </script>
  <script defer src="dashboard.js?v=20260418b"></script>
</body>
</html>
