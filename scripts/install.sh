#!/usr/bin/env bash
# Mini CMS installer: one file, run once on a fresh Ubuntu 22.04 / 24.04 server as root. It checks the server, installs what is
# missing (PHP 8.4, Nginx, MySQL 8, Node 22 for server-side rendering, Supervisor, Certbot), installs the latest signed release with
# the locked-down layout from docs/DEPLOY.md, creates the first admin, and then deletes itself.
#
#   curl -fsSLO https://github.com/matiuskm/minicms/releases/latest/download/install.sh
#   sudo bash install.sh --domain example.com --email you@example.com
#
# Options:  --domain D       public host name (DNS must already point here for HTTPS)
#           --email E        first admin's email, also used for the HTTPS certificate
#           --name N         first admin's display name (default "Admin")
#           --version X.Y.Z  install a specific release (default: latest)
#           --no-tls         do not request a certificate (TLS ends at a proxy/CDN in front of this server)
#           --dry-run        only check the server and print the plan
#           --yes            do not ask for confirmation
#           --keep           do not delete this file afterwards
#           --repo O/R, --public-key FILE   install a fork (the key is the base64 Ed25519 public key it signs releases with)
#
# Trust: the archive is only unpacked if its Ed25519 signature verifies against the public key below, so a hijacked download
# or mirror cannot install code. Database passwords are generated here, written only to the .env files, and never printed.
set -Eeuo pipefail

# Absolute path of this file, taken before the script changes directory (it deletes itself at the end).
SELF=""
[ -f "${BASH_SOURCE[0]:-}" ] && SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"

REPO="matiuskm/minicms"
PUBLIC_KEY="XkoubcJh9BNht2SlnxbsF5dPJUcM9CLH0Epm6GyVY50="
APP_ROOT="${INSTALL_APP_ROOT:-/var/www/cms}"
DB_NAME="${INSTALL_DB_NAME:-mini_cms}"
LOG="${INSTALL_LOG:-/var/log/cms-install.log}"
TEST="${INSTALL_TEST:-0}"            # runs the application steps only (no apt, users, services); used by the project's own test
MYSQL_CMD="${INSTALL_MYSQL_CMD:-mysql -uroot}"

DOMAIN=""; EMAIL=""; ADMIN_NAME="Admin"; VERSION=""; TLS=1; DRY=0; YES=0; KEEP=0; KEYFILE=""
PROBLEMS=(); WARNINGS=(); TODO=()

# ---- output ------------------------------------------------------------------------------------------------------
exec 3>&1
say()  { printf '%s\n' "$*"; }
step() { printf '\n==> %s\n' "$*"; }
ok()   { printf '  [ok]   %s\n' "$*"; }
need() { printf '  [--]   %s\n' "$*"; }
warn() { printf '  [warn] %s\n' "$*"; WARNINGS+=("$*"); }
bad()  { printf '  [FAIL] %s\n' "$*"; PROBLEMS+=("$*"); }
die()  { printf '\nERROR: %s\n' "$*" >&2; exit 1; }
on_error() { printf '\nThe installer stopped at line %s. Nothing is half-hidden: run it again and it will clean up its own partial work first.\nLog: %s\n' "$1" "$LOG" >&2; }
trap 'on_error $LINENO' ERR

usage() { sed -n '2,22p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

# ---- arguments ---------------------------------------------------------------------------------------------------
while [ $# -gt 0 ]; do
  case "$1" in
    --domain)     DOMAIN="${2:-}"; shift 2 ;;
    --email)      EMAIL="${2:-}"; shift 2 ;;
    --name)       ADMIN_NAME="${2:-}"; shift 2 ;;
    --version)    VERSION="${2:-}"; shift 2 ;;
    --repo)       REPO="${2:-}"; shift 2 ;;
    --public-key) KEYFILE="${2:-}"; shift 2 ;;
    --no-tls)     TLS=0; shift ;;
    --dry-run)    DRY=1; shift ;;
    --yes)        YES=1; shift ;;
    --keep)       KEEP=1; shift ;;
    -h|--help)    usage 0 ;;
    *)            say "Unknown option: $1" >&2; usage 2 ;;
  esac
done

have() { command -v "$1" >/dev/null 2>&1; }
ver_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }   # ver_ge 22.1 22 -> true
rand() { openssl rand -hex 24; }

