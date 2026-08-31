#!/usr/bin/env bash
# Regenerate the .pot template for flexa-smtp.
#
# Requires WP-CLI and Node. WP-CLI's bundled i18n-command only parses
# .js/.jsx, so scripts/extract-tsx-i18n.mjs first walks the .tsx/.ts React
# source and emits a temporary JS shim of every __()/_x()/_n() call (with
# the textdomain appended) that `wp i18n make-pot` can scan statically. No
# `pnpm build` is needed — strings come from source, not the bundle.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POT_FILE="${ROOT_DIR}/i18n/languages/flexa-smtp.pot"
SHIM_FILE="${ROOT_DIR}/i18n/extracted-strings.js"

mkdir -p "${ROOT_DIR}/i18n/languages"

node "${ROOT_DIR}/scripts/extract-tsx-i18n.mjs" \
    "${ROOT_DIR}/apps/admin/src" \
    "${SHIM_FILE}"

wp i18n make-pot \
    "${ROOT_DIR}" \
    "${POT_FILE}" \
    --slug="flexa-smtp" \
    --domain="flexa-smtp" \
    --exclude="vendor,node_modules,build,apps/admin/node_modules,assets/dist/*.map"

if command -v msgfmt >/dev/null 2>&1; then
    msgfmt --check --output-file=/dev/null "${POT_FILE}"
fi

rm -f "${SHIM_FILE}"

echo "Wrote ${POT_FILE}"
