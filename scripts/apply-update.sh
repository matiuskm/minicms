#!/usr/bin/env bash
# Applies an update that an admin requested from the web UI. Run it every minute from cron as the DEPLOY user
# (never as the web server user); with no pending request it exits immediately.
#
#   * * * * * deploy APP_ROOT=/var/www/cms /var/www/cms/current/scripts/apply-update.sh
#
# Layout it expects (see docs/DEPLOY.md):  $APP_ROOT/{current -> releases/<version>, releases/, shared/{.env,storage}}
#
# Trust model: the request file written by the web app only names a version. Everything else is decided here:
# the version must be newer than the installed one, the archive comes from the project's own release page and must
# carry an Ed25519 signature that verifies against release-public.key *of the version already installed*.
# A compromised website can therefore at most trigger an update to a genuine, newer, signed release.
set -uo pipefail

APP_ROOT="${APP_ROOT:-/var/www/cms}"
PHP="${PHP:-php}"
REPO="${CMS_UPDATE_REPO:-matiuskm/minicms}"
API="${CMS_UPDATE_API:-https://api.github.com}"
KEEP="${CMS_KEEP_RELEASES:-3}"
RELOAD_CMD="${CMS_RELOAD_CMD:-sudo -n systemctl reload php8.4-fpm; sudo -n supervisorctl restart cms-ssr}"

SHARED="$APP_ROOT/shared"
UPDATES="$SHARED/storage/app/updates"
REQ="$UPDATES/request.json"
STATUS="$UPDATES/status.json"
CURRENT="$APP_ROOT/current"
LOCK="$SHARED/.update.lock"

[ -f "$REQ" ] || exit 0                                   # nothing requested: the common case

# One run at a time. mkdir is atomic everywhere (flock is not on macOS); a lock older than an hour is stale.
if ! mkdir "$LOCK" 2>/dev/null; then
  [ -n "$(find "$LOCK" -maxdepth 0 -mmin +60 2>/dev/null)" ] && rm -rf "$LOCK" && mkdir "$LOCK" || exit 0
fi

WORK="$(mktemp -d)"
VERSION=""; FROM=""; PREV_TARGET=""; MAINT_WAS=""; REL=""; SWITCHED=0
cleanup() { rm -rf "$WORK" "$LOCK"; }
trap cleanup EXIT

status() { # state step message
  "$PHP" "$CURRENT/scripts/update-status.php" "$STATUS" "$1" "$2" "$3" "$VERSION" "$FROM" 2>/dev/null || true
}
log() { echo "[apply-update] $*"; }

restore_maintenance() {
  [ "$MAINT_WAS" = "was-off" ] && ( cd "$CURRENT" && "$PHP" artisan cms:maintenance off >/dev/null 2>&1 ) || true
}

reload_services() { eval "$RELOAD_CMD" >/dev/null 2>&1 || log "warning: reload command failed"; }

switch_to() { # target
  if mv --version >/dev/null 2>&1; then   # GNU mv: atomic replace of the symlink
    ln -sfn "$1" "$APP_ROOT/current.tmp" && mv -Tf "$APP_ROOT/current.tmp" "$CURRENT"
  else
    ln -sfn "$1" "$CURRENT"
  fi
}

fail() { # step message [rolled_back]
  log "FAILED at $1: $2"
  status failed "$1" "$2"
  restore_maintenance
  [ -n "$VERSION" ] && ( cd "$CURRENT" && "$PHP" artisan cms:record-update "${FROM:-0.0.0}" "$VERSION" "${3:-failed}" --note="$1: $2" >/dev/null 2>&1 ) || true
  exit 1
}

