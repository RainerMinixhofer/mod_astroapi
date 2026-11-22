#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PAYLOAD_DIR="$SCRIPT_DIR/payload"
JOOMLA_ROOT="${JOOMLA_ROOT:-/var/www/joomla}"
SERVICE_USER="${SERVICE_USER:-}"
WEB_GROUP="${WEB_GROUP:-www-data}"
INBOX="${ASTROAPI_INBOX:-/srv/astrodata/inbox}"
DB_NAME="${ASTROAPI_DB_NAME:-astrophotos}"
DB_USER="${ASTROAPI_DB_USER:-astroapi}"
DB_PASSWORD="${ASTROAPI_DB_PASSWORD:-}"
DB_HOST="${ASTROAPI_DB_HOST:-localhost}"
DB_PORT="${ASTROAPI_DB_PORT:-3306}"
API_PORT="${ASTROAPI_PORT:-8010}"
PHOCA_SYNC_SECRET="${ASTROAPI_PHOCA_SYNC_SECRET:-}"
SKIP_APT=0

usage() {
    cat <<'EOF'
Usage:
  sudo ./install.sh [--skip-apt] [--joomla-root /var/www/joomla]

Environment overrides:
  SERVICE_USER                 Unix account running AstroAPI
  WEB_GROUP                    Joomla web group, default www-data
  ASTROAPI_INBOX               Inbox path, default /srv/astrodata/inbox
  ASTROAPI_DB_NAME             Database name, default astrophotos
  ASTROAPI_DB_USER             Database user, default astroapi
  ASTROAPI_DB_PASSWORD         Database password; prompted if omitted
  ASTROAPI_DB_HOST             Database host, default localhost
  ASTROAPI_DB_PORT             Database port, default 3306
  ASTROAPI_PORT                Local API port, default 8010
  ASTROAPI_PHOCA_SYNC_SECRET   Plugin sync secret; generated if omitted
EOF
}

while (($#)); do
    case "$1" in
        --skip-apt)
            SKIP_APT=1
            shift
            ;;
        --joomla-root)
            JOOMLA_ROOT="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage
            exit 2
            ;;
    esac
done

if [[ $EUID -ne 0 ]]; then
    echo "Run this installer as root." >&2
    exit 1
fi

if [[ ! -f "$JOOMLA_ROOT/configuration.php" || ! -f "$JOOMLA_ROOT/cli/joomla.php" ]]; then
    echo "Joomla root is invalid: $JOOMLA_ROOT" >&2
    exit 1
fi

if [[ ! -d "$JOOMLA_ROOT/administrator/components/com_phocagallery" ]]; then
    echo "Phoca Gallery is not installed. Install Phoca Gallery first, then rerun." >&2
    exit 1
fi

if [[ -z "$SERVICE_USER" ]]; then
    if [[ -n "${SUDO_USER:-}" && "$SUDO_USER" != "root" ]]; then
        SERVICE_USER="$SUDO_USER"
    else
        read -r -p "AstroAPI service user: " SERVICE_USER
    fi
fi

if ! id "$SERVICE_USER" >/dev/null 2>&1; then
    echo "Service user does not exist: $SERVICE_USER" >&2
    exit 1
fi
if ! getent group "$WEB_GROUP" >/dev/null 2>&1; then
    echo "Web group does not exist: $WEB_GROUP" >&2
    exit 1
fi

if [[ -z "$DB_PASSWORD" ]]; then
    read -r -s -p "AstroAPI database password: " DB_PASSWORD
    echo
fi
if [[ -z "$DB_PASSWORD" ]]; then
    echo "A database password is required." >&2
    exit 1
fi
if [[ -z "$PHOCA_SYNC_SECRET" ]]; then
    PHOCA_SYNC_SECRET="$(openssl rand -hex 32)"
fi

if [[ $SKIP_APT -eq 0 ]]; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        python3 python3-venv python3-pip \
        php-cli php-curl php-gd php-mysql \
        mariadb-client curl unzip rsync
fi

command -v php >/dev/null
command -v python3 >/dev/null
command -v mysql >/dev/null
command -v zip >/dev/null || apt-get install -y zip

MODULE_DIR="$JOOMLA_ROOT/modules/mod_astroapi"
ENV_DIR="/etc/astroapi"
ENV_FILE="$ENV_DIR/astroapi.env"
SYNC_ENV_FILE="$ENV_DIR/phoca-sync.env"

install -d -m 2775 -o "$SERVICE_USER" -g "$WEB_GROUP" "$INBOX"
install -d -m 0750 -o root -g "$SERVICE_USER" "$ENV_DIR"
install -d -m 2775 -o "$WEB_GROUP" -g "$WEB_GROUP" \
    "$JOOMLA_ROOT/images/phocagallery/astroapi-media"

printf '%s\n' \
    "ASTROAPI_DB_HOST=$DB_HOST" \
    "ASTROAPI_DB_PORT=$DB_PORT" \
    "ASTROAPI_DB_NAME=$DB_NAME" \
    "ASTROAPI_DB_USER=$DB_USER" \
    "ASTROAPI_DB_PASSWORD=$DB_PASSWORD" \
    "ASTROAPI_INBOX=$INBOX" \
    "ASTROAPI_JOOMLA_ROOT=$JOOMLA_ROOT" > "$ENV_FILE"
