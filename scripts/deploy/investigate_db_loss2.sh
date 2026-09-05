#!/bin/bash
set -euo pipefail

echo "=== BT DATA FILES ==="
ls -la /www/server/panel/data/ | head -50

ROOT_PASS=""
if [ -f /www/server/panel/data/default.db ]; then
  echo "=== SQLITE TABLES ==="
  sqlite3 /www/server/panel/data/default.db '.tables' | tr ' ' '\n' | head -40 || true
  sqlite3 /www/server/panel/data/default.db "PRAGMA table_info(config);" || true
  ROOT_PASS=$(sqlite3 /www/server/panel/data/default.db "select mysql_root from config where id=1;" 2>/dev/null || true)
fi

if [ -z "$ROOT_PASS" ]; then
  ROOT_PASS=$(btpython -c 'import public; print(public.M("config").where("id=?",(1,)).getField("mysql_root") or "")' 2>/dev/null || true)
fi

echo "root_pass_len=${#ROOT_PASS}"

if [ -z "$ROOT_PASS" ]; then
  echo "NO_ROOT_PASS"
  # fallback: strings scan binlog for DELETE
  echo "=== RAW BINLOG STRINGS (last 30MB) ==="
  tail -c 30M /www/server/data/mysql-bin.000007 | strings -a | grep -E "DELETE FROM|TRUNCATE|DROP TABLE|series" | tail -100
  exit 0
fi

MYSQL=(mysql -uroot -p"$ROOT_PASS")
"${MYSQL[@]}" -e "SELECT 1; SHOW BINARY LOGS; SHOW MASTER STATUS\G"

echo "=== CURRENT COUNTS ==="
"${MYSQL[@]}" ai_workflow -e "
SELECT 'series' t, COUNT(*) c, IFNULL(MAX(id),0) m FROM series
UNION ALL SELECT 'assets', COUNT(*), IFNULL(MAX(id),0) FROM assets
UNION ALL SELECT 'episodes', COUNT(*), IFNULL(MAX(id),0) FROM episodes
UNION ALL SELECT 'asset_image_jobs', COUNT(*), IFNULL(MAX(id),0) FROM asset_image_jobs
UNION ALL SELECT 'workflow_runs', COUNT(*), IFNULL(MAX(id),0) FROM workflow_runs;
SELECT id,title,user_id,create_time FROM series ORDER BY id;
"

SIZE=$(stat -c%s /www/server/data/mysql-bin.000007)
START=$((SIZE>20000000 ? SIZE-20000000 : 4))
echo "SIZE=$SIZE trying events near end via SQL"
"${MYSQL[@]}" -e "SHOW BINLOG EVENTS IN 'mysql-bin.000007' FROM ${START} LIMIT 10;" 2>&1 | head -30 || true

# Get a valid recent position
POS=$("${MYSQL[@]}" -N -e "SHOW MASTER STATUS" | awk '{print $2}')
echo "MASTER_POS=$POS"
LOOK=$((POS>5000000 ? POS-5000000 : 4))
"${MYSQL[@]}" -N -e "SHOW BINLOG EVENTS IN 'mysql-bin.000007' FROM ${LOOK} LIMIT 30;" 2>&1 | head -40

echo "=== DECODE LAST VALID CHUNK ==="
# find a nearby event Pos
NEAR=$("${MYSQL[@]}" -N -e "SHOW BINLOG EVENTS IN 'mysql-bin.000007' FROM ${LOOK} LIMIT 1;" | awk '{print $2}')
echo "NEAR=$NEAR"
if [ -n "$NEAR" ]; then
  /www/server/mysql/bin/mysqlbinlog --no-defaults --base64-output=DECODE-ROWS -v --start-position="$NEAR" /www/server/data/mysql-bin.000007 2>/tmp/bin_near.txt | true
  # mysqlbinlog writes to stdout; redirect properly
  /www/server/mysql/bin/mysqlbinlog --no-defaults --base64-output=DECODE-ROWS -v --start-position="$NEAR" /www/server/data/mysql-bin.000007 > /tmp/bin_near.txt 2>/tmp/bin_near.err || true
  wc -l /tmp/bin_near.txt /tmp/bin_near.err
  grep -n "DELETE FROM\|TRUNCATE\|DROP TABLE\|Query.*series\|### DELETE" /tmp/bin_near.txt | tail -80
  grep -E "^#[0-9]{6}" /tmp/bin_near.txt | head -5
  grep -E "^#[0-9]{6}" /tmp/bin_near.txt | tail -5
fi

echo "=== ADMIN OP LOGS FOR SERIES DELETE ==="
"${MYSQL[@]}" ai_workflow -e "SHOW TABLES LIKE '%operation%'; SHOW TABLES LIKE '%admin%';"
"${MYSQL[@]}" ai_workflow -e "SELECT id,action,target_id,target_name_snapshot,operator_user_id,create_time FROM admin_operation_logs WHERE action LIKE '%series%' ORDER BY id DESC LIMIT 30;" 2>&1 | head -60 || true

echo "=== PANEL TASK HINTS ==="
grep -E "mysql|database|ai_workflow|import|restore|备份|恢复|导入" /www/server/panel/logs/task.log 2>/dev/null | tail -40 || true
