#!/bin/bash
set -euo pipefail

CONF=/www/server/panel/vhost/nginx/ai-workflow.conf
cp -a "$CONF" "/root/ai-workflow.conf.bak.$(date +%Y%m%d-%H%M%S)"

python3 - <<'PY'
from pathlib import Path

path = Path("/www/server/panel/vhost/nginx/ai-workflow.conf")
text = path.read_text(encoding="utf-8")

old_variants = [
    """    location /admin/assets/ {
        try_files $uri @admin_asset_missing;
        access_log off;
        expires 365d;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }

    location @admin_asset_missing {
        add_header Cache-Control "no-store" always;
        return 404;
    }""",
    """    location /admin/assets/ {
        try_files $uri =404;
        access_log off;
        expires 365d;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }""",
    """    location /admin/assets/ {
        try_files $uri =404;
        access_log off;
        expires 365d;
        add_header Cache-Control "public, max-age=31536000, immutable" always;
    }""",
]

new_block = """    location /admin/static/ {
        try_files $uri @admin_static_missing;
        access_log off;
        expires 365d;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }

    location @admin_static_missing {
        add_header Cache-Control "no-store" always;
        return 404;
    }

    location = /admin/assets/ {
        return 301 /admin/assets;
    }"""

if "location /admin/static/" in text and "location = /admin/assets/" in text:
    print("already_patched")
else:
    replaced = 0
    for old in old_variants:
        c = text.count(old)
        if c:
            text = text.replace(old, new_block)
            replaced += c
    if replaced == 0 and "location /admin/static/" not in text:
        raise SystemExit("assets/static location block not found to patch")
    path.write_text(text, encoding="utf-8")
    print(f"nginx_static_path_updated={replaced}")

print(path.read_text(encoding="utf-8"))
PY

nginx -t
nginx -s reload
echo NGINX_OK