prompt() { # var "question" [default]
  local var="$1" q="$2" def="${3:-}" ans=""
  [ -n "${!var}" ] && return 0
  if [ -t 0 ]; then read -r -p "$q${def:+ [$def]}: " ans; fi
  printf -v "$var" '%s' "${ans:-$def}"
}

# ---- preflight ---------------------------------------------------------------------------------------------------
preflight() {
  step "Checking this server"

  if [ "$TEST" != 1 ]; then
    [ "$(id -u)" = 0 ] && ok "running as root" || bad "run this as root (sudo bash install.sh ...)"

    local os_id os_ver os_name   # read in a subshell: os-release defines VERSION, which is ours
    os_id=$(. /etc/os-release 2>/dev/null; echo "${ID:-}"); os_ver=$(. /etc/os-release 2>/dev/null; echo "${VERSION_ID:-}"); os_name=$(. /etc/os-release 2>/dev/null; echo "${PRETTY_NAME:-}")
    case "$os_id:$os_ver" in
      ubuntu:22.04|ubuntu:24.04) ok "Ubuntu $os_ver ($(uname -m))" ;;
      *) bad "this installer supports Ubuntu 22.04 and 24.04 (found ${os_name:-an unknown system}); install by hand with docs/DEPLOY.md" ;;
    esac
    case "$(uname -m)" in x86_64|aarch64) ;; *) bad "unsupported CPU architecture $(uname -m)" ;; esac

    local mem_mb disk_mb
    mem_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
    disk_mb=$(df -Pm /var 2>/dev/null | awk 'NR==2 {print $4}')
    [ "${mem_mb:-0}" -ge 900 ] && ok "${mem_mb} MB memory" || bad "at least 1 GB of memory is needed (found ${mem_mb} MB)"
    [ "${disk_mb:-0}" -ge 3000 ] && ok "$((disk_mb / 1024)) GB free disk" || bad "at least 3 GB of free disk space is needed under /var"

    for p in 80 443; do
      local who; who=$( (ss -ltnpH "sport = :$p" 2>/dev/null || true) | { grep -v nginx || true; } | head -n1 )
      [ -z "$who" ] && ok "port $p is free for the web server" || bad "port $p is used by another program: $(printf '%s' "$who" | tr -s ' ' | cut -c1-90)"
    done
  fi

  if ! have curl; then   # a minimal server may not have it, and nothing else can be checked without it
    [ "$(id -u)" = 0 ] && { DEBIAN_FRONTEND=noninteractive apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl ca-certificates >/dev/null; } || true
  fi
  have curl && ok "curl" || bad "curl is required (apt-get install curl)"
  curl -fsS --max-time 15 -o /dev/null -I "https://github.com/$REPO/releases" 2>/dev/null && ok "github.com is reachable" || bad "cannot reach https://github.com (needed to download the release)"

  [ -n "$DOMAIN" ] && printf '%s' "$DOMAIN" | grep -Eq '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$' && ok "domain $DOMAIN" || bad "a valid domain is required (--domain example.com, lower-case)"
  [ -n "$EMAIL" ] && printf '%s' "$EMAIL" | grep -Eq '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$' && ok "admin email $EMAIL" || bad "a valid email is required (--email you@example.com)"
  printf '%s' "$ADMIN_NAME" | grep -Eq '^[^[:cntrl:]]{1,80}$' || bad "the admin name must be 1-80 characters"

  if [ -n "$DOMAIN" ] && have getent; then
    local resolved mine
    resolved=$( (getent ahostsv4 "$DOMAIN" 2>/dev/null || true) | awk 'NR==1 {print $1}')
    mine=$( (ip -4 route get 1.1.1.1 2>/dev/null || true) | awk '{for (i=1;i<=NF;i++) if ($i=="src") print $(i+1)}')
    if [ -z "$resolved" ]; then
      [ "$TLS" = 1 ] && warn "$DOMAIN does not resolve yet; the HTTPS certificate will be skipped (point DNS here, then run: certbot --nginx -d $DOMAIN)"
    elif [ -n "$mine" ] && [ "$resolved" != "$mine" ]; then
      warn "$DOMAIN points to $resolved but this server's address is $mine (fine behind NAT or a proxy; otherwise HTTPS will fail)"
    else
      ok "$DOMAIN resolves to this server"
    fi
  fi

  if [ "$TEST" != 1 ]; then
    if [ -e "$APP_ROOT/current" ] && [ ! -f "$APP_ROOT/.install-incomplete" ]; then
      bad "$APP_ROOT already holds an installation; update it from the admin Updates screen instead"
    fi

    # What is already there and what will be added.
    for spec in "php8.4-fpm:PHP 8.4 (FPM + extensions)" "nginx:Nginx" "mysqld:MySQL 8" "supervisord:Supervisor" "certbot:Certbot (HTTPS)"; do
      local bin="${spec%%:*}" label="${spec#*:}"
      case "$bin" in php8.4-fpm) have php-fpm8.4 || [ -x /usr/sbin/php-fpm8.4 ] ;; supervisord) have supervisord ;; *) have "$bin" || [ -x "/usr/sbin/$bin" ] ;; esac && ok "$label already installed" || { need "$label will be installed"; TODO+=("$label"); }
    done
    if have node && ver_ge "$(node -v | tr -d v)" 22; then ok "Node $(node -v)"; else need "Node 22 will be installed (server-side rendering only)"; TODO+=("Node 22"); fi
    if have mysql && mysql --version 2>/dev/null | grep -qi mariadb; then bad "MariaDB is installed; the CMS needs MySQL 8 (remove MariaDB or install by hand)"; fi
    if have mysql; then
      # shellcheck disable=SC2086
      echo 'SELECT 1' | $MYSQL_CMD >/dev/null 2>&1 && ok "MySQL root access over the local socket" || bad "cannot run 'mysql -uroot' as root; the installer needs administrative MySQL access"
    fi
  fi

  if [ "${#PROBLEMS[@]}" -gt 0 ]; then
    printf '\n%d problem(s) must be fixed first:\n' "${#PROBLEMS[@]}"; printf '  - %s\n' "${PROBLEMS[@]}"
    exit 1
  fi
}

