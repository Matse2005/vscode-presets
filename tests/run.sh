#!/usr/bin/env bash
# Tests install.sh, the hooks and the scripts in throwaway projects; nothing outside a temp folder is touched.
# Runs locally and in CI without containers: tests/run.sh
# TEST_BASH picks the shell that runs install.sh, so CI can test macOS's bash 3.2 (TEST_BASH=/bin/bash).
set -uo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
shell=${TEST_BASH:-bash}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
passed=0
failed=0
projects=0

ok() {
    passed=$((passed + 1))
    echo "  ok   $1"
}

not_ok() {
    failed=$((failed + 1))
    echo "  FAIL $1"
    if [ -s "$work/out" ]; then sed 's/^/       | /' "$work/out" | head -20; fi
}

check() {
    local description=$1
    shift
    if "$@"; then ok "$description"; else not_ok "$description"; fi
}

# A project with a copy of the working tree (tracked and new files, not the ignored ones) as its .vscode, committed in
# a fresh git repo, so "git status" in the checkout shows exactly what install.sh leaves behind. The project's
# .gitignore stands in for the global gitignore a real machine has.
new_project() {
    projects=$((projects + 1))
    project=$work/project$projects
    mkdir -p "$project/.vscode"
    (cd "$repo" && git ls-files -z --cached --others --exclude-standard | xargs -0 tar -cf -) | tar -xf - -C "$project/.vscode"
    git -C "$project/.vscode" init -q
    git -C "$project/.vscode" add -A
    git -C "$project/.vscode" -c user.name=test -c user.email=test@example.com commit -qm checkout
    git -C "$project" init -q
    printf '.vscode/\n.php-cs-fixer.local.php\n.orodocumentation\n' > "$project/.gitignore"
}

install() {
    (cd "$project" && "$shell" .vscode/install.sh "$@") > "$work/out" 2>&1
}

set_local() {
    printf '%s\n' "$1" > "$project/.vscode/settings.local.json"
}

settings() {
    grep -v '^//' "$project/.vscode/settings.json" | jq -c "$1"
}

tasks() {
    grep -v '^//' "$project/.vscode/tasks.json" | jq -c "$1"
}

# A fingerprint of everything in .vscode except git, the install state and settings.local.json itself.
fingerprint() {
    (cd "$project/.vscode" && find . -path ./.git -prune -o -path ./.install-state -prune -o -type f ! -name settings.local.json -print \
        | LC_ALL=C sort | while IFS= read -r file; do printf '%s ' "$file"; cksum < "$file"; done) | cksum
}

exit_code() {
    local expected=$1
    shift
    install "$@"
    [ $? -eq "$expected" ]
}

refused_unchanged() {
    local before
    before=$(fingerprint)
    exit_code 1 && [ "$before" = "$(fingerprint)" ]
}

contains() {
    grep -qF -- "$2" "$1"
}

