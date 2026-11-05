#!/usr/bin/env bash
# Runs on install.sh --schemas, in the project root, after the symfony hook. Needs the containers running.
set -euo pipefail

read -r -a run <<< "$VSCODE_PRESETS_RUNNER"
dir=/app/.vscode/presets
"${run[@]}" php -d memory_limit=2G -d auto_prepend_file=$dir/oro/oro-dotenv.php $dir/oro/schemas/generator/generate.php
"${run[@]}" env 'ONLY=oro-*' php -d memory_limit=4G $dir/symfony/schemas/generator/validate.php