# ---- release lookup ----------------------------------------------------------------------------------------------
resolve_version() {
  if [ -z "$VERSION" ]; then
    local url
    url=$(curl -fsSL --max-time 20 -o /dev/null -w '%{url_effective}' "https://github.com/$REPO/releases/latest") || die "could not find the latest release of $REPO"
    VERSION="${url##*/v}"
  fi
  printf '%s' "$VERSION" | grep -Eq '^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}$' || die "not a valid version: '$VERSION'"
}

# ---- operating system layer --------------------------------------------------------------------------------------
install_system() {
  step "Installing system packages"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y -qq
  apt-get install -y -qq ca-certificates curl gnupg lsb-release software-properties-common openssl tar cron unzip >/dev/null

  if ! [ -x /usr/sbin/php-fpm8.4 ]; then
    say "  adding the PHP package repository (ppa:ondrej/php)"
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -y -qq
  fi
  if ! have node || ! ver_ge "$(node -v | tr -d v)" 22; then
    say "  adding the Node 22 package repository (nodesource.com)"
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
    apt-get update -y -qq
  fi

  apt-get install -y -qq nginx mysql-server supervisor nodejs certbot python3-certbot-nginx \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath php8.4-opcache >/dev/null
  update-alternatives --set php /usr/bin/php8.4 >/dev/null 2>&1 || true

  systemctl enable --now mysql nginx supervisor php8.4-fpm cron >/dev/null 2>&1
  for _ in $(seq 1 30); do echo 'SELECT 1' | $MYSQL_CMD >/dev/null 2>&1 && break; sleep 2; done
  echo 'SELECT 1' | $MYSQL_CMD >/dev/null 2>&1 || die "MySQL did not start"
  ok "packages installed"
}

# ---- application layer -------------------------------------------------------------------------------------------
PHP="${PHP:-php}"
as_deploy() { if [ "$TEST" = 1 ]; then "$@"; else runuser -u deploy -- "$@"; fi; }
setenv() { # file KEY value : set or replace one line
  local f="$1" k="$2" v="$3" tmp; tmp="$(mktemp)"
  grep -v "^${k}=" "$f" > "$tmp" || true
  printf '%s=%s\n' "$k" "$v" >> "$tmp"; cat "$tmp" > "$f"; rm -f "$tmp"
}

