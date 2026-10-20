# PrestaShop ↔ AboutYou Sync

Lightweight PHP middleware that keeps a PrestaShop shop and AboutYou Seller Center in sync.

- **PrestaShop** is the master for catalogue, stock, prices, and status
- **AboutYou** receives product pushes and sends orders back into PrestaShop
- Plain PHP CLI + optional admin UI — no heavy framework, easy on VPS or shared hosting

Built by [Obada Al Tarazi](https://github.com/obadatarazi)

---

## Flow

```text
PrestaShop ──products / stock / prices──► AboutYou Seller Center
PrestaShop ◄── new orders / status ────── AboutYou Seller Center
```

CLI commands (also available as Composer scripts):

| Command | Purpose |
| --- | --- |
| `php bin/sync.php products` | Push / update products |
| `php bin/sync.php stock` | Sync stock levels |
| `php bin/sync.php orders` | Pull AboutYou orders into PrestaShop |
| `php bin/sync.php order-status` | Push order status updates |
| `php bin/sync.php all` | Run the full cycle |
| `php bin/sync.php status` | Health / last-run info |

## Requirements

- PHP 8.1+ recommended (7.4+ minimum)
- Composer 2+
- PrestaShop 1.7.6+ with Webservice enabled
- AboutYou Seller Center API credentials
- Cron access on the host

## Quick start

```bash
git clone https://github.com/obadatarazi/ps-aboutyou-sync.git
cd ps-aboutyou-sync
bash scripts/install.sh
cp .env.example .env
# edit .env — never commit this file
php bin/sync.php status
bash scripts/health-check.sh
```

Minimum `.env` values:

```dotenv
PS_BASE_URL=https://yourstore.com
PS_API_KEY=YOUR_PRESTASHOP_WEBSERVICE_API_KEY
AY_BASE_URL=https://partner.aboutyou.com/api/v1
AY_API_KEY=YOUR_ABOUTYOU_API_KEY
AY_MERCHANT_ID=YOUR_MERCHANT_ID
AY_BRAND_ID=YOUR_BRAND_ID
```

Optional admin UI:

```bash
composer ui:serve
# open http://localhost:8080 — set UI_AUTH_USER / UI_AUTH_PASSWORD in .env
```

Cron example: see `scripts/cron.example` and `cron/install-cron.sh`.

## Tests

```bash
composer test
```

## Security

- Keep secrets only in `.env` (listed in `.gitignore`)
- If this repo ever contained a real `.env`, rotate PrestaShop + AboutYou keys immediately
- Change the dashboard password before exposing the UI publicly

## Stack

PHP · Guzzle · Monolog · Symfony Console · PHP-DI · PHPUnit
