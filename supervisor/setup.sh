#!/bin/bash
# ShadApp — Supervisor + Cron setup script
# Run this on the server after deployment.
#
# Installs three long-lived pieces:
#   1. queue:work   — without it, queued mail and notifications are written to
#                     the queue and never delivered. Nothing errors; mail just
#                     never arrives.
#   2. reverb:start — the WebSocket server. Without it the apps still work but
#                     nothing updates live until a manual refresh.
#   3. schedule:run — contract/meeting/payment/birthday reminders.
#
# All three fail silently rather than loudly, which is why they are scripted
# rather than left as manual steps.

set -euo pipefail

PROJECT_DIR=$(dirname "$(dirname "$(realpath "$0")")")

# The account that owns the deployment. Supervisor needs a real user; when the
# script is run under sudo, $USER is "root", so prefer the invoking account.
# Override explicitly with:  APP_USER=deploy ./supervisor/setup.sh
APP_USER="${APP_USER:-${SUDO_USER:-$USER}}"

if ! id "$APP_USER" >/dev/null 2>&1; then
  echo "✗ User '$APP_USER' does not exist. Re-run with APP_USER=<name> $0" >&2
  exit 1
fi

echo "Project:  $PROJECT_DIR"
echo "Run as:   $APP_USER"
echo ""

install_program() {
  local name="$1"
  local src="$PROJECT_DIR/supervisor/${name}.conf"
  local dest="/etc/supervisor/conf.d/${name}.conf"

  sudo cp "$src" "$dest"
  sudo sed -i "s|/path/to/artisan|$PROJECT_DIR/artisan|g" "$dest"
  sudo sed -i "s|/path/to/storage|$PROJECT_DIR/storage|g" "$dest"
  sudo sed -i "s|__APP_USER__|$APP_USER|g" "$dest"
}

echo "=== 1. Queue worker ==="
install_program "shadapp-worker"
echo "✓ Installed."

echo ""
echo "=== 2. Reverb (WebSocket server) ==="
install_program "shadapp-reverb"
echo "✓ Installed."

echo ""
echo "=== 3. Applying Supervisor config ==="
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart shadapp-worker:* || sudo supervisorctl start shadapp-worker:*
sudo supervisorctl restart shadapp-reverb || sudo supervisorctl start shadapp-reverb
echo "✓ Both programs running."

echo ""
echo "=== 4. Cron for schedule:run ==="
CRON_JOB="* * * * * cd $PROJECT_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -l 2>/dev/null | grep -v "artisan schedule:run"; echo "$CRON_JOB") | crontab -
echo "✓ Cron entry added."

echo ""
echo "Done. Verify with:  sudo supervisorctl status"
echo ""
echo "Reminder: Reverb listens on REVERB_PORT (default 8080) but browsers will"
echo "connect through your web server on 443. The reverse proxy must forward"
echo "that route with the WebSocket upgrade headers, or connections are"
echo "refused despite Reverb itself running fine. See README.md."