cleanup_partial() { # only ever removes what an earlier run of this installer recorded in its own marker
  local marker="$APP_ROOT/.install-incomplete"
  [ -f "$marker" ] || return 0
  warn "a previous installation did not finish; removing its leftovers first"
  if grep -q '^db_created=1' "$marker"; then
    printf 'DROP DATABASE IF EXISTS `%s`; DROP USER IF EXISTS "cms_app"@"localhost"; DROP USER IF EXISTS "cms_migrator"@"localhost";\n' "$DB_NAME" | $MYSQL_CMD
  fi
  rm -rf "$APP_ROOT"
}

fetch_release() {
  step "Downloading and verifying v$VERSION"
  WORK="$(mktemp -d)"; chmod 700 "$WORK"
  trap 'rm -rf "$WORK"' EXIT
  local base="https://github.com/$REPO/releases/download/v$VERSION" name="minicms-v$VERSION.tar.gz"
  curl -fsSL --max-time 900 "$base/$name" -o "$WORK/release.tar.gz" || die "could not download $base/$name"
  curl -fsSL --max-time 60 "$base/$name.sig" -o "$WORK/release.sig" || die "could not download the signature for v$VERSION"

  if [ -n "$KEYFILE" ]; then cp "$KEYFILE" "$WORK/key"; else printf '%s\n' "$PUBLIC_KEY" > "$WORK/key"; fi
  "$PHP" -r '
    $key = base64_decode(trim(file_get_contents($argv[3])), true); $sig = base64_decode(trim(file_get_contents($argv[2])), true);
    if ($key === false || $sig === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) exit(1);
    exit(sodium_crypto_sign_verify_detached($sig, file_get_contents($argv[1]), $key) ? 0 : 1);
  ' "$WORK/release.tar.gz" "$WORK/release.sig" "$WORK/key" || die "the signature of v$VERSION does not match the project's public key; nothing was installed"
  ok "signature verified"
}

