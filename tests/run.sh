#!/usr/bin/env bash
# Tests install.sh, the hooks and the scripts in throwaway projects; nothing outside a temp folder is touched.
# Runs locally and in CI without containers: tests/run.sh
# TEST_BASH picks the shell that runs install.sh, so CI can test macOS's bash 3.2 (TEST_BASH=/bin/bash). On Windows it
# runs in Git Bash.
set -uo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
shell=${TEST_BASH:-bash}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
passed=0
failed=0
skipped=0
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

# Only for what the platform cannot do at all, such as removing the execute bit in Git Bash on Windows.
skip() {
    skipped=$((skipped + 1))
    echo "  skip $1"
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

# jq on Windows can end its output with \r.
settings() {
    grep -v '^//' "$project/.vscode/settings.json" | jq -c "$1" | tr -d '\r'
}

tasks() {
    grep -v '^//' "$project/.vscode/tasks.json" | jq -c "$1" | tr -d '\r'
}

extensions() {
    grep -v '^//' "$project/.vscode/extensions.json" | jq -c "$1" | tr -d '\r'
}

launch() {
    grep -v '^//' "$project/.vscode/launch.json" | jq -c "$1" | tr -d '\r'
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
check "the tasks of all presets are there" test "$(tasks '[.tasks[].label] | sort')" = '["Create php-cs-fixer local config","Link Oro documentation","Regenerate YAML schemas","Restart Kevin with a clean cache","Sync vscode-presets","Update vscode-presets"]'
check "the update task pulls fast-forward only and installs" test "$(tasks '.tasks[] | select(.label == "Update vscode-presets") | .command | test("pull --ff-only && .*/install.sh")')" = true
check "extensions.json recommends the extensions of all presets" test "$(extensions '.recommendations')" = '["esbenp.prettier-vscode","junstyle.php-cs-fixer","bmewburn.vscode-intelephense-client","recca0120.vscode-phpunit","xdebug.php-debug","symfony.language-tools","redhat.vscode-yaml"]'
check "launch.json listens for Xdebug with /app mapped to the project" test "$(launch '.configurations[0] | [.type, .port, .pathMappings["/app"]]')" = '["php",9003,"${workspaceFolder}"]'
check "the symfony and oro schemas are mapped from their own presets" test "$(settings '."yaml.schemas" | keys | map(split("/")[3]) | unique')" = '["oro","symfony"]'
check "Oro adds its own files to the symfony config schema" test "$(settings '."yaml.schemas"["./.vscode/presets/symfony/schemas/generated/symfony-config.schema.json"] | index("**/Resources/config/oro/app.yml") != null')" = true
check "the sync task runs on folder open" test "$(tasks '.tasks[] | select(.label == "Sync vscode-presets") | .runOptions.runOn')" = '"folderOpen"'
check "no generated file contains the project path" sh -c "! grep -rqF '$project' '$project/.vscode/'*.json '$project/.vscode/'*.code-snippets"
check "no generated file contains the home folder" sh -c "! grep -qF '$HOME' '$project/.vscode/settings.json' '$project/.vscode/tasks.json' '$project/.vscode/extensions.json' '$project/.vscode/launch.json'"
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
check "disabling presets removes their extension recommendations" test "$(extensions '.recommendations | index("redhat.vscode-yaml")')" = null
set_local '// presets for this project
{
  // only php here
  "vscodePresets.enabled": ["php"],
  "vscodePresets.runner": "ddev exec",
  "vscodePresets.extensions": ["xdebug.php-debug", "eamodio.gitlens"],
  "vscodePresets.launch": [{"name": "Mine", "type": "php", "request": "launch", "port": 9010}]
}'
check "whole-line comments in settings.local.json are allowed" exit_code 0
check "vscodePresets.extensions adds recommendations once" test "$(extensions '.recommendations | [length, (map(select(. == "xdebug.php-debug")) | length), .[-1]]')" = '[6,1,"eamodio.gitlens"]'
check "vscodePresets.launch adds a configuration after the presets" test "$(launch '[.configurations[].name]')" = '["Listen for Xdebug in the container","Mine"]'
check "vscodePresets.extensions and launch stay out of settings.json" test "$(settings '[keys[] | select(startswith("vscodePresets"))] | length')" = 0

echo "a Symfony project without Oro"
new_project
set_local '{"vscodePresets.enabled": ["symfony", "kevin"]}'
check "symfony and kevin install" exit_code 0
check "the symfony schemas map .yaml files too" test "$(settings '."yaml.schemas"["./.vscode/presets/symfony/schemas/generated/symfony-routing.schema.json"] | index("config/routes/**/*.yaml") != null')" = true
check "no Oro schema is mapped" test "$(settings '[."yaml.schemas" | keys[] | select(contains("/oro/"))] | length')" = 0
check "no Oro prepend in the LSP command" sh -c "! grep -q oro-dotenv '$project/.vscode/settings.json'"
set_local '{"vscodePresets.enabled": ["symfony"], "vscodePresets.runner": "echo"}'
install --schemas
check "--schemas runs only the symfony generator" sh -c "grep -q /app/.vscode/presets/symfony/schemas/generator/generate.php '$work/out' && ! grep -q /oro/ '$work/out'"

echo "refusals that leave .vscode unchanged"
new_project
install
set_local '{"vscodePresets.enabled": ["oro"] // a comment after a value
}'
check "a comment after a value in settings.local.json" refused_unchanged
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
if [ -x "$project/.vscode/presets/php/hooks/install.sh" ]; then
    skip "a hook that is not executable fails the install (this file system has no execute bit)"
else
    check "a hook that is not executable fails the install" exit_code 1
    check "the skipped hook is reported" contains "$work/out" "it is not executable"
fi
chmod +x "$project/.vscode/presets/php/hooks/install.sh"
check "the php hook leaves .php-cs-fixer.local.php alone without .php-cs-fixer.php" test ! -e "$project/.php-cs-fixer.local.php"
touch "$project/.php-cs-fixer.php"
install
check "the php hook creates .php-cs-fixer.local.php" contains "$project/.php-cs-fixer.local.php" "return require __DIR__.'/.php-cs-fixer.php';"
set_local '{"vscodePresets.enabled": ["oro"], "vscodePresets.runner": "echo"}'
install --schemas
check "--schemas runs the symfony generator" contains "$work/out" "/app/.vscode/presets/symfony/schemas/generator/generate.php"
check "--schemas runs the oro generator after it" sh -c "grep -n generate.php '$work/out' | head -1 | grep -q symfony && grep -q /app/.vscode/presets/oro/schemas/generator/generate.php '$work/out'"
check "--schemas validates the symfony and the oro schemas separately" sh -c "grep -qF 'env ONLY=symfony-* php' '$work/out' && grep -qF 'env ONLY=oro-* php' '$work/out'"
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
echo "$passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ]
