#!/bin/bash
# Install/enable N asset-image workers on Baota/systemd hosts.
# Usage: bash setup_asset_image_workers.sh [count=2] [max_concurrency=2]
set -euo pipefail

COUNT="${1:-2}"
MAX_CONCURRENCY="${2:-2}"
COUNT="$(echo "$COUNT" | tr -cd '0-9')"
MAX_CONCURRENCY="$(echo "$MAX_CONCURRENCY" | tr -cd '0-9')"
if [ -z "$COUNT" ] || [ "$COUNT" -lt 1 ]; then COUNT=2; fi
if [ -z "$MAX_CONCURRENCY" ] || [ "$MAX_CONCURRENCY" -lt 1 ]; then MAX_CONCURRENCY=2; fi

SITE_CURRENT=/www/wwwroot/ai-workflow/current
UNIT=/etc/systemd/system/ai-workflow-asset-image@.service

cat >"$UNIT" <<EOF
[Unit]
Description=AI Workflow asset-image worker %i
After=network.target mysqld.service redis.service

[Service]
Type=simple
User=www
Group=www
WorkingDirectory=${SITE_CURRENT}
ExecStart=/www/server/php/82/bin/php think asset-image:worker --sleep=1 --cooldown=0 --max-concurrency=${MAX_CONCURRENCY} --stale-minutes=3 --wait-timeout-minutes=20 --poll-interval=3
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl disable --now 'ai-workflow-worker@asset-image:worker' 2>/dev/null || true

i=1
while [ "$i" -le "$COUNT" ]; do
  systemctl enable --now "ai-workflow-asset-image@${i}"
  i=$((i + 1))
done

# Disable extras if reducing count
i=$((COUNT + 1))
while [ "$i" -le 8 ]; do
  systemctl disable --now "ai-workflow-asset-image@${i}" 2>/dev/null || true
  i=$((i + 1))
done

sleep 1
ps -ef | grep 'asset-image:worker' | grep -v grep || true
# 注意：bash 的 {1..$COUNT} 不会展开，必须逐个检查
ACTIVE_OK=0
i=1
while [ "$i" -le "$COUNT" ]; do
  if systemctl is-active --quiet "ai-workflow-asset-image@${i}"; then
    ACTIVE_OK=$((ACTIVE_OK + 1))
    echo "ai-workflow-asset-image@${i}: active"
  else
    echo "ai-workflow-asset-image@${i}: $(systemctl is-active "ai-workflow-asset-image@${i}" 2>/dev/null || echo inactive)"
  fi
  i=$((i + 1))
done
echo "asset_image_workers=${COUNT} active=${ACTIVE_OK} max_concurrency=${MAX_CONCURRENCY}"