install_app() {
  step "Installing the application"
  cleanup_partial
  [ ! -e "$APP_ROOT/current" ] || die "$APP_ROOT already holds an installation; nothing was changed"
  SHARED="$APP_ROOT/shared"; REL="$APP_ROOT/releases/$VERSION"

  if [ "$TEST" != 1 ]; then
    id deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash --comment "CMS deploy" deploy
    usermod -aG deploy www-data   # shared group: the site and the updater can both write storage/ (files are group-writable)
  fi
  mkdir -p "$REL" "$SHARED"
  printf 'started=%s\n' "$(date -u +%FT%TZ)" > "$APP_ROOT/.install-incomplete"

  tar -xzf "$WORK/release.tar.gz" -C "$REL"
  [ "$(tr -d '[:space:]' < "$REL/VERSION")" = "$VERSION" ] && [ -f "$REL/artisan" ] || die "the archive does not contain v$VERSION"
  local min_php; min_php="$("$PHP" -r '$m = json_decode(@file_get_contents($argv[1]), true); echo $m["min_php"] ?? "";' "$REL/manifest.json")"
  [ -z "$min_php" ] || "$PHP" -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$min_php" || die "this release needs PHP $min_php or newer"

  # Shared data lives outside the release so updates never touch it.
  cp -R "$REL/storage" "$SHARED/storage"
  mkdir -p "$SHARED/storage/app/public" "$SHARED/storage/app/updates" "$SHARED/storage/logs" "$SHARED/storage/framework/"{cache,sessions,views}
  rm -rf "$REL/storage"; ln -sfn "$SHARED/storage" "$REL/storage"
  ln -sfn "$SHARED/storage/app/public" "$REL/public/storage"
  ln -sfn "$SHARED/.env" "$REL/.env"
  mkdir -p "$REL/bootstrap/cache"

  local app_pw mig_pw scheme=https
  app_pw="$(rand)"; mig_pw="$(rand)"
  cp "$REL/.env.example" "$SHARED/.env"
  local e="$SHARED/.env"
  setenv "$e" APP_NAME "\"$DOMAIN\""
  setenv "$e" APP_ENV production
  setenv "$e" APP_DEBUG false
  setenv "$e" APP_KEY "base64:$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  setenv "$e" APP_URL "$scheme://$DOMAIN"
  setenv "$e" LOG_LEVEL warning
  setenv "$e" DB_HOST 127.0.0.1
  setenv "$e" DB_DATABASE "$DB_NAME"
  setenv "$e" DB_USERNAME cms_app
  setenv "$e" DB_PASSWORD "$app_pw"
  setenv "$e" SESSION_SECURE_COOKIE true
  setenv "$e" SESSION_ENCRYPT true
  setenv "$e" HASH_DRIVER argon2id
  setenv "$e" CMS_REQUIRE_ADMIN_2FA true
  setenv "$e" CMS_UPDATES true
  setenv "$e" MAIL_FROM_ADDRESS "\"no-reply@$DOMAIN\""
  printf 'DB_USERNAME=cms_migrator\nDB_PASSWORD=%s\n' "$mig_pw" > "$SHARED/.env.migrator"

  # Database: the migrator account creates the tables; only afterwards does the app account get its table-by-table rights
  # (MySQL cannot grant on tables that do not exist yet), which is also what makes audit_logs append-only.
  local sql="$REL/database/hardening/production-grants.sql"
  [ -f "$sql" ] || die "the release has no database/hardening/production-grants.sql"
  # shellcheck disable=SC2086
  local existing; existing="$(echo "SELECT 1 FROM information_schema.schemata WHERE schema_name = '$DB_NAME'" | $MYSQL_CMD -N 2>/dev/null || true)"
  if [ -n "$existing" ]; then
    die "a database named $DB_NAME already exists; refusing to touch it"
  fi
  local part_a="$WORK/grants-a.sql" part_b="$WORK/grants-b.sql"
  awk -v a="$part_a" -v b="$part_b" '/^CREATE USER IF NOT EXISTS .cms_app./ {f=1} {print > (f ? b : a)}' "$sql"
  sed -i.bak -e "s/CHANGE_ME_MIGRATOR/$mig_pw/" -e "s/mini_cms/$DB_NAME/g" "$part_a" && sed -i.bak -e "s/CHANGE_ME_APP/$app_pw/" -e "s/mini_cms/$DB_NAME/g" "$part_b" && rm -f "$part_a.bak" "$part_b.bak"
  printf 'db_created=1\n' >> "$APP_ROOT/.install-incomplete"
  $MYSQL_CMD < "$part_a"

  : > "$SHARED/storage/logs/laravel.log"; chmod 664 "$SHARED/storage/logs/laravel.log"
  if [ "$TEST" != 1 ]; then
    chown -R deploy:www-data "$APP_ROOT"
    chmod -R u=rwX,g=rX,o= "$APP_ROOT"
    chown deploy:www-data "$SHARED/.env"; chmod 640 "$SHARED/.env"
    chown deploy:deploy "$SHARED/.env.migrator"; chmod 600 "$SHARED/.env.migrator"
    chown -R www-data:deploy "$SHARED/storage"
    chmod -R ug+rwX,o-rwx "$SHARED/storage"; find "$SHARED/storage" -type d -exec chmod g+s {} +
  fi
  ln -sfn "$REL" "$APP_ROOT/current"

  step "Preparing the database"
  as_deploy bash -c 'umask 0002 && cd "$2" && "$3" artisan package:discover --no-ansi >/dev/null && set -a && . "$1" && set +a && "$3" artisan migrate --force --no-interaction' _ "$SHARED/.env.migrator" "$REL" "$PHP" >/dev/null
  $MYSQL_CMD < "$part_b"
  ok "tables created, least-privilege accounts in place"

  step "Creating the first admin"
  ADMIN_OUT="$(cd "$REL" && as_deploy bash -c 'umask 0002; exec "$@"' _ "$PHP" artisan cms:make-admin "$EMAIL" --name="$ADMIN_NAME" --no-ansi 2>&1)" || die "could not create the admin account"
  ADMIN_PW="$(printf '%s\n' "$ADMIN_OUT" | sed -n 's/^Password (shown once): //p')"
  [ -n "$ADMIN_PW" ] || die "the admin account was created but no password was returned"

  ( cd "$REL" && as_deploy bash -c 'umask 0002; for c in config route view; do "$1" artisan "$c:cache" || exit 1; done' _ "$PHP" ) >/dev/null
  rm -f "$APP_ROOT/.install-incomplete"
}

