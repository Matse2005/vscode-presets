#!/usr/bin/env bash
# Output helpers for install.sh --check and the hooks/check.sh of the presets. Source it, report with ok, warn and fail,
# and end a check hook with finish: it exits with 1 when something failed.
checks_failed=0

ok() {
    echo "  ok    $1"
}

warn() {
    echo "  warn  $1"
}

fail() {
    echo "  FAIL  $1"
    checks_failed=1
}

finish() {
    exit "$checks_failed"
}

# True when the project's git exists and ignores the path, or when there is no git to see it at all.
git_ignores() {
    ! git rev-parse --is-inside-work-tree >/dev/null 2>&1 || git check-ignore -q "$1"
}
