#!/usr/bin/env bash
# Runs on install.sh --check, in the project root. Reports only; changes nothing. See docs/troubleshooting.md.
set -uo pipefail
# shellcheck source=presets/base/scripts/check-lib.sh
. "$VSCODE_PRESETS_REPO/presets/base/scripts/check-lib.sh"

prepend=/app/.vscode/presets/oro/oro-dotenv.php
if grep -q "auto_prepend_file=$prepend" .vscode/settings.json 2>/dev/null; then
    ok "the Symfony LSP command prepends oro-dotenv.php"
else
    fail "the Symfony LSP command does not prepend oro-dotenv.php; run the sync"
fi

# An empty class map is the header of 32 bytes; see "Empty extend class map" in troubleshooting.
map=var/cache/dev/oro_entities/Extend/Entity/classes.php
if [ ! -f "$map" ]; then
    warn "no $map yet; it appears after the first cache warmup"
elif [ "$(wc -c < "$map" | tr -d ' ')" -le 64 ]; then
    fail "$map is empty ($(wc -c < "$map" | tr -d ' ') bytes): the extend class map problem is back, see troubleshooting"
else
    ok "the extend class map has $(wc -c < "$map" | tr -d ' ') bytes"
fi

# The script comes from stdin, so nothing is written into the project; -r would ignore the prepend.
if [ -n "${VSCODE_PRESETS_RUNNER:-}" ]; then
    read -r -a run <<< "$VSCODE_PRESETS_RUNNER"
    result=$(printf '<?php echo isset($_SERVER["ORO_DB_URL"]) ? "loaded" : "missing";' \
        | "${run[@]}" php -d auto_prepend_file=$prepend 2>/dev/null | tr -d '\r' | tail -1)
    case $result in
        loaded) ok "the prepend loads the Oro env in the container (ORO_DB_URL is set)" ;;
        missing) fail "the prepend does not load the Oro env in the container (ORO_DB_URL is missing)" ;;
        *) warn "could not check the Oro env in the container (containers not running?)" ;;
    esac
fi

finish
