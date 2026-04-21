# PrestaShop -> AboutYou Production Integration

Complete PHP middleware integration where:
- PrestaShop 1.7.6.1 is the master
- AboutYou Seller Center is the channel/slave
- Products/stock/prices/status flow from PrestaShop to AboutYou
- New orders flow from AboutYou to PrestaShop

## 1) Why this stack

This project uses plain PHP CLI + lightweight PHP admin UI:
- Native fit for PrestaShop environments
- Simple deploy on VPS/shared hosting
- Reuses existing codebase and reduces migration risk
- No framework lock-in, easier ops and cron integration

## 2) Project structure

```text
ps-aboutyou-sync/
  bin/
    sync.php
  config/
    app.php
  cron/
    install-cron.sh
  logs/
    .gitkeep
  public/
    index.php
  scripts/
    install.sh
    health-check.sh
    cron.example
  services/
    README.md
  src/
    AboutYou/
    Config/
    Controllers/
    Logger/
    PrestaShop/
    Services/
    Sync/
  tests/
    SyncTest.php
  .env.example
  composer.json
  README.md
```

## 3) Requirements

- PHP 8.1+ recommended (7.4+ minimum in composer)
- Composer 2+
- PrestaShop 1.7.6.1 with webservice enabled
- AboutYou Seller Center API credentials
- Linux server access for cron

## 4) Installation (step by step)

### Step 1 - Get the code

```bash
git clone <your-repo-url> ps-aboutyou-sync
cd ps-aboutyou-sync
```

### Step 2 - Install dependencies and bootstrap

```bash
bash scripts/install.sh
```

### Step 3 - Create and edit environment

```bash
cp .env.example .env
nano .env
```

Minimum required values:

```dotenv
PS_BASE_URL=https://yourstore.com
PS_API_KEY=YOUR_PRESTASHOP_WEBSERVICE_API_KEY
AY_BASE_URL=https://partner.aboutyou.com/api/v1
AY_API_KEY=YOUR_ABOUTYOU_API_KEY
AY_MERCHANT_ID=YOUR_MERCHANT_ID
AY_BRAND_ID=YOUR_BRAND_ID
```

### Step 4 - Verify installation

```bash
php bin/sync.php status
bash scripts/health-check.sh
```

### Step 5 - Optional admin UI

```bash
composer ui:serve
```

Open `http://localhost:8080`

### Step 6 - Install cron jobs

```bash
bash cron/install-cron.sh /absolute/path/to/ps-aboutyou-sync
```

Or copy lines from `scripts/cron.example` into your crontab.

## 5) Configuration guide

All fields in `.env.example`:

- `APP_ENV`: app environment name
- `APP_TIMEZONE`: timezone used by runtime/logs
- `UI_ENABLED`: allow/deny web admin page

- `PS_BASE_URL`: PrestaShop base URL
- `PS_API_KEY`: PrestaShop webservice key
- `PS_API_OUTPUT`: JSON/XML preference
- `PS_LANGUAGE_ID`: language for localized fields
- `PS_SHOP_ID`: target shop id

- `AY_BASE_URL`: AboutYou API base URL
- `AY_API_KEY`: AboutYou API key
- `AY_MERCHANT_ID`: merchant identifier
- `AY_BRAND_ID`: default brand id for product payloads

- `SYNC_BATCH_SIZE`: push batch size
- `SYNC_INCREMENTAL`: enable incremental sync path
- `SYNC_LAST_SYNC_FILE`: mapping/timestamps file path
- `TEST_MODE`: disables remote writes (safe validation)
- `DRY_RUN`: simulate writes, keep reads

- `QUEUE_DRIVER`, `QUEUE_PATH`, `REDIS_HOST`, `REDIS_PORT`: future queue wiring

- `LOG_LEVEL`, `LOG_PATH`, `LOG_MAX_FILES`: logging configuration

- `NOTIFY_EMAIL_*`: daily report email settings
- `NOTIFY_SLACK_ENABLED`, `NOTIFY_SLACK_WEBHOOK`: instant error alerts

## 6) How to run

### Manual commands

```bash
php bin/sync.php status
php bin/sync.php products:inc
php bin/sync.php products
php bin/sync.php stock
php bin/sync.php orders
php bin/sync.php order-status
php bin/sync.php all
php bin/sync.php report
```

### Composer shortcuts

```bash
composer sync:products
composer sync:stock
composer sync:orders
composer sync:all
```

### Cron examples

