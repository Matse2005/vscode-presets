#!/usr/bin/env bash
# Runs on install.sh --schemas, in the project root. Needs the containers running.
set -euo pipefail

read -r -a run <<< "$VSCODE_PRESETS_RUNNER"
dir=/app/.vscode/presets/oro
"${run[@]}" php -d memory_limit=2G -d auto_prepend_file=$dir/oro-dotenv.php $dir/schemas/generator/generate.php
"${run[@]}" php -d memory_limit=4G $dir/schemas/generator/validate.php
