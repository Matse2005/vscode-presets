#!/usr/bin/env bash
# Runs on every sync, in the project root.
set -euo pipefail

# .php-cs-fixer.local.php returns .php-cs-fixer.php; without that file the on-save fixer would fail.
if [ -f .php-cs-fixer.php ] && [ ! -f .php-cs-fixer.local.php ]; then
    "$VSCODE_PRESETS_REPO/presets/php/scripts/setup-php-cs-fixer.sh" "$PWD"
fi
if [ -f .php-cs-fixer.local.php ] && git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
    && ! git check-ignore -q .php-cs-fixer.local.php; then
    echo "Warning: .php-cs-fixer.local.php is not ignored by git here. Add it to your global gitignore." >&2
fi
