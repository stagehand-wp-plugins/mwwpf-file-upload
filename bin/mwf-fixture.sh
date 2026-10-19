#!/usr/bin/env bash
# Prepare the MW WP Form fixture used by wp-env.
#
# Usage:
#   bin/mwf-fixture.sh <version>   Download MW WP Form <version> from wordpress.org.
#   bin/mwf-fixture.sh sim52       Use 5.1.8 with the file/image fields and file
#                                  validation rules removed (simulates 5.2).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${ROOT}/tests/fixtures/mw-wp-form"
CACHE="${ROOT}/tests/fixtures/.cache"
SIM52_BASE="5.1.8"

arg="${1:-}"
if [[ -z "${arg}" ]]; then
	echo "Usage: $0 <version>|sim52" >&2
	exit 1
fi

if [[ "${arg}" == "sim52" ]]; then
	version="${SIM52_BASE}"
else
	version="${arg}"
fi

if [[ ! "${version}" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
	echo "Invalid version: ${version}" >&2
	exit 1
fi

mkdir -p "${CACHE}"
zip="${CACHE}/mw-wp-form.${version}.zip"
if [[ ! -s "${zip}" ]]; then
	curl -fsSL -o "${zip}.tmp" "https://downloads.wordpress.org/plugin/mw-wp-form.${version}.zip"
	mv "${zip}.tmp" "${zip}"
fi

# Empty the directory but keep it (it is bind-mounted into the containers).
mkdir -p "${DEST}"
find "${DEST}" -mindepth 1 -delete

tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
unzip -q "${zip}" -d "${tmp}"
cp -R "${tmp}/mw-wp-form/." "${DEST}/"

if [[ "${arg}" == "sim52" ]]; then
	rm -f \
		"${DEST}/classes/form-fields/class.file.php" \
		"${DEST}/classes/form-fields/class.image.php" \
		"${DEST}/classes/validation-rules/class.filetype.php" \
		"${DEST}/classes/validation-rules/class.filesize.php" \
		"${DEST}/classes/validation-rules/class.maximagesize.php" \
		"${DEST}/classes/validation-rules/class.minimagesize.php"
	echo "sim52 (based on ${version})" > "${DEST}/.fixture-version"
else
	echo "${version}" > "${DEST}/.fixture-version"
fi

echo "MW WP Form fixture: $(cat "${DEST}/.fixture-version")"