```cron
*/10 * * * * /usr/bin/php /path/to/ps-aboutyou-sync/bin/sync.php stock >> /path/to/ps-aboutyou-sync/logs/cron-stock.log 2>&1
*/5 * * * * /usr/bin/php /path/to/ps-aboutyou-sync/bin/sync.php orders >> /path/to/ps-aboutyou-sync/logs/cron-orders.log 2>&1
*/5 * * * * /usr/bin/php /path/to/ps-aboutyou-sync/bin/sync.php order-status >> /path/to/ps-aboutyou-sync/logs/cron-order-status.log 2>&1
*/15 * * * * /usr/bin/php /path/to/ps-aboutyou-sync/bin/sync.php products:inc >> /path/to/ps-aboutyou-sync/logs/cron-products.log 2>&1
0 2 * * * /usr/bin/php /path/to/ps-aboutyou-sync/bin/sync.php products >> /path/to/ps-aboutyou-sync/logs/cron-full.log 2>&1
```

## 7) Testing guide (step by step)

### Test 1 - Connection and config
1. Set `TEST_MODE=true` and `DRY_RUN=true`
2. Run `php bin/sync.php status`
3. Expected: JSON stats output, no fatal errors

### Test 2 - Sync one product safely
1. Keep `DRY_RUN=true`
2. Run `php bin/sync.php products:inc`
3. Expected: fetch counters increment, write operations skipped by safety mode in logs

### Test 3 - Sync orders safely
1. Keep `DRY_RUN=true`
2. Run `php bin/sync.php orders`
3. Expected: AboutYou orders are read; PrestaShop write calls skipped

### Test 4 - Full sync
1. Run `php bin/sync.php all` with `DRY_RUN=true`
2. Review logs and counters
3. Switch to production (`TEST_MODE=false`, `DRY_RUN=false`)
4. Run each command manually once before enabling cron

### Unit tests

```bash
composer test
```

## 8) Debug and troubleshooting

### Logs
- Main log: `logs/sync.log`
- Cron logs: `logs/cron-*.log`
- Mapping/timestamps: `logs/last_sync.json`

### Common errors

- Invalid API key
  - Symptoms: 401/403 errors
  - Fix: verify `PS_API_KEY` or `AY_API_KEY`

- Missing product fields
  - Symptoms: push failures for variants/brand/category
  - Fix: ensure `reference`, price, and brand/category mappings are valid

- Image upload/access failure
  - Symptoms: AboutYou rejects image URLs
  - Fix: confirm PrestaShop image URLs are publicly reachable

- Order import fails on cart/order creation
  - Symptoms: PrestaShop order write errors
  - Fix: ensure full cart-first flow is available for your PrestaShop setup, and test with dry-run first

## 9) Safety mode

Use both for safe rollout:

```dotenv
TEST_MODE=true
DRY_RUN=true
```

- `TEST_MODE=true`: mutating API calls are blocked
- `DRY_RUN=true`: write paths are simulated (no external data mutation)

Recommended rollout:
1. enable both flags
2. validate all command paths and logs
3. disable `DRY_RUN`, keep `TEST_MODE=true`, validate reads
4. disable both and run production sync

## 10) Admin UI (optional)

The admin dashboard is served from `public/index.php` (vanilla HTML/CSS/JS plus `public/dashboard.css` and `public/dashboard.js`). It uses a JSON API at `public/api.php` on the same origin (PHP session + CSRF). You can:

- Run real sync commands (`all`, `stock`, `orders`, `order-status`, etc.) against the same `SyncRunner` as the CLI
- **Product / order lists:** each table supports a **source** toggle — **Mapped only** (data from `MappingStore`, default) or **PrestaShop catalog** (paged IDs from the PrestaShop webservice, `per_page` capped at 50). When the mapping list is empty, use **Load from PrestaShop** to switch source. PrestaShop pages report `has_more` for next-page navigation (total catalog size is not fetched in one call).
- **Push selected:** `POST api.php` action `sync` with `command: "stock"` and `ps_product_ids: [..]` pushes stock/price to AboutYou only for those PS product IDs (mapped SKUs only; max 50 ids). `command: "order-status"` with `ps_order_ids: [..]` pushes status for those PS orders (AboutYou-linked orders only; max 50). Omit the id arrays to run the usual full **stock** or **order-status** job.
- **Orders:** **Import new (AY → PS)** runs `orders`; **Push status** runs `order-status` (all modified PS orders, or selected ids as above).
- Tail logs, export CSV, run a connection test, toggle `TEST_MODE` / `DRY_RUN` in `.env`, and switch **light/dark** theme (stored in `localStorage` as `prestasync_theme`).
- Use optional browser-only features (webhook simulation, other local settings in `localStorage`)

Enable the UI with:

```dotenv
UI_ENABLED=true
```

Set dashboard credentials in production (defaults are only for local convenience):

```dotenv
UI_AUTH_USER=your_admin_user
UI_AUTH_PASSWORD=your_strong_password
```

Serve locally:

```bash
composer ui:serve
```

Then open `http://127.0.0.1:8080/index.php` and sign in.

## 11) Final delivery checklist

- Full project code: included
- README: installation, usage, testing, debug completed
- `.env.example`: updated with safety and UI config
- run commands: included
- cron setup: included
- troubleshooting guide: included
