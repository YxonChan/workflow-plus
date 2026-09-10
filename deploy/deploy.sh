#!/usr/bin/env bash
# 生产一键更新（宝塔）：Git 快进 + Docker 多阶段构建 + Compose 启动全部服务
# 用法：
#   bash /www/wwwroot/ai-workflow/deploy/deploy.sh
# 可选：
#   AI_WORKFLOW_BRANCH=main bash deploy/deploy.sh
#   SKIP_FRONTEND=1 bash deploy/deploy.sh
#   SKIP_COMPOSER=1 bash deploy/deploy.sh
#   FORCE_COMPOSER=1 bash deploy/deploy.sh
set -Eeuo pipefail
umask 022

APP_ROOT="${AI_WORKFLOW_APP_ROOT:-/www/wwwroot/ai-workflow}"
BRANCH="${AI_WORKFLOW_BRANCH:-main}"
REPO_URL="${AI_WORKFLOW_REPO_URL:-https://github.com/YxonChan/workflow.git}"
PHP_BIN="${AI_WORKFLOW_PHP_BIN:-/www/server/php/82/bin/php}"
SITE_URL="${AI_WORKFLOW_SITE_URL:-https://ai.taptalk.live}"
USE_DOCKER="${AI_WORKFLOW_USE_DOCKER:-1}"
EXTERNAL_DB="${AI_WORKFLOW_EXTERNAL_DB:-0}"
COMPOSE_FILE="${AI_WORKFLOW_COMPOSE_FILE:-$APP_ROOT/docker-compose.yml}"
COMPOSE_EXTERNAL_FILE="${AI_WORKFLOW_EXTERNAL_COMPOSE_FILE:-}"
COMPOSE_EXTERNAL_FILE_EXPLICIT=0
if [[ -n "$COMPOSE_EXTERNAL_FILE" ]]; then
  COMPOSE_EXTERNAL_FILE_EXPLICIT=1
fi
STAMP="$(date +%Y%m%d-%H%M%S)"

OLD_COMMIT=""
NEW_COMMIT=""
DEPLOY_STARTED=0
LIVE_DIR=""
MODE=""
COMPOSE_ARGS=()

log() {
  printf '[%s] %s\n' "$(date '+%F %T')" "$*"
}

die() {
  log "ERROR: $*"
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "缺少命令：$1"
}

resolve_php() {
  if [[ "$USE_DOCKER" == "1" ]]; then
    return 0
  fi
  if [[ -x "$PHP_BIN" ]]; then
    return 0
  fi
  if command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
    return 0
  fi
  die "找不到 PHP（已试 $PHP_BIN 与 PATH 中的 php）"
}

# 兼容三种布局：
# 1) APP_ROOT 本身就是 Git 工作区（推荐，类似亚朵）
# 2) APP_ROOT/current 是 Git 工作区
# 3) APP_ROOT/git 是 Git 工作区，发布到 releases/ + current 软链（旧发布模式）
detect_layout() {
  if [[ -d "$APP_ROOT/.git" ]]; then
    LIVE_DIR="$APP_ROOT"
    MODE="inplace"
    return 0
  fi
  if [[ -d "$APP_ROOT/current/.git" ]]; then
    LIVE_DIR="$APP_ROOT/current"
    MODE="inplace"
    return 0
  fi
  if [[ -d "$APP_ROOT/git/.git" ]]; then
    LIVE_DIR="$APP_ROOT/git"
    MODE="release"
    return 0
  fi

  # 旧宝塔发布目录没有 Git 时，自动旁路初始化仓库，不触碰 current/release 数据。
  if [[ -d "$APP_ROOT/releases" ]]; then
    log "未找到 Git 仓库，初始化旁路仓库：$APP_ROOT/git"
    require_command git
    git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$APP_ROOT/git"
    LIVE_DIR="$APP_ROOT/git"
    MODE="release"
    return 0
  fi

  die "未找到 Git 仓库。请把仓库放到以下之一：$APP_ROOT、$APP_ROOT/current、$APP_ROOT/git"
}

parse_env_value() {
  local file="$1"
  local key="$2"
  [[ -f "$file" ]] || return 0
  local line
  line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" | tail -n1 || true)"
  [[ -n "$line" ]] || return 0
  printf '%s' "$line" | sed -E "s/^[^=]*=[[:space:]]*//" | sed -E "s/^['\"]|['\"]$//g" | tr -d '\r'
}

