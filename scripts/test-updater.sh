#!/usr/bin/env bash
# End-to-end rehearsal of the updater in a throwaway sandbox (no real server, no network).
# It installs a release as "current", then drives scripts/apply-update.sh through success, rejection and rollback.
#
#   scripts/test-updater.sh <built-release.tar.gz> <signing-key-file> [database-name]
# The database is only used for migrations, backup and the maintenance switch: point it at a disposable one
# (default mini_cms_test). Needs php + sodium, curl, tar, python3 and a configured local .env for DB credentials.
set -uo pipefail

ARCHIVE="$(cd "$(dirname "${1:?built release archive}")" && pwd)/$(basename "$1")"
KEY_FILE="${2:?signing key file}"
DB="${3:-mini_cms_test}"
ROOT="$(git rev-parse --show-toplevel)"
SB="$(mktemp -d)"; PORT=8765
FAILS=0
trap 'kill $SERVER 2>/dev/null; rm -rf "$SB"' EXIT

ok()   { echo "  ok    $*"; }
bad()  { echo "  FAIL  $*"; FAILS=$((FAILS + 1)); [ -n "${LAST_LOG:-}" ] && { echo "        --- output:"; sed 's/^/        /' "$LAST_LOG" | tail -n 12; echo "        --- status: $(php -r '$s = json_decode(@file_get_contents($argv[1]), true); echo ($s["step"] ?? "?") . ": " . ($s["message"] ?? "");' "$SB/shared/storage/app/updates/status.json")"; }; }
expect() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

# --- sandbox install: releases/0.8.5 is live ---------------------------------------------------------------------
mkdir -p "$SB/releases/0.8.5" "$SB/shared" "$SB/web/repos/matiuskm/minicms/releases/tags" "$SB/web/dl"
tar -xzf "$ARCHIVE" -C "$SB/releases/0.8.5"
echo 0.8.5 > "$SB/releases/0.8.5/VERSION"
cp -R "$SB/releases/0.8.5/storage" "$SB/shared/storage"; rm -rf "$SB/releases/0.8.5/storage"; ln -s "$SB/shared/storage" "$SB/releases/0.8.5/storage"
mkdir -p "$SB/shared/storage/app/updates" "$SB/shared/storage/app/public" "$SB/shared/storage/framework/"{cache,sessions,views} "$SB/shared/storage/logs"
grep -vE '^(DB_DATABASE|APP_URL|CACHE_STORE|SESSION_DRIVER|QUEUE_CONNECTION|MAIL_MAILER)=' "$ROOT/.env" > "$SB/shared/.env"
printf 'DB_DATABASE=%s\nAPP_URL=http://127.0.0.1:%s\nCACHE_STORE=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\nMAIL_MAILER=array\n' "$DB" "$PORT" >> "$SB/shared/.env"
ln -s "$SB/shared/.env" "$SB/releases/0.8.5/.env"
ln -s "$SB/releases/0.8.5" "$SB/current"
echo ok > "$SB/web/up"
export APP_ROOT="$SB" CMS_UPDATE_API="http://127.0.0.1:$PORT" CMS_HEALTH_URL="http://127.0.0.1:$PORT/up" CMS_RELOAD_CMD="true"
APPLY="$SB/current/scripts/apply-update.sh"

# --- fake release server: the same JSON shape GitHub returns --------------------------------------------------------
publish() { # version archive-file   (signs with the real key unless SIGN_WITH is set)
  local v="$1" f="$2" key="${SIGN_WITH:-$(cat "$KEY_FILE")}"
  cp "$f" "$SB/web/dl/minicms-v$v.tar.gz"
  php "$ROOT/scripts/sign-release.php" "$f" "$key" > "$SB/web/dl/minicms-v$v.tar.gz.sig"
  cat > "$SB/web/repos/matiuskm/minicms/releases/tags/v$v" <<JSON
{"tag_name":"v$v","assets":[
 {"name":"minicms-v$v.tar.gz","browser_download_url":"http://127.0.0.1:$PORT/dl/minicms-v$v.tar.gz"},
 {"name":"minicms-v$v.tar.gz.sig","browser_download_url":"http://127.0.0.1:$PORT/dl/minicms-v$v.tar.gz.sig"}]}
JSON
}
cd "$SB/web" && { python3 -m http.server "$PORT" --bind 127.0.0.1 >/dev/null 2>&1 & SERVER=$!; }; cd "$ROOT"
sleep 1