printf 'ASTROAPI_PHOCA_SYNC_SECRET=%s\n' "$PHOCA_SYNC_SECRET" > "$SYNC_ENV_FILE"
chmod 0640 "$ENV_FILE" "$SYNC_ENV_FILE"
chown root:"$SERVICE_USER" "$ENV_FILE" "$SYNC_ENV_FILE"

db_admin="$ENV_DIR/db-admin.sql"
cat > "$db_admin" <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$DB_HOST';
FLUSH PRIVILEGES;
SQL
mysql < "$db_admin"
shred -u "$db_admin"

schema=$(mktemp)
sed "s/__ASTRO_DB__/$DB_NAME/g" "$SCRIPT_DIR/sql/schema.sql" > "$schema"
mysql < "$schema"
rm -f "$schema"

tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT
(cd "$PAYLOAD_DIR/module" && zip -qr "$tmpdir/mod_astroapi.zip" .)
(cd "$PAYLOAD_DIR/plugin" && zip -qr "$tmpdir/plg_system_astroapi_phoca_sync.zip" .)

php "$JOOMLA_ROOT/cli/joomla.php" extension:install --path="$tmpdir/mod_astroapi.zip"
php "$JOOMLA_ROOT/cli/joomla.php" extension:install --path="$tmpdir/plg_system_astroapi_phoca_sync.zip"
rsync -a "$PAYLOAD_DIR/module/categories.md" "$MODULE_DIR/categories.md" 2>/dev/null || true
install -d -m 2775 -o "$WEB_GROUP" -g "$WEB_GROUP" "$MODULE_DIR"
chown -R "$SERVICE_USER":"$WEB_GROUP" "$MODULE_DIR"
find "$MODULE_DIR" -type d -exec chmod 2775 {} +
find "$MODULE_DIR" -type f -exec chmod 0664 {} +
chmod 0755 "$MODULE_DIR/tools/update_category_thumbnails.sh"

if [[ ! -x "$MODULE_DIR/venv/bin/python" ]]; then
    sudo -u "$SERVICE_USER" python3 -m venv "$MODULE_DIR/venv"
fi
sudo -u "$SERVICE_USER" "$MODULE_DIR/venv/bin/pip" install --upgrade pip
sudo -u "$SERVICE_USER" "$MODULE_DIR/venv/bin/pip" install \
    -r "$MODULE_DIR/backend/requirements.txt"

export ASTRO_SYNC_SECRET="$PHOCA_SYNC_SECRET"
export ASTRO_API_URL="http://127.0.0.1:$API_PORT"
php -r '
require "'"$JOOMLA_ROOT"'/configuration.php";
$c = new JConfig();
$db = new mysqli($c->host, $c->user, $c->password, $c->db);
$secret = getenv("ASTRO_SYNC_SECRET");
$stmt = $db->prepare("UPDATE `" . $c->dbprefix . "extensions` SET enabled=1, params=JSON_SET(COALESCE(params, \"{}\"), \"$.api_url\", ?, \"$.sync_secret\", ?) WHERE type=\"plugin\" AND folder=\"system\" AND element=\"astroapi_phoca_sync\"");
$api = getenv("ASTRO_API_URL");
$stmt->bind_param("ss", $api, $secret);
$stmt->execute();
if ($stmt->affected_rows < 0) exit(1);
'
unset ASTRO_SYNC_SECRET ASTRO_API_URL

render_unit() {
    local source="$1"
    local target="$2"
    sed \
        -e "s|__SERVICE_USER__|$SERVICE_USER|g" \
        -e "s|__WEB_GROUP__|$WEB_GROUP|g" \
        -e "s|__JOOMLA_ROOT__|$JOOMLA_ROOT|g" \
        -e "s|__ENV_FILE__|$ENV_FILE|g" \
        -e "s|__SYNC_ENV_FILE__|$SYNC_ENV_FILE|g" \
        -e "s|__API_PORT__|$API_PORT|g" \
        "$source" > "$target"
}

render_unit "$SCRIPT_DIR/systemd/astroapi.service.template" /etc/systemd/system/astroapi.service
render_unit "$SCRIPT_DIR/systemd/astroimport-watchdog.service.template" \
    /etc/systemd/system/astroimport-watchdog.service

systemctl daemon-reload
systemctl disable astroimport.timer 2>/dev/null || true
systemctl stop astroimport.timer 2>/dev/null || true
systemctl enable --now astroapi.service astroimport-watchdog.service

sudo -u "$SERVICE_USER" env \
    ASTROAPI_JOOMLA_ROOT="$JOOMLA_ROOT" \
    ASTROAPI_INBOX="$INBOX" \
    php "$MODULE_DIR/tools/install_phoca_categories.php" --apply
sudo -u "$SERVICE_USER" env \
    ASTROAPI_JOOMLA_ROOT="$JOOMLA_ROOT" \
    ASTROAPI_INBOX="$INBOX" \
    php "$MODULE_DIR/tools/create_astroapi_inbox_tree.php"

echo
echo "AstroAPI/Phoca installation completed."
echo "  API: http://127.0.0.1:$API_PORT"
echo "  Inbox: $INBOX"
echo "  Module: $MODULE_DIR"
echo "  Sync secret: $PHOCA_SYNC_SECRET"
echo
echo "Configure the Astronomy Observations Phoca menu item in Joomla, then:"
echo "  $MODULE_DIR/tools/update_category_thumbnails.sh"