git_fast_forward() {
  [[ -d "$LIVE_DIR/.git" ]] || die "不是 Git 目录：$LIVE_DIR"
  if [[ -n "$(git -C "$LIVE_DIR" status --porcelain)" ]]; then
    die "生产 Git 工作区有未提交修改，拒绝覆盖。请先处理：git -C $LIVE_DIR status"
  fi
  OLD_COMMIT="$(git -C "$LIVE_DIR" rev-parse HEAD)"
  log "当前提交：$(git -C "$LIVE_DIR" rev-parse --short HEAD)"
  log "拉取 origin/$BRANCH"
  git -C "$LIVE_DIR" fetch --prune origin "$BRANCH"
  git -C "$LIVE_DIR" merge --ff-only "origin/$BRANCH"
  NEW_COMMIT="$(git -C "$LIVE_DIR" rev-parse --short HEAD)"
  log "更新后提交：$NEW_COMMIT"
}

build_frontend() {
  if [[ "$USE_DOCKER" == "1" ]]; then
    log "Docker 模式：前端将在镜像构建阶段生成"
    return 0
  fi
  if [[ "${SKIP_FRONTEND:-0}" == "1" ]]; then
    log "SKIP_FRONTEND=1，跳过前端构建"
    return 0
  fi
  if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
    log "WARN: 服务器未安装 node/npm，跳过前端构建并复用当前 release 前端产物"
    return 0
  fi
  local fe="$LIVE_DIR/frontend"
  [[ -d "$fe" ]] || die "缺少前端目录：$fe"
  log "构建前端 → $LIVE_DIR/public/admin"
  (
    umask 022
    if ! npm --prefix "$fe" ci; then
      log "npm ci 失败，改用 npm install"
      npm --prefix "$fe" install
    fi
    npm --prefix "$fe" run build
  )
  [[ -f "$LIVE_DIR/public/admin/index.html" ]] || die "前端构建失败：缺少 public/admin/index.html"
  find "$LIVE_DIR/public/admin" -type d -exec chmod 755 {} + 2>/dev/null || true
  find "$LIVE_DIR/public/admin" -type f -exec chmod 644 {} + 2>/dev/null || true
  chmod -R a+rX "$LIVE_DIR/public/admin" 2>/dev/null || true
  log "前端构建完成"
}

composer_install_if_needed() {
  if [[ "$USE_DOCKER" == "1" ]]; then
    log "Docker 模式：Composer 依赖将在镜像构建阶段安装"
    return 0
  fi
  if [[ "${SKIP_COMPOSER:-0}" == "1" ]]; then
    log "SKIP_COMPOSER=1，跳过 composer"
    return 0
  fi
  local need=0
  if [[ "${FORCE_COMPOSER:-0}" == "1" ]]; then
    need=1
  elif [[ ! -f "$LIVE_DIR/vendor/autoload.php" ]]; then
    need=1
  elif [[ -n "$OLD_COMMIT" ]] && ! git -C "$LIVE_DIR" diff --quiet "$OLD_COMMIT" HEAD -- composer.lock composer.json 2>/dev/null; then
    need=1
    log "检测到 composer.lock/composer.json 变更"
  fi
  if (( need == 0 )); then
    log "Composer 依赖未变，跳过 install"
    return 0
  fi
  require_command composer
  log "执行 composer install --no-dev"
  (
    cd "$LIVE_DIR"
    composer install --no-dev --optimize-autoloader --no-interaction
  )
  [[ -f "$LIVE_DIR/vendor/autoload.php" ]] || die "composer install 后仍缺少 vendor/autoload.php"
}