request() { echo "{\"version\":\"$1\",\"requested_by\":\"test\"}" > "$SB/shared/storage/app/updates/request.json"; }
state()   { php -r '$s = json_decode(file_get_contents($argv[1]), true); echo $s["state"] ?? "none";' "$SB/shared/storage/app/updates/status.json"; }
live()    { tr -d '[:space:]' < "$SB/current/VERSION"; }
mkrelease() { # version -> path of a freshly re-versioned archive
  local d; d="$(mktemp -d)"; tar -xzf "$ARCHIVE" -C "$d"; echo "$1" > "$d/VERSION"; tar -czf "$SB/rel-$1.tar.gz" -C "$d" .; rm -rf "$d"; echo "$SB/rel-$1.tar.gz"
}

echo "1. no request: nothing happens"
"$APPLY" >/dev/null 2>&1; expect "still on 0.8.5" '[ "$(live)" = 0.8.5 ]'

echo "2. a genuine signed newer release is installed"
publish 0.9.0 "$(mkrelease 0.9.0)"
request 0.9.0; "$APPLY" >"$SB/run2.log" 2>&1; LAST_LOG="$SB/run2.log"
expect "current now points at 0.9.0" '[ "$(live)" = 0.9.0 ]'
expect "status is success" '[ "$(state)" = success ]'
expect "request file was consumed" '[ ! -f "$SB/shared/storage/app/updates/request.json" ]'
expect "the new release shares .env and storage" '[ "$(readlink "$SB/current/storage")" = "$SB/shared/storage" ] && [ -L "$SB/current/.env" ]'
expect "a backup was taken before switching" 'ls "$SB/shared/storage/app/backups"/*-db.sql.gz >/dev/null 2>&1'
expect "maintenance mode was switched back off" '( cd "$SB/current" && php artisan cms:maintenance off ) | grep -q was-off'
expect "the old release is kept for rollback" '[ -d "$SB/releases/0.8.5" ]'

echo "3. a tampered archive is rejected and nothing changes"
BAD="$(mkrelease 0.9.1)"; publish 0.9.1 "$BAD"; printf 'x' >> "$SB/web/dl/minicms-v0.9.1.tar.gz"
request 0.9.1; "$APPLY" >"$SB/run3.log" 2>&1
expect "still on 0.9.0" '[ "$(live)" = 0.9.0 ]'
expect "status is failed at verification" '[ "$(state)" = failed ] && grep -q verifying "$SB/shared/storage/app/updates/status.json"'
expect "no release directory was activated" '[ ! -d "$SB/releases/0.9.1" ] || [ "$(live)" = 0.9.0 ]'

echo "4. an archive signed with a different key is rejected"
OTHER="$(php -r '$kp = sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($kp));')"
SIGN_WITH="$OTHER" publish 0.9.2 "$(mkrelease 0.9.2)"
request 0.9.2; "$APPLY" >"$SB/run4.log" 2>&1
expect "still on 0.9.0" '[ "$(live)" = 0.9.0 ]'
expect "rejected for the signature" 'grep -q "signature does not match" "$SB/shared/storage/app/updates/status.json"'

echo "5. a downgrade or the same version is refused"
request 0.8.0; "$APPLY" >/dev/null 2>&1
expect "still on 0.9.0 after a downgrade request" '[ "$(live)" = 0.9.0 ] && [ "$(state)" = failed ]'
request 0.9.0; "$APPLY" >/dev/null 2>&1
expect "still on 0.9.0 after a same-version request" '[ "$(live)" = 0.9.0 ]'

echo "6. a garbage request is ignored safely"
echo '{"version":"1.0.0; rm -rf /"}' > "$SB/shared/storage/app/updates/request.json"; "$APPLY" >/dev/null 2>&1
expect "nothing happened" '[ "$(live)" = 0.9.0 ] && [ "$(state)" = failed ]'

echo "7. a release that fails its health check is rolled back"
publish 1.0.0 "$(mkrelease 1.0.0)"
request 1.0.0; CMS_HEALTH_URL="http://127.0.0.1:9/never" "$APPLY" >"$SB/run7.log" 2>&1
expect "current went back to 0.9.0" '[ "$(live)" = 0.9.0 ]'
expect "status is failed" '[ "$(state)" = failed ]'
expect "maintenance mode was switched back off" '( cd "$SB/current" && php artisan cms:maintenance off ) | grep -q was-off'

echo "8. the same release installs once the problem is gone"
request 1.0.0; "$APPLY" >"$SB/run8.log" 2>&1
expect "now on 1.0.0" '[ "$(live)" = 1.0.0 ] && [ "$(state)" = success ]'
expect "update outcomes were audited" '( cd "$SB/current" && php artisan tinker --execute="echo App\\Models\\AuditLog::where(\"action\", \"like\", \"update.%\")->count();" ) | tail -n1 | grep -qE "^[2-9]"'

echo
[ "$FAILS" = 0 ] && echo "ALL UPDATER CHECKS PASSED" || echo "$FAILS CHECK(S) FAILED"
exit "$FAILS"
