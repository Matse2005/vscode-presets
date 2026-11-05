#!/usr/bin/env bash
# Runs on install.sh --check, in the project root. Reports only; changes nothing.
set -uo pipefail
# shellcheck source=presets/base/scripts/check-lib.sh
. "$VSCODE_PRESETS_REPO/presets/base/scripts/check-lib.sh"

if command -v kevin >/dev/null; then
    ok "kevin is on the PATH"
else
    fail "kevin is not on the PATH"
fi

mode=$(sed -n 's/^XDEBUG_MODE=//p' .env.kevin.local 2>/dev/null | tail -1 | tr -d '"'"'"'\r')
case ${mode:-off} in
    off) ok "Xdebug is off (XDEBUG_MODE=debug in .env.kevin.local and kevin recreate turn it on)" ;;
    *) ok "Xdebug mode in .env.kevin.local: $mode (it slows requests down; set it back to off when done)" ;;
esac

finish