# ---- 1. read the request (untrusted: only a plain version number is accepted) -----------------------------------
mv "$REQ" "$WORK/request.json" 2>/dev/null || exit 0     # take it, so a crash can never loop
VERSION="$("$PHP" -r '$j = json_decode(@file_get_contents($argv[1]), true); echo (is_array($j) && preg_match("/^\d{1,4}\.\d{1,4}\.\d{1,4}$/", (string)($j["version"] ?? ""))) ? $j["version"] : "";' "$WORK/request.json")"
FROM="$(tr -d '[:space:]' < "$CURRENT/VERSION" 2>/dev/null || echo 0.0.0)"
[ -n "$VERSION" ] || { VERSION=""; fail request "The update request was not valid."; }
"$PHP" -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$VERSION" "$FROM" || fail request "Version $VERSION is not newer than the installed $FROM."

status running checking "Looking up release v$VERSION"
log "updating $FROM -> $VERSION"

# ---- 2. fetch the release and its signature ---------------------------------------------------------------------
curl -fsSL --max-time 30 -H 'Accept: application/vnd.github+json' -A minicms-updater "$API/repos/$REPO/releases/tags/v$VERSION" -o "$WORK/release.json" \
  || fail checking "Could not read release v$VERSION from the release server."
ASSET_URL="$("$PHP" -r '$r = json_decode(file_get_contents($argv[1]), true); foreach (($r["assets"] ?? []) as $a) if (($a["name"] ?? "") === $argv[2]) { echo $a["browser_download_url"]; break; }' "$WORK/release.json" "minicms-v$VERSION.tar.gz")"
SIG_URL="$("$PHP" -r '$r = json_decode(file_get_contents($argv[1]), true); foreach (($r["assets"] ?? []) as $a) if (($a["name"] ?? "") === $argv[2]) { echo $a["browser_download_url"]; break; }' "$WORK/release.json" "minicms-v$VERSION.tar.gz.sig")"
[ -n "$ASSET_URL" ] && [ -n "$SIG_URL" ] || fail checking "Release v$VERSION has no signed package."

status running downloading "Downloading v$VERSION"
curl -fsSL --max-time 600 -A minicms-updater "$ASSET_URL" -o "$WORK/release.tar.gz" || fail downloading "Download failed."
curl -fsSL --max-time 30 -A minicms-updater "$SIG_URL" -o "$WORK/release.sig" || fail downloading "Signature download failed."

status running verifying "Verifying the signature"
REASON="$("$PHP" "$CURRENT/scripts/verify-release.php" "$WORK/release.tar.gz" "$WORK/release.sig" "$CURRENT/release-public.key" 2>&1)" \
  || fail verifying "Rejected: $REASON. Nothing was installed."

# ---- 3. unpack beside the running version ----------------------------------------------------------------------
REL="$APP_ROOT/releases/$VERSION"
rm -rf "$REL"; mkdir -p "$REL"
tar -xzf "$WORK/release.tar.gz" -C "$REL" || fail verifying "The archive could not be unpacked."
chmod u=rwx,g=rx,o= "$REL"   # whatever the archive says about its root folder, the web server's group must be able to enter
[ "$(tr -d '[:space:]' < "$REL/VERSION" 2>/dev/null)" = "$VERSION" ] && [ -f "$REL/artisan" ] || fail verifying "The archive does not contain version $VERSION."
MIN_PHP="$("$PHP" -r '$m = json_decode(@file_get_contents($argv[1]), true); echo $m["min_php"] ?? "";' "$REL/manifest.json")"
if [ -n "$MIN_PHP" ]; then
  "$PHP" -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$MIN_PHP" || fail verifying "This release needs PHP $MIN_PHP or newer."
fi

# Same configuration and data as the running version.
ln -sfn "$SHARED/.env" "$REL/.env"
rm -rf "$REL/storage"; ln -sfn "$SHARED/storage" "$REL/storage"
rm -rf "$REL/public/storage"; ln -sfn "$SHARED/storage/app/public" "$REL/public/storage"
mkdir -p "$REL/bootstrap/cache"

