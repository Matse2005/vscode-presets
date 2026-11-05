#!/usr/bin/env bash
# Runs on install.sh --check, in the project root. Reports only; changes nothing.
set -uo pipefail
# shellcheck source=presets/base/scripts/check-lib.sh
. "$VSCODE_PRESETS_REPO/presets/base/scripts/check-lib.sh"

if [ -f .php-cs-fixer.php ]; then
    if [ -f .php-cs-fixer.local.php ]; then
        ok "php-cs-fixer on save has .php-cs-fixer.local.php"
        git_ignores .php-cs-fixer.local.php || fail ".php-cs-fixer.local.php is not ignored by git; add it to the global gitignore"
    else
        fail "php-cs-fixer on save needs .php-cs-fixer.local.php; run \"Create php-cs-fixer local config\" or the sync"
    fi
else
    ok "no .php-cs-fixer.php, so php-cs-fixer on save is not used here"
fi

if [ -f bin/phpunit ]; then
    ok "PHPUnit at bin/phpunit"
elif [ -f vendor/bin/phpunit ]; then
    warn "PHPUnit is at vendor/bin/phpunit; set \"phpunit.phpunit\": \"vendor/bin/phpunit\" in settings.local.json"
else
    warn "no bin/phpunit or vendor/bin/phpunit; the PHPUnit extension has nothing to run (composer install?)"
fi

finish