# ---- web server, background jobs, hardening ----------------------------------------------------------------------
configure_services() {
  step "Configuring Nginx, PHP-FPM, Supervisor and cron"
  cat > /etc/php/8.4/fpm/conf.d/99-cms.ini <<'INI'
expose_php = Off
display_errors = Off
session.cookie_httponly = 1
upload_max_filesize = 12M
post_max_size = 12M
opcache.validate_timestamps = 0
INI
  install -d /etc/systemd/system/php8.4-fpm.service.d
  printf '[Service]\nUMask=0002\n' > /etc/systemd/system/php8.4-fpm.service.d/cms-umask.conf   # files the site writes stay group-writable for the updater
  systemctl daemon-reload

  # http-level: per-address request limits. The map leaves the key empty (= no limit) for everything but the sign-in routes.
  cat > /etc/nginx/conf.d/cms-limits.conf <<'NGINX'
map $request_uri $cms_auth_key {
    default "";
    ~^/(login|two-factor-challenge|forgot-password|reset-password|invite)(\?|/|$) $binary_remote_addr;
}
limit_req_zone $binary_remote_addr zone=cms_all:10m rate=20r/s;
limit_req_zone $cms_auth_key zone=cms_auth:10m rate=30r/m;
limit_req_status 429;
NGINX

  cat > /etc/nginx/sites-available/cms <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    root $APP_ROOT/current/public;
    index index.php;
    client_max_body_size 12m;
    server_tokens off;

    # Slow-client (slowloris) limits.
    client_header_timeout 10s;
    client_body_timeout 15s;
    send_timeout 15s;

    # Only the configured domain is served: requests by IP address or any other Host get no answer.
    if (\$host != \$server_name) { return 444; }

    location ~ /\.(?!well-known) { deny all; }

    # Uploaded files are data, never code.
    location ^~ /storage/ {
        location ~ \.php\$ { return 403; }
        add_header X-Content-Type-Options nosniff always;
        expires 30d;
    }
    location ^~ /build/ { expires 1y; add_header Cache-Control "public, immutable"; }

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        limit_req zone=cms_all burst=100 nodelay;
        limit_req zone=cms_auth burst=10 nodelay;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ ^/(?!index\.php).+\.php\$ { return 404; }   # only index.php may execute
}
NGINX
  ln -sfn /etc/nginx/sites-available/cms /etc/nginx/sites-enabled/cms
  [ -L /etc/nginx/sites-enabled/default ] && rm -f /etc/nginx/sites-enabled/default
  nginx -t >/dev/null 2>&1 || { nginx -t; die "the Nginx configuration is invalid"; }

  cat > /etc/supervisor/conf.d/cms-ssr.conf <<SUP
[program:cms-ssr]
command=/usr/bin/php8.4 $APP_ROOT/current/artisan inertia:start-ssr
user=www-data
autostart=true
autorestart=true
stopasgroup=true
SUP

  cat > /etc/cron.d/cms <<CRON
# Mini CMS: scheduler (scheduled posts, integrity check, backups), update requests from the admin, weekly audit-log retention.
PHP=/usr/bin/php8.4
* * * * * www-data cd $APP_ROOT/current && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
* * * * * deploy umask 0002; APP_ROOT=$APP_ROOT $APP_ROOT/current/scripts/apply-update.sh >> $APP_ROOT/shared/storage/logs/updater.log 2>&1
30 3 * * 0 deploy umask 0002; cd $APP_ROOT/current && set -a && . $APP_ROOT/shared/.env.migrator && set +a && /usr/bin/php8.4 artisan cms:prune-audit >> $APP_ROOT/shared/storage/logs/prune.log 2>&1
CRON
  chmod 644 /etc/cron.d/cms

  # The deploy account may reload two services and nothing else.
  printf 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm, /usr/bin/supervisorctl restart cms-ssr\n' > /etc/sudoers.d/cms-deploy
  chmod 440 /etc/sudoers.d/cms-deploy
  visudo -cf /etc/sudoers.d/cms-deploy >/dev/null || { rm -f /etc/sudoers.d/cms-deploy; die "could not write a valid sudoers rule"; }

  systemctl restart php8.4-fpm; systemctl reload nginx
  supervisorctl reread >/dev/null; supervisorctl update >/dev/null
  ok "services configured"

  if [ "$TLS" = 1 ]; then
    step "Requesting the HTTPS certificate"
    if certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --no-eff-email --redirect -n >/dev/null 2>&1; then
      ok "HTTPS enabled for $DOMAIN (renews automatically)"; TLS_OK=1
    else
      warn "no certificate yet. Point $DOMAIN to this server, then run: certbot --nginx -d $DOMAIN -m $EMAIL --agree-tos --redirect. Sign-in needs HTTPS."
    fi
  else
    ok "skipping HTTPS as requested (--no-tls): terminate TLS in front of this server"
  fi
}

