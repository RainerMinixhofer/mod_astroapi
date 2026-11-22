#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

required=(
    "$SCRIPT_DIR/install.sh"
    "$SCRIPT_DIR/sql/schema.sql"
    "$SCRIPT_DIR/systemd/astroapi.service.template"
    "$SCRIPT_DIR/systemd/astroimport-watchdog.service.template"
    "$SCRIPT_DIR/payload/module/mod_astroapi.xml"
    "$SCRIPT_DIR/payload/module/backend/api.py"
    "$SCRIPT_DIR/payload/module/backend/import_fits.py"
    "$SCRIPT_DIR/payload/module/backend/inbox_watchdog.py"
    "$SCRIPT_DIR/payload/module/categories.md"
    "$SCRIPT_DIR/payload/plugin/astroapi_phoca_sync.xml"
    "$SCRIPT_DIR/payload/plugin/src/Extension/AstroapiPhocaSync.php"
)

for path in "${required[@]}"; do
    [[ -e "$path" ]] || {
        echo "Missing: $path" >&2
        exit 1
    }
done

bash -n "$SCRIPT_DIR/install.sh"
php -l "$SCRIPT_DIR/payload/module/tools/create_astroapi_inbox_tree.php"
php -l "$SCRIPT_DIR/payload/module/tools/install_phoca_categories.php"
php -l "$SCRIPT_DIR/payload/plugin/src/Extension/AstroapiPhocaSync.php"
python3 -m py_compile \
    "$SCRIPT_DIR/payload/module/backend/api.py" \
    "$SCRIPT_DIR/payload/module/backend/import_fits.py" \
    "$SCRIPT_DIR/payload/module/backend/inbox_watchdog.py"

echo "Install package checks passed."
