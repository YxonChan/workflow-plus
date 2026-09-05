#!/bin/bash
set -euo pipefail

echo "=== MYSQL ROOT VIA BT ==="
ROOT_PASS=""
if command -v btpython >/dev/null 2>&1; then
  ROOT_PASS=$(btpython - <<'PY' 2>/dev/null || true
import public
print(public.M("config").where("id=?", (1,)).getField("mysql_root") or "")
PY
)
fi
if [ -z "$ROOT_PASS" ] && [ -f /www/server/panel/data/default.db ]; then
  ROOT_PASS=$(sqlite3 /www/server/panel/data/default.db "select mysql_root from config limit 1;" 2>/dev/null || true)
fi

MYSQL=(mysql -uroot)
if [ -n "$ROOT_PASS" ]; then
  MYSQL=(mysql -uroot -p"$ROOT_PASS")
fi

echo "root_pass_len=${#ROOT_PASS}"
"${MYSQL[@]}" -e "SELECT 1 AS ok; SHOW BINARY LOGS; SHOW MASTER STATUS\G" 2>&1 | head -40

echo "=== TABLE STATE ==="
"${MYSQL[@]}" ai_workflow -e "
SELECT 'series' t, COUNT(*) c, IFNULL(MAX(id),0) m FROM series
UNION ALL SELECT 'assets', COUNT(*), IFNULL(MAX(id),0) FROM assets
UNION ALL SELECT 'episodes', COUNT(*), IFNULL(MAX(id),0) FROM episodes
UNION ALL SELECT 'asset_images', COUNT(*), IFNULL(MAX(id),0) FROM asset_images
UNION ALL SELECT 'asset_image_jobs', COUNT(*), IFNULL(MAX(id),0) FROM asset_image_jobs
UNION ALL SELECT 'workflow_runs', COUNT(*), IFNULL(MAX(id),0) FROM workflow_runs
UNION ALL SELECT 'ai_request_logs', COUNT(*), IFNULL(MAX(id),0) FROM ai_request_logs;
SELECT id,title,user_id,create_time FROM series ORDER BY id;
SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES
 WHERE TABLE_SCHEMA='ai_workflow'
   AND TABLE_NAME IN ('series','assets','episodes','asset_images','asset_image_jobs','workflow_runs');
"

BIN=/www/server/mysql/bin/mysqlbinlog
FILE=/www/server/data/mysql-bin.000007
SIZE=$(stat -c%s "$FILE")
# Valid event positions are needed; pull near end via SHOW BINLOG EVENTS
echo "=== FIND HIGH POSITIONS ==="
"${MYSQL[@]}" -N -e "SHOW BINLOG EVENTS IN 'mysql-bin.000007' FROM $((SIZE>5000000 ? SIZE-5000000 : 4)) LIMIT 5;" 2>&1 | head -20 || true

# Decode last ~5MB using approximate start; if fails, fall back to first events then scan with grep on strings
START=$((SIZE>10000000 ? SIZE-10000000 : 4))
echo "SIZE=$SIZE START=$START"
$BIN --no-defaults --base64-output=DECODE-ROWS -v --start-position=4 "$FILE" 2>/tmp/bin_head.txt | true
# Better: use mysql client to dump recent event info
"${MYSQL[@]}" -e "SHOW BINLOG EVENTS IN 'mysql-bin.000007' LIMIT 3;" 2>&1 | head -20

echo "=== STRINGS SCAN for DELETE/TRUNCATE near end ==="
# raw scan last 20MB for SQL text fragments (statement mode fragments in MIXED)
tail -c 20M "$FILE" | strings -a | grep -E "DELETE FROM|TRUNCATE|DROP TABLE|DELETE FROM series|ai_workflow" | tail -80

echo "=== PANEL TASK LOG around loss ==="
grep -E "mysql|database|ai_workflow|import|restore|backup|sync" /www/server/panel/logs/task.log 2>/dev/null | tail -50 || true

echo "=== APP DELETE SERIES CODE PATHS CHECK via recent php access? ==="
ls -lt /www/wwwlogs/ai* /www/wwwroot/ai-workflow/current/runtime/log 2>/dev/null | head
find /www/wwwroot/ai-workflow/current/runtime/log -type f -mtime -1 2>/dev/null | head