check_site() {
  step "Final checks"
  local url="http://127.0.0.1/up" extra=(-H "Host: $DOMAIN")
  [ "${TLS_OK:-0}" = 1 ] && { url="https://$DOMAIN/up"; extra=(--resolve "$DOMAIN:443:127.0.0.1"); }
  local good=0
  for _ in $(seq 1 10); do curl -fsSk --max-time 5 "${extra[@]}" -o /dev/null "$url" && { good=1; break; }; sleep 2; done
  [ "$good" = 1 ] && ok "the site answers" || warn "the site did not answer on $url yet; check: journalctl -u nginx -u php8.4-fpm"
  # Run as the web user: the checklist asks whether *that* account can overwrite the code (it must not be able to).
  ( cd "$APP_ROOT/current" && runuser -u www-data -- "$PHP" artisan cms:check-production --no-ansi 2>&1 | sed 's/^/    /' ) || warn "cms:check-production reported items to review (above)"
}

# ---- main --------------------------------------------------------------------------------------------------------
main() {
  say "Mini CMS installer"
  prompt DOMAIN "Domain name (DNS already pointing here)"
  prompt EMAIL "Admin email"
  prompt ADMIN_NAME "Admin name" "Admin"

  have openssl || [ "$TEST" != 1 ] || die "openssl is required"
  preflight
  resolve_version

  step "Plan"
  say "  install Mini CMS v$VERSION from github.com/$REPO"
  say "  site     $DOMAIN  ($( [ "$TLS" = 1 ] && echo 'HTTPS via Let'\''s Encrypt' || echo 'no certificate requested' ))"
  say "  admin    $ADMIN_NAME <$EMAIL>   (a one-time password is shown at the end; two-factor setup is required at first sign-in)"
  [ "${#TODO[@]}" -gt 0 ] && say "  adds     $(printf '%s, ' "${TODO[@]}" | sed 's/, $//')"
  say "  files    $APP_ROOT   log: $LOG"
  if [ "$DRY" = 1 ]; then say; say "Dry run: nothing was changed."; exit 0; fi
  if [ "$YES" != 1 ]; then
    [ -t 0 ] || die "not a terminal: add --yes to confirm"
    read -r -p "Continue? [y/N] " go; [ "$go" = y ] || [ "$go" = Y ] || die "cancelled"
  fi

  cd /
  if [ "$TEST" != 1 ]; then
    ( umask 077; : > "$LOG" )
    exec > >(tee -a "$LOG") 2>&1
    install_system
    PHP=/usr/bin/php8.4
  fi
  fetch_release
  install_app
  [ "$TEST" = 1 ] || configure_services
  [ "$TEST" = 1 ] || check_site

  {
    echo
    echo "=================================================================="
    echo " Mini CMS v$VERSION is installed."
    echo "   Sign in:   $( [ "${TLS_OK:-0}" = 1 ] || [ "$TLS" = 0 ] && echo https || echo http )://$DOMAIN/login"
    echo "   Email:     $EMAIL"
    echo "   Password:  $ADMIN_PW        (shown once, not saved anywhere; change it after signing in)"
    echo "   The first sign-in asks you to set up two-factor authentication."
    echo
    echo " Still to do on your side:"
    echo "   - Mail: set MAIL_* in $APP_ROOT/shared/.env (invites and alerts use it; until then cms:check-production reports it), then:"
    echo "       sudo -u deploy php $APP_ROOT/current/artisan config:cache"
    echo "   - Check the SSR process (search engines get full HTML): supervisorctl status cms-ssr"
    echo "   - Backups: point BACKUP_DISK at storage on another machine (docs/DEPLOY.md section 7)"
    echo "   - Firewall: allow only 22, 80 and 443 (for example: ufw allow OpenSSH; ufw allow 'Nginx Full'; ufw enable)"
    [ "${#WARNINGS[@]}" -gt 0 ] && { echo; echo " Warnings:"; printf '   - %s\n' "${WARNINGS[@]}"; }
    echo "=================================================================="
  } >&3

  if [ "$KEEP" != 1 ] && [ -n "$SELF" ] && [ -f "$SELF" ]; then
    rm -f -- "$SELF" && say "This installer file has been deleted." >&3
  fi
}

main
