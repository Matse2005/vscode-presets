#!/usr/bin/env bash
# The junstyle.php-cs-fixer extension (0.3.21) reports a run that changed nothing as an error when stderr has more than
# one line, and php-cs-fixer 3 prints several informational lines there. On success only the first line is passed on;
# on failure all of stderr is, so real errors still reach the output panel.
set -uo pipefail

# The extension runs this from the directory of the file it formats, so the project is found from this script.
project=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
errors=$(mktemp)
trap 'rm -f "$errors"' EXIT

"$project/bin/php-cs-fixer" "$@" 2> "$errors"
status=$?

if [ "$status" -eq 0 ]; then
    head -n 1 "$errors" >&2
else
    cat "$errors" >&2
fi
exit "$status"
