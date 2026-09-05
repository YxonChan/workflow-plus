#!/bin/bash
# Install/enable N video-job workers on Baota/systemd hosts.
# Usage:
#   bash setup_video_job_workers.sh [count=10] [per_user=2] [max_global=20]
#
# 关键：video worker 同步阻塞上游，单进程同时只能真正跑 1 个 job。
# “每用户 2 路”需要 count >= 期望全站同时 running 数（建议 5 用户 × 2 = 10）。
set -euo pipefail

COUNT="${1:-10}"
PER_USER="${2:-2}"
MAX_GLOBAL="${3:-20}"
COUNT="$(echo "$COUNT" | tr -cd '0-9')"
PER_USER="$(echo "$PER_USER" | tr -cd '0-9')"
MAX_GLOBAL="$(echo "$MAX_GLOBAL" | tr -cd '0-9')"
if [ -z "$COUNT" ] || [ "$COUNT" -lt 1 ]; then COUNT=10; fi
if [ -z "$PER_USER" ] || [ "$PER_USER" -lt 1 ]; then PER_USER=2; fi
if [ -z "$MAX_GLOBAL" ]; then MAX_GLOBAL=20; fi

SITE_CURRENT=/www/wwwroot/ai-workflow/current
UNIT=/etc/systemd/system/ai-workflow-video-job@.service

cat >"$UNIT" <<EOF
[Unit]
Description=AI Workflow video-job worker %i
After=network.target mysqld.service redis.service

[Service]
Type=simple
User=www
Group=www
WorkingDirectory=${SITE_CURRENT}
ExecStart=/www/server/php/82/bin/php think video-job:worker --sleep=2 --cooldown=3 --per-user-concurrency=${PER_USER} --max-global-concurrency=${MAX_GLOBAL} --stale-minutes=20
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
# 旧模板实例（单实例）关掉，避免和多实例抢任务且仍用旧参数
systemctl disable --now 'ai-workflow-worker@video-job:worker' 2>/dev/null || true

i=1
while [ "$i" -le "$COUNT" ]; do
  systemctl enable --now "ai-workflow-video-job@${i}"
  i=$((i + 1))
done

# Disable extras if reducing count
i=$((COUNT + 1))
while [ "$i" -le 32 ]; do
  systemctl disable --now "ai-workflow-video-job@${i}" 2>/dev/null || true
  i=$((i + 1))
done

sleep 1
ps -ef | grep 'video-job:worker' | grep -v grep || true
# 注意：bash 的 {1..$COUNT} 不会展开，必须用 seq
ACTIVE_OK=0
i=1
while [ "$i" -le "$COUNT" ]; do
  if systemctl is-active --quiet "ai-workflow-video-job@${i}"; then
    ACTIVE_OK=$((ACTIVE_OK + 1))
    echo "ai-workflow-video-job@${i}: active"
  else
    echo "ai-workflow-video-job@${i}: $(systemctl is-active "ai-workflow-video-job@${i}" 2>/dev/null || echo inactive)"
  fi
  i=$((i + 1))
done
echo "video_job_workers=${COUNT} active=${ACTIVE_OK} per_user=${PER_USER} max_global=${MAX_GLOBAL}"
