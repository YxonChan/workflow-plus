#!/bin/bash
set -euo pipefail

echo "=== SCAN LAST 100MB FOR SERIES DELETE ==="
tail -c 100M /www/server/data/mysql-bin.000007 | strings -a > /tmp/bin_strings.txt
grep -E "DELETE FROM \`series\`|series\.delete|target_id|target_name_snapshot|154\.16\.27\.165" /tmp/bin_strings.txt | tail -200

echo "=== CAT TITLE HITS ==="
grep -n "猫" /tmp/bin_strings.txt | tail -40 || true

echo "=== CURRENT DB ==="
mysql -uai_workflow -p'ThgWKWDQyjWr_hdzFG3twHWkdYG_x9rb' ai_workflow <<'SQL'
SELECT id,user_id,title,create_time FROM series ORDER BY id;
SHOW COLUMNS FROM admin_operation_logs;
SELECT * FROM admin_operation_logs WHERE action LIKE '%series%' ORDER BY id DESC LIMIT 30;
SQL

echo "=== WHO IS 154.16.27.165 ==="
# No whois required; just note IP for user
echo "delete_ip=154.16.27.165"

echo "=== USERS ==="
mysql -uai_workflow -p'ThgWKWDQyjWr_hdzFG3twHWkdYG_x9rb' ai_workflow -e "SELECT id,username,display_name,role FROM users;"
