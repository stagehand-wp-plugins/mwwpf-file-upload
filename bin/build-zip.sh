#!/usr/bin/env bash
# Build the distributable zip at dist/<slug>.zip.
# The top-level folder inside the zip is always <slug>/.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="mwwpf-file-upload"
DIST="${ROOT}/dist"

work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT
stage="${work}/${SLUG}"
mkdir -p "${stage}"

# Copy plugin files, skipping .distignore entries and the dev vendor directory.
rsync -a --exclude-from="${ROOT}/.distignore" --exclude='/vendor' --exclude='.DS_Store' "${ROOT}/" "${stage}/"

# Install production dependencies only.
cp "${ROOT}/composer.json" "${ROOT}/composer.lock" "${stage}/"
composer install --working-dir="${stage}" --no-dev --optimize-autoloader --no-interaction --quiet
rm -f "${stage}/composer.json" "${stage}/composer.lock"

mkdir -p "${DIST}"
rm -f "${DIST}/${SLUG}.zip"
(cd "${work}" && zip -qr "${DIST}/${SLUG}.zip" "${SLUG}")

echo "Built ${DIST}/${SLUG}.zip"
