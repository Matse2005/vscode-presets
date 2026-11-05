#!/usr/bin/env bash
# Runs on install.sh --schemas, in the project root. Needs the containers running.
set -euo pipefail

read -r -a run <<< "$VSCODE_PRESETS_RUNNER"
dir=/app/.vscode/presets/symfony/schemas/generator
"${run[@]}" php -d memory_limit=2G $dir/generate.php
"${run[@]}" env 'ONLY=symfony-*' php -d memory_limit=4G $dir/validate.php
