#!/usr/bin/env bash
# Checks the files of the repo without installing anything: JSON, PHP syntax and shellcheck. In CI (CI=true) a
# missing tool is an error; locally it is skipped with a notice.
set -uo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo" || exit 1
failed=0

tool() {
    command -v "$1" >/dev/null && return 0
    if [ "${CI:-}" = true ]; then
        echo "FAIL $1 is not installed"
        failed=1
    else
        echo "skip $1 is not installed"
    fi
    return 1
}

files() {
    git ls-files --cached --others --exclude-standard -- "$@"
}

if tool jq; then
    while IFS= read -r file; do
        jq empty "$file" 2>/dev/null || { echo "FAIL invalid JSON: $file"; failed=1; }
    done < <(files '*.json' '*.code-snippets')
    echo "done JSON"
fi

if tool php; then
    while IFS= read -r file; do
        php -l "$file" >/dev/null 2>&1 || { php -l "$file"; failed=1; }
    done < <(files '*.php')
    echo "done PHP syntax"
fi

if tool shellcheck; then
    scripts=()
    while IFS= read -r file; do scripts+=("$file"); done < <(files '*.sh')
    shellcheck --severity=warning "${scripts[@]}" || failed=1
    echo "done shellcheck"
fi

# Every script that tasks or install.sh run must be executable in the repo itself, not only on this machine.
while IFS= read -r file; do
    mode=$(git ls-files --stage -- "$file" | cut -c1-6)
    [ -z "$mode" ] || [ "$mode" = 100755 ] || { echo "FAIL not executable in git: $file"; failed=1; }
done < <(files '*.sh')
echo "done executable bits"

exit $failed
