#!/bin/bash
# =============================================================
#  cron/install-cron.sh
#  Sets up all sync cron jobs for the PS ↔ AboutYou integration.
#  Run once as the web server user (www-data) or the PHP process user.
#  Usage: bash cron/install-cron.sh /path/to/ps-aboutyou-sync
# =============================================================

SCRIPT_DIR="${1:-$(pwd)}"
PHP_BIN="${PHP_BIN:-$(which php)}"
LOG_DIR="${SCRIPT_DIR}/logs"

mkdir -p "$LOG_DIR"

echo "Installing cron jobs for: $SCRIPT_DIR"
echo "PHP binary: $PHP_BIN"

# Build the cron block
CRON_BLOCK="
# === PrestaShop ↔ AboutYou Sync Jobs ===

# Stock + price sync: every 10 minutes
*/10 * * * * $PHP_BIN $SCRIPT_DIR/bin/sync.php stock >> $LOG_DIR/cron-stock.log 2>&1

# Order import (AY → PS): every 5 minutes
*/5 * * * * $PHP_BIN $SCRIPT_DIR/bin/sync.php orders >> $LOG_DIR/cron-orders.log 2>&1

# Order status push (PS → AY): every 5 minutes
*/5 * * * * $PHP_BIN $SCRIPT_DIR/bin/sync.php order-status >> $LOG_DIR/cron-order-status.log 2>&1

# Incremental product sync: every 15 minutes
*/15 * * * * $PHP_BIN $SCRIPT_DIR/bin/sync.php products:inc >> $LOG_DIR/cron-products-inc.log 2>&1

# Full product sync (all products): daily at 02:00
0 2 * * * $PHP_BIN $SCRIPT_DIR/bin/sync.php products >> $LOG_DIR/cron-products-full.log 2>&1

# Daily report email: every day at 08:00
0 8 * * * $PHP_BIN $SCRIPT_DIR/bin/sync.php report >> $LOG_DIR/cron-report.log 2>&1

# === End PS ↔ AboutYou Sync Jobs ===
"

# Append to crontab (avoiding duplicates)
EXISTING=$(crontab -l 2>/dev/null || true)

if echo "$EXISTING" | grep -q "ps-aboutyou-sync\|AboutYou Sync Jobs"; then
  echo "⚠️  Cron jobs already installed. Remove old entries first if you want to reinstall."
  exit 0
fi

(echo "$EXISTING"; echo "$CRON_BLOCK") | crontab -

echo "✅ Cron jobs installed successfully!"
echo ""
echo "Verify with: crontab -l"