publish_release_if_needed() {
  if [[ "$MODE" != "release" ]]; then
    return 0
  fi

  local rel="release-${STAMP}"
  local new="$APP_ROOT/releases/$rel"
  local stage="$APP_ROOT/releases/.incoming-$rel-$$"
  local old=""
  if [[ -L "$APP_ROOT/current" || -d "$APP_ROOT/current" ]]; then
    old="$(readlink -f "$APP_ROOT/current" || true)"
  fi

  log "发布模式：生成 $new"
  rm -rf "$stage" "$new"
  mkdir -p "$stage" "$APP_ROOT/releases"
  if command -v rsync >/dev/null 2>&1; then
    rsync -a \
      --exclude '.git' \
      --exclude 'frontend/node_modules' \
      --exclude 'node_modules' \
      --exclude 'runtime/cache' \
      --exclude 'runtime/temp' \
      --exclude 'runtime/session' \
      --exclude 'runtime/log' \
      --exclude 'docker/mysql' \
      --exclude 'generated-videos' \
      --exclude '*.pem' \
      --exclude '*.key' \
      "$LIVE_DIR"/ "$stage"/
  else
    log "WARN: 未安装 rsync，使用 tar 复制发布文件"
    tar -C "$LIVE_DIR" \
      --exclude='./.git' \
      --exclude='./frontend/node_modules' \
      --exclude='./node_modules' \
      --exclude='./runtime/cache' \
      --exclude='./runtime/temp' \
      --exclude='./runtime/session' \
      --exclude='./runtime/log' \
      --exclude='./docker/mysql' \
      --exclude='./generated-videos' \
      --exclude='*.pem' \
      --exclude='*.key' \
      -cf - . | tar -C "$stage" -xf -
  fi

  # 无 Node 时 git 工作区没有被跟踪的 public/admin，继承当前可用前端构建。
  if [[ ! -f "$stage/public/admin/index.html" && -n "$old" && -f "$old/public/admin/index.html" ]]; then
    mkdir -p "$stage/public/admin"
    cp -a "$old/public/admin/." "$stage/public/admin/"
    log "复用旧 release 前端产物：$old/public/admin"
  fi

  # 继承旧 .env；媒体统一使用共享目录，避免每个 release 重复复制图片/视频。
  if [[ -n "$old" && -f "$old/.env" ]]; then
    cp -a "$old/.env" "$stage/.env"
  elif [[ -f "$APP_ROOT/.env" ]]; then
    cp -a "$APP_ROOT/.env" "$stage/.env"
  else
    die "发布模式缺少 .env（请放在 current/.env 或 $APP_ROOT/.env）"
  fi
  local shared_storage="$APP_ROOT/shared/storage"
  mkdir -p "$APP_ROOT/shared"
  if [[ ! -e "$shared_storage" ]]; then
    if [[ -n "$old" && -d "$old/public/storage" && ! -L "$old/public/storage" ]]; then
      mv "$old/public/storage" "$shared_storage"
    else
      mkdir -p "$shared_storage"
    fi
  fi
  rm -rf "$stage/public/storage"
  ln -s "$shared_storage" "$stage/public/storage"

  mkdir -p "$stage/runtime/log" "$stage/runtime/cache" "$stage/runtime/temp" "$stage/runtime/session"
  chown -R www:www "$stage" 2>/dev/null || true
  chmod -R ug+rwX "$stage/runtime" "$stage/public/storage" 2>/dev/null || true
  mv "$stage" "$new"
  ln -sfn "$new" "$APP_ROOT/current"
  LIVE_DIR="$(readlink -f "$APP_ROOT/current")"
  log "CURRENT=$LIVE_DIR"
}

ensure_runtime_dirs() {
  mkdir -p "$LIVE_DIR/runtime/log" "$LIVE_DIR/runtime/cache" "$LIVE_DIR/runtime/temp" "$LIVE_DIR/runtime/session"
  mkdir -p "$LIVE_DIR/public/storage"
  chown -R www:www "$LIVE_DIR/runtime" "$LIVE_DIR/public/storage" 2>/dev/null || true
  chmod -R ug+rwX "$LIVE_DIR/runtime" "$LIVE_DIR/public/storage" 2>/dev/null || true
}