# ---- 4. back up, then maintenance mode ---------------------------------------------------------------------------
if [ "${CMS_UPDATE_SKIP_BACKUP:-0}" != "1" ]; then
  status running backing_up "Taking a backup"
  ( cd "$CURRENT" && "$PHP" artisan cms:backup ) >"$WORK/backup.log" 2>&1 || fail backing_up "The backup failed, so the update was not started. $(tail -n 2 "$WORK/backup.log" | tr '\n' ' ')"
fi
MAINT_WAS="$( cd "$CURRENT" && "$PHP" artisan cms:maintenance on 2>/dev/null | tail -n 1 )"

# ---- 5. database ----------------------------------------------------------------------------------------------
status running migrating "Updating the database"
( cd "$REL" && "$PHP" artisan package:discover --no-ansi ) >"$WORK/discover.log" 2>&1 || fail migrating "package:discover failed. $(tail -n 2 "$WORK/discover.log" | tr '\n' ' ')"
if [ "${CMS_UPDATE_SKIP_MIGRATE:-0}" != "1" ]; then
  (
    if [ -f "$SHARED/.env.migrator" ]; then set -a; . "$SHARED/.env.migrator"; set +a; fi   # DDL-capable account, deploy-only
    cd "$REL" && "$PHP" artisan migrate --force --no-interaction
  ) >"$WORK/migrate.log" 2>&1 || fail migrating "Migration failed; the site was not switched. $(tail -n 3 "$WORK/migrate.log" | tr '\n' ' ')"
fi

# ---- 6. switch -------------------------------------------------------------------------------------------------
status running switching "Switching to v$VERSION"
PREV_TARGET="$(readlink "$CURRENT" || true)"
switch_to "$REL" || fail switching "Could not switch the release."
SWITCHED=1
( cd "$CURRENT" && "$PHP" artisan optimize:clear >/dev/null 2>&1; "$PHP" artisan config:cache >/dev/null 2>&1; "$PHP" artisan route:cache >/dev/null 2>&1; "$PHP" artisan view:cache >/dev/null 2>&1 )
reload_services

# ---- 7. health check, roll the code back if it fails ---------------------------------------------------------
status running health_check "Checking that the site responds"
HEALTH_URL="${CMS_HEALTH_URL:-$(grep -E '^APP_URL=' "$SHARED/.env" | head -n1 | cut -d= -f2- | tr -d '"')/up}"
OK=0
for _ in 1 2 3 4 5 6 7 8 9 10; do
  GOT="$( cd "$CURRENT" && "$PHP" artisan cms:version 2>/dev/null | tail -n 1 )"
  if [ "$GOT" = "$VERSION" ] && curl -fsS -o /dev/null --max-time 5 "$HEALTH_URL" 2>/dev/null; then OK=1; break; fi
  sleep 3
done

if [ "$OK" != 1 ]; then
  log "health check failed, rolling back to $PREV_TARGET"
  [ -n "$PREV_TARGET" ] && switch_to "$PREV_TARGET"
  ( cd "$CURRENT" && "$PHP" artisan optimize:clear >/dev/null 2>&1; "$PHP" artisan config:cache >/dev/null 2>&1 )
  reload_services
  fail health_check "The new version did not respond, so the previous version was restored. (Database changes made by the new version are kept; they are written to be backward compatible.)" rolled_back
fi

# ---- 8. done -----------------------------------------------------------------------------------------------------
restore_maintenance
( cd "$CURRENT" && "$PHP" artisan cms:record-update "$FROM" "$VERSION" success >/dev/null 2>&1 ) || true
status success done "Updated from $FROM to $VERSION"

# Keep the newest releases for quick manual rollback; never delete the live or previous one.
ls -1dt "$APP_ROOT"/releases/*/ 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
  old="${old%/}"
  [ "$old" = "$(readlink "$CURRENT")" ] && continue
  [ "$old" = "$PREV_TARGET" ] && continue
  rm -rf "$old"
done
log "done: $FROM -> $VERSION"
