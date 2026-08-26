#!/bin/bash
# ShadApp — Supervisor + Cron setup script
# Run this on the server after deployment.

PROJECT_DIR=$(dirname "$(dirname "$(realpath "$0")")")

echo "=== 1. Setting up Supervisor for queue:work ==="
sudo cp "$PROJECT_DIR/supervisor/shadapp-worker.conf" /etc/supervisor/conf.d/shadapp-worker.conf
sudo sed -i "s|/path/to/artisan|$PROJECT_DIR/artisan|g" /etc/supervisor/conf.d/shadapp-worker.conf
sudo sed -i "s|/path/to/storage|$PROJECT_DIR/storage|g" /etc/supervisor/conf.d/shadapp-worker.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start shadapp-worker:*
echo "✓ Supervisor started."

echo ""

echo "=== 2. Adding cron for schedule:run ==="
CRON_JOB="* * * * * cd $PROJECT_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -l 2>/dev/null | grep -v "artisan schedule:run"; echo "$CRON_JOB") | crontab -
echo "✓ Cron entry added."
echo ""
echo "Done! Both queue worker and scheduler are active."