apply_sql_and_defaults() {
  if [[ "$USE_DOCKER" == "1" && "$EXTERNAL_DB" != "1" ]]; then
    log "Docker 模式：跳过宿主机 SQL/PHP 脚本，避免连接错误运行时；请通过 compose exec 执行一次性迁移"
    return 0
  fi

  local env_file="$LIVE_DIR/.env"
  [[ -f "$env_file" ]] || env_file="$APP_ROOT/.env"
  [[ -f "$env_file" ]] || {
    log "WARN: 无 .env，跳过 SQL/默认模型脚本"
    return 0
  }

  local db_host db_port db_name db_user db_pass mysql_bin
  db_host="$(parse_env_value "$env_file" DB_HOST)"
  db_port="$(parse_env_value "$env_file" DB_PORT)"
  db_name="$(parse_env_value "$env_file" DB_NAME)"
  db_user="$(parse_env_value "$env_file" DB_USER)"
  db_pass="$(parse_env_value "$env_file" DB_PASS)"
  [[ -n "$db_pass" ]] || db_pass="$(parse_env_value "$env_file" DB_PASSWORD)"
  [[ -n "$db_host" ]] || db_host="127.0.0.1"
  [[ -n "$db_port" ]] || db_port="3306"
  if [[ "$db_host" == "host.docker.internal" ]]; then
    db_host="${DB_BACKUP_HOST:-127.0.0.1}"
  fi

  if [[ -x /www/server/mysql/bin/mysql ]]; then
    mysql_bin=/www/server/mysql/bin/mysql
  elif command -v mysql >/dev/null 2>&1; then
    mysql_bin="$(command -v mysql)"
  else
    mysql_bin=""
  fi

  if [[ -n "$mysql_bin" && -n "$db_name" && -n "$db_user" && -f "$LIVE_DIR/database/sql/20260820_add_credit_system.sql" ]]; then
    log "尝试应用积分迁移（可重复执行）"
    MYSQL_PWD="$db_pass" "$mysql_bin" -h"$db_host" -P"$db_port" -u"$db_user" "$db_name" \
      <"$LIVE_DIR/database/sql/20260820_add_credit_system.sql" || log "WARN: 积分 SQL 执行失败/已存在，继续"
  fi

  if [[ "$USE_DOCKER" == "1" ]]; then
    log "Docker 外部数据库模式：跳过宿主机 PHP 默认模型脚本；请在容器启动后手动执行"
    return 0
  fi

  if [[ -f "$LIVE_DIR/scripts/apply_tencent_image_defaults.php" ]]; then
    log "同步腾讯生图默认 options"
    (
      cd "$LIVE_DIR"
      "$PHP_BIN" scripts/apply_tencent_image_defaults.php || log "WARN: apply_tencent_image_defaults 失败，继续"
    )
  fi
}

reload_runtime() {
  if [[ "$USE_DOCKER" == "1" ]]; then
    require_command docker
    [[ -f "$COMPOSE_FILE" ]] || die "缺少 Compose 文件：$COMPOSE_FILE"
    log "Docker 模式：构建并启动 PHP、Nginx、数据库、Redis 与全部 Worker"
    (cd "$(dirname "$COMPOSE_FILE")" && docker compose "${COMPOSE_ARGS[@]}" up -d --build --remove-orphans)
    return 0
  fi

  if [[ -f /www/server/php/82/var/run/php-fpm.pid ]]; then
    kill -USR2 "$(cat /www/server/php/82/var/run/php-fpm.pid)" || true
    log "已重载 PHP-FPM 8.2"
  elif [[ -f /www/server/php/81/var/run/php-fpm.pid ]]; then
    kill -USR2 "$(cat /www/server/php/81/var/run/php-fpm.pid)" || true
    log "已重载 PHP-FPM 8.1"
  else
    log "WARN: 未找到 php-fpm.pid，跳过 FPM 重载"
  fi

  local units=(
    'ai-workflow-worker@workflow:worker'
    'ai-workflow-worker@episode-workflow:worker'
    'ai-workflow-worker@episode-node:worker'
    'ai-workflow-worker@quick-create:worker'
    'ai-workflow-worker@quick-create:face-worker'
    'ai-workflow-worker@script-creation:worker'
    'ai-workflow-worker@agent-task:worker'
  )
  log "重启 systemd workers"
  systemctl restart "${units[@]}" 2>/dev/null || log "WARN: 部分 worker unit 重启失败（可能未安装）"
  # 多实例 asset / video workers（setup_*_workers.sh 安装）；旧单实例 video-job 已废弃
  # 逐个 restart，避免部分 shell 下 brace 不展开变成字面量 @{1..10}
  local i
  for i in 1 2 3; do
    systemctl restart "ai-workflow-asset-image@${i}" 2>/dev/null || true
  done
  for i in 1 2 3 4 5 6 7 8 9 10; do
    systemctl restart "ai-workflow-video-job@${i}" 2>/dev/null || true
  done
  systemctl stop 'ai-workflow-worker@video-job:worker' 2>/dev/null || true
  systemctl disable --now 'ai-workflow-worker@asset-image:worker' 2>/dev/null || true
}

