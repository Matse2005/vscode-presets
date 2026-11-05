#!/usr/bin/env bash
# Stops Kevin, clears var/cache and starts Kevin again. Takes the project root; the task passes ${workspaceFolder}.
set -euo pipefail

cd "${1:?project root}"
# var/cache is emptied below, so make sure this really is a Symfony project root.
[ -f bin/console ] || { echo "No bin/console in $PWD, not a project root; nothing was changed." >&2; exit 1; }

# Kevin echoes the compose command it runs, so match container ids only.
if kevin ps --status running -q 2>/dev/null | grep -qE '^[0-9a-f]{64}$'; then
    echo "Stopping running containers"
    kevin stop
fi

echo "Clearing cache"
if [ -d var/cache ]; then find var/cache -mindepth 1 -delete; fi

echo "Starting up docker"
kevin up