echo "install.sh with the default presets"
new_project
check "the first run exits with 0" exit_code 0
check "settings.local.json is created from the default" cmp -s "$repo/settings.local.default.json" "$project/.vscode/settings.local.json"
check "all default presets and base are resolved" contains "$work/out" "Presets: base php symfony oro kevin"
check "settings.json and tasks.json are written" test -s "$project/.vscode/settings.json" -a -s "$project/.vscode/tasks.json"
check "settings.json is valid JSON after its header" test "$(settings 'type == "object"')" = true
check "the snippets are copied with the preset as prefix" test -f "$project/.vscode/oro-yaml.code-snippets" -a -f "$project/.vscode/symfony-yaml.code-snippets" -a -f "$project/.vscode/symfony-services.code-snippets"
check "no snippet or generated file is a symlink" test -z "$(find "$project/.vscode" -path "$project/.vscode/.git" -prune -o -type l -print)"
check "the runner is split into words in the LSP command" test "$(settings '.["symfonyLsp.phpCommand"][0:3]')" = '["kevin","app","php"]'
check "the oro preset prepends oro-dotenv.php" contains "$project/.vscode/settings.json" "auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php"
check "the oro preset appends to the php excludes" test "$(settings '.["intelephense.files.exclude"] | (index("**/.git/**") != null) and (index("**/public/bundles/**") != null)')" = true
check "vscodePresets keys stay out of settings.json" test "$(settings '[keys[] | select(startswith("vscodePresets."))] | length')" = 0
check "the tasks of all presets are there" test "$(tasks '[.tasks[].label] | sort')" = '["Create php-cs-fixer local config","Link Oro documentation","Regenerate YAML schemas","Restart Kevin with a clean cache","Sync vscode-presets"]'
check "the sync task runs on folder open" test "$(tasks '.tasks[] | select(.label == "Sync vscode-presets") | .runOptions.runOn')" = '"folderOpen"'
check "no generated file contains the project path" sh -c "! grep -rqF '$project' '$project/.vscode/settings.json' '$project/.vscode/tasks.json' '$project/.vscode/'*.code-snippets"
check "no generated file contains the home folder" sh -c "! grep -rqF '$HOME' '$project/.vscode/settings.json' '$project/.vscode/tasks.json'"
check "~ in the documentation path is not expanded" test "$(tasks '.tasks[] | select(.label == "Link Oro documentation") | .options.env.ORO_DOCUMENTATION')" = '"~/Work/orodocumentation"'
check "git in the checkout sees nothing install.sh wrote" test -z "$(git -C "$project/.vscode" status --porcelain)"
before=$(fingerprint)
check "a second run exits with 0" exit_code 0
check "a second run changes nothing" test "$before" = "$(fingerprint)"
check "a second run reports no updates" sh -c "! grep -q Updated '$work/out'"

echo "settings.local.json"
new_project
install
set_local '{"vscodePresets.enabled": ["php"], "vscodePresets.runner": "  ddev   exec ", "intelephense.files.exclude+": ["**/build/**"], "php-cs-fixer.onsave": false}'
check "a custom runner and overrides install" exit_code 0
check "the runner is normalised" test "$(settings '.["phpunit.command"]')" = '"ddev exec php ${phpargs} ${phpunit} ${phpunitargs}"'
check "a key ending in + appends to the preset list" test "$(settings '.["intelephense.files.exclude"][-1]')" = '"**/build/**"'
check "a local key overrides a preset value" test "$(settings '.["php-cs-fixer.onsave"]')" = false
check "disabling presets removes their snippet copies" test ! -e "$project/.vscode/oro-yaml.code-snippets" -a ! -e "$project/.vscode/symfony-yaml.code-snippets"
check "disabling presets removes their settings" test "$(settings 'has("symfonyLsp.phpCommand") or has("yaml.schemas")')" = false

echo "refusals that leave .vscode unchanged"
new_project
install
set_local '{"vscodePresets.enabled": ["oro"] // comment
}'
check "a comment in settings.local.json" refused_unchanged
set_local '{"vscodePresets.enabled": "oro"}'
check "enabled as a string" refused_unchanged
set_local '{"vscodePresets.enabled": ["../oro"]}'
check "a preset name with a path" refused_unchanged
set_local '{"vscodePresets.enabled": ["unknown"]}'
check "an unknown preset" refused_unchanged
set_local '{"vscodePresets.enabled": ["php"]}'
check "a missing runner" refused_unchanged
set_local '{"vscodePresets.enabled": ["php"], "vscodePresets.runner": "   "}'
check "a runner of only spaces" refused_unchanged
set_local '{"vscodePresets.enabled": ["oro", "kevin"], "intelephense.files.exclude+": "not a list"}'
check "a key ending in + that is not a list" refused_unchanged
mkdir -p "$project/.vscode/presets/cycle-a" "$project/.vscode/presets/cycle-b"
echo '{"requires": ["cycle-b"]}' > "$project/.vscode/presets/cycle-a/preset.json"
echo '{"requires": ["cycle-a"]}' > "$project/.vscode/presets/cycle-b/preset.json"
set_local '{"vscodePresets.enabled": ["cycle-a"]}'
check "presets that require each other" refused_unchanged
check "the cycle is named in the message" contains "$work/out" "cycle"

echo "hand edits"
new_project
install
printf '\n// my change\n' >> "$project/.vscode/settings.json"
check "a hand-edited settings.json is refused" exit_code 1
check "the hand edit is kept" contains "$project/.vscode/settings.json" "// my change"
check "the refusal shows a diff" contains "$work/out" "changed since the last install"
check "--force overwrites the hand edit" exit_code 0 --force
check "the hand edit is gone after --force" sh -c "! grep -q 'my change' '$project/.vscode/settings.json'"
printf '\n' >> "$project/.vscode/oro-yaml.code-snippets"
set_local '{"vscodePresets.enabled": ["symfony", "kevin"]}'
install
check "an edited snippet copy of a disabled preset is kept" test -f "$project/.vscode/oro-yaml.code-snippets"
check "keeping it is reported" contains "$work/out" "Kept .vscode/oro-yaml.code-snippets"

echo "hooks and options"
new_project
install
chmod -x "$project/.vscode/presets/php/hooks/install.sh"
check "a hook that is not executable fails the install" exit_code 1
check "the skipped hook is reported" contains "$work/out" "it is not executable"
chmod +x "$project/.vscode/presets/php/hooks/install.sh"
check "the php hook leaves .php-cs-fixer.local.php alone without .php-cs-fixer.php" test ! -e "$project/.php-cs-fixer.local.php"
touch "$project/.php-cs-fixer.php"
install
check "the php hook creates .php-cs-fixer.local.php" contains "$project/.php-cs-fixer.local.php" "return require __DIR__.'/.php-cs-fixer.php';"
set_local '{"vscodePresets.enabled": ["oro"], "vscodePresets.runner": "echo"}'
install --schemas
check "--schemas runs the oro hook through the runner: generator" contains "$work/out" "/app/.vscode/presets/oro/schemas/generator/generate.php"
check "--schemas runs the oro hook through the runner: validator" contains "$work/out" "/app/.vscode/presets/oro/schemas/generator/validate.php"
check "an unknown option exits with 2" exit_code 2 --unknown
mkdir -p "$work/elsewhere"
cp -R "$project/.vscode/." "$work/elsewhere/"
check "install.sh refuses to run outside a .vscode folder" sh -c "cd '$project' && ! '$shell' '$work/elsewhere/install.sh' > '$work/out' 2>&1"

echo "scripts"
mkdir -p "$work/home/docs" "$work/docs-project"
link=$project/.vscode/presets/oro/scripts/link-documentation.sh
check "link-documentation.sh asks for the setting when it is empty" sh -c "ORO_DOCUMENTATION= '$link' '$work/docs-project' > '$work/out' 2>&1; [ \$? -eq 1 ] && grep -q vscodePresets.oroDocumentation '$work/out'"
check "link-documentation.sh expands ~ when it runs" sh -c "HOME='$work/home' ORO_DOCUMENTATION='~/docs' '$link' '$work/docs-project' > '$work/out' 2>&1 && [ \"\$(readlink '$work/docs-project/.orodocumentation')\" = '$work/home/docs' ]"
mkdir -p "$work/not-a-project/var/cache"
touch "$work/not-a-project/var/cache/keep"
check "start-kevin.sh refuses a folder without bin/console" sh -c "! '$project/.vscode/presets/kevin/scripts/start-kevin.sh' '$work/not-a-project' > '$work/out' 2>&1 && [ -f '$work/not-a-project/var/cache/keep' ]"

echo
echo "$passed passed, $failed failed"
[ "$failed" -eq 0 ]