verify() {
  local code
  code="$(curl -k -sS -o /dev/null -w '%{http_code}' --max-time 15 "${SITE_URL}/admin/" || echo 000)"
  log "验收 GET /admin/ → HTTP ${code}"
  [[ "$code" == "200" ]] || die "验收失败：${SITE_URL}/admin/ 返回 $code"

  code="$(curl -k -sS -o /tmp/ai_workflow_deploy_login.json -w '%{http_code}' --max-time 15 \
    -X POST "${SITE_URL}/api/auth/login" \
    -H 'Content-Type: application/json' \
    -d '{"username":"_deploy_probe_","password":"_"}' || echo 000)"
  log "验收 POST /api/auth/login → HTTP ${code}"
  if [[ "$code" == "000" || "$code" == "502" || "$code" == "504" || "$code" == "405" ]]; then
    die "验收失败：登录接口异常 HTTP $code"
  fi
}

rollback_hint() {
  local exit_code=$?
  trap - ERR
  if (( DEPLOY_STARTED == 1 )); then
    log "部署失败。源码可回退：git -C $LIVE_DIR reset --hard ${OLD_COMMIT:-HEAD}"
  fi
  exit "$exit_code"
}

trap rollback_hint ERR

require_command git
require_command curl
if [[ "$USE_DOCKER" == "1" ]]; then
  require_command docker
fi
resolve_php
detect_layout

if [[ "$USE_DOCKER" == "1" && ! -f "$COMPOSE_FILE" && -f "$LIVE_DIR/docker-compose.yml" ]]; then
  COMPOSE_FILE="$LIVE_DIR/docker-compose.yml"
fi

if [[ "$USE_DOCKER" == "1" ]]; then
  COMPOSE_ARGS=(-f "$COMPOSE_FILE")
  if [[ "$EXTERNAL_DB" == "1" ]]; then
    if [[ -z "$COMPOSE_EXTERNAL_FILE" ]]; then
      COMPOSE_EXTERNAL_FILE="$(dirname "$COMPOSE_FILE")/docker-compose.external.yml"
    fi
    [[ -f "$COMPOSE_EXTERNAL_FILE" ]] || die "外部数据库模式缺少 Compose 覆盖文件：$COMPOSE_EXTERNAL_FILE"
    COMPOSE_ARGS+=(-f "$COMPOSE_EXTERNAL_FILE")
  fi
fi

log "APP_ROOT=$APP_ROOT"
log "LIVE_DIR=$LIVE_DIR"
log "MODE=$MODE"
log "PHP=$PHP_BIN"

ENV_FILE="$LIVE_DIR/.env"
[[ -f "$ENV_FILE" ]] || ENV_FILE="$APP_ROOT/.env"
[[ -f "$ENV_FILE" ]] || ENV_FILE="$APP_ROOT/current/.env"
[[ -f "$ENV_FILE" ]] || die "缺少 .env（$LIVE_DIR/.env 或 $APP_ROOT/.env）"

git_fast_forward
DEPLOY_STARTED=1

build_frontend
composer_install_if_needed
publish_release_if_needed
if [[ "$USE_DOCKER" == "1" && -f "$LIVE_DIR/docker-compose.yml" ]]; then
  COMPOSE_FILE="$LIVE_DIR/docker-compose.yml"
  COMPOSE_ARGS=(-f "$COMPOSE_FILE")
  if [[ "$EXTERNAL_DB" == "1" ]]; then
    if (( COMPOSE_EXTERNAL_FILE_EXPLICIT == 0 )); then
      COMPOSE_EXTERNAL_FILE="$LIVE_DIR/docker-compose.external.yml"
    fi
    [[ -f "$COMPOSE_EXTERNAL_FILE" ]] || die "外部数据库模式缺少 Compose 覆盖文件：$COMPOSE_EXTERNAL_FILE"
    COMPOSE_ARGS+=(-f "$COMPOSE_EXTERNAL_FILE")
  fi
fi
ensure_runtime_dirs
apply_sql_and_defaults
reload_runtime
sleep 2
verify

DEPLOY_STARTED=0
trap - ERR
log "部署成功：${NEW_COMMIT:-unknown}"
echo DEPLOY_OK
