#!/usr/bin/env bash
# Builds the installable release archive: tracked files + production vendor/ + compiled assets, no dev tooling.
# Servers then need neither Composer nor Node to update (only PHP).
#
#   scripts/build-release.sh <version> [output-dir]
# Produces <output-dir>/minicms-v<version>.tar.gz and a .sha256. Signing is a separate step (scripts/sign-release.php).
set -euo pipefail

VERSION="${1:?usage: build-release.sh <version> [output-dir]}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "version must look like 1.2.3" >&2; exit 2; }
ROOT="$(git rev-parse --show-toplevel)"
OUT="$(mkdir -p "${2:-$ROOT/dist}" && cd "${2:-$ROOT/dist}" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cd "$ROOT"
git archive --format=tar HEAD | tar -x -C "$WORK"      # tracked files only: no .env, uploads or local build output
cd "$WORK"
echo "$VERSION" > VERSION

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet
npm ci --silent
VITE_APP_NAME="${VITE_APP_NAME:-Mini CMS}" npm run build --silent
VITE_APP_NAME="${VITE_APP_NAME:-Mini CMS}" npm run build:ssr --silent

# Development-only material never ships.
rm -rf node_modules tests .github resources/js resources/css database/factories \
       package.json package-lock.json vite.config.js vitest.config.ts eslint.config.js tsconfig.json components.json phpunit.xml \
       .prettierrc .prettierignore .editorconfig .gitattributes public/hot

MIN_PHP="$(php -r '$c = json_decode(file_get_contents("composer.json"), true); preg_match("/(\d+\.\d+)/", $c["require"]["php"], $m); echo $m[1];')"
cat > manifest.json <<JSON
{"version":"$VERSION","min_php":"$MIN_PHP","built_at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)"}
JSON

ARCHIVE="$OUT/minicms-v$VERSION.tar.gz"
tar -czf "$ARCHIVE" -C "$WORK" .
php -r 'echo hash_file("sha256", $argv[1]), PHP_EOL;' "$ARCHIVE" > "$ARCHIVE.sha256"
echo "built $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
