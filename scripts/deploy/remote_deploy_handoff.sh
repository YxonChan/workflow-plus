#!/bin/bash
set -euo pipefail

REL="${1:?release id required}"
SITE=/www/wwwroot/ai-workflow
OLD=$(readlink -f "$SITE/current")
NEW="$SITE/releases/$REL"

echo "OLD=$OLD"
echo "NEW=$NEW"

if [ "$OLD" = "$NEW" ]; then
  echo "ERROR: refusing to redeploy into the currently active release directory: $NEW"
  echo "Pick a new release id, or switch current away first."
  exit 1
fi

STAGE="$SITE/releases/.incoming-$REL-$$"
rm -rf "$STAGE" "$NEW"
mkdir -p "$STAGE"
tar -xzf "/tmp/ai-workflow-$REL.tar.gz" -C "$STAGE"
cp -a "$OLD/.env" "$STAGE/.env"
if [ -d "$OLD/public/storage" ]; then
  mkdir -p "$STAGE/public/storage"
  cp -a "$OLD/public/storage/." "$STAGE/public/storage/"
fi
mkdir -p "$STAGE/runtime/log" "$STAGE/runtime/cache" "$STAGE/runtime/temp" "$STAGE/runtime/session"
chown -R www:www "$STAGE"
chmod -R ug+rwX "$STAGE/runtime" "$STAGE/public/storage" || true
mv "$STAGE" "$NEW"

ln -sfn "$NEW" "$SITE/current"
echo "CURRENT=$(readlink -f "$SITE/current")"
test -f "$SITE/current/app/support/ScriptCreationService.php"
grep -n "parseDeliverablePackage\|handoff_content\|previous_handoff" "$SITE/current/app/support/ScriptCreationService.php" | head -10
ls "$SITE/current/public/admin/static"/ScriptCreationView-* 2>/dev/null | head || ls "$SITE/current/public/admin/assets"/ScriptCreationView-* 2>/dev/null | head

cd "$SITE/current"
/www/server/php/82/bin/php <<'PHP'
<?php
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$uids = think\facade\Db::name("script_ai_configs")->distinct(true)->column("user_id");
if (!$uids) {
    $uids = think\facade\Db::name("script_ai_projects")->distinct(true)->column("user_id");
}
foreach ($uids as $uid) {
    app\support\ScriptCreationService::configs((int) $uid);
    echo "synced_user={$uid}\n";
}
$cols = think\facade\Db::query("SHOW COLUMNS FROM script_ai_steps LIKE 'handoff_content'");
echo "handoff_column=" . (count($cols) > 0 ? "yes" : "no") . "\n";
$n = think\facade\Db::name("script_ai_configs")->whereLike("task_prompt", "%previous_handoff%")->count();
echo "configs_with_previous_handoff={$n}\n";
PHP

if [ -f /www/server/php/82/var/run/php-fpm.pid ]; then
  kill -USR2 "$(cat /www/server/php/82/var/run/php-fpm.pid)"
  echo php_reloaded
fi

systemctl restart \
  'ai-workflow-worker@workflow:worker' \
  'ai-workflow-worker@episode-workflow:worker' \
  'ai-workflow-worker@episode-node:worker' \
  'ai-workflow-worker@video-job:worker' \
  'ai-workflow-worker@quick-create:worker' \
  'ai-workflow-worker@quick-create:face-worker' \
  'ai-workflow-worker@script-creation:worker' \
  'ai-workflow-worker@agent-task:worker'

# 旧单实例图片 Worker 会绕过多实例并发配置，必须保持停用。
systemctl disable --now 'ai-workflow-worker@asset-image:worker' 2>/dev/null || true

sleep 2
systemctl is-active 'ai-workflow-worker@script-creation:worker'
pid=$(ps -eo pid,cmd | grep 'script-creation:worker' | grep -v grep | awk '{print $1}' | head -1)
echo "script_pid=$pid"
if [ -n "$pid" ]; then
  readlink "/proc/$pid/cwd"
fi
curl -k -s -o /dev/null -w 'admin=%{http_code}\n' https://ai.taptalk.live/admin/
echo DEPLOY_OK
