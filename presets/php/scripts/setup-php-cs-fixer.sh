#!/usr/bin/env bash
set -euo pipefail

# Runs in the project root: VS Code starts tasks there, and install.sh passes the project as the first argument.
cd "${1:-$PWD}"

if [ -f .php-cs-fixer.local.php ]; then
    echo ".php-cs-fixer.local.php already exists, leaving it untouched"
    exit 0
fi

echo "Writing .php-cs-fixer.local.php"
cat > .php-cs-fixer.local.php <<'PHP'
<?php

declare(strict_types=1);

// VS Code runs the fixer from a directory other than the project root, so getcwd() points
// elsewhere. The dist config resolves its paths from __DIR__, which keeps on-save fixing
// identical to what CI enforces.
chdir(__DIR__);

return require __DIR__.'/.php-cs-fixer.php';
PHP
