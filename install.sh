#!/usr/bin/env bash
# Installs or updates the enabled presets. This repo is cloned as the project's .vscode folder:
#
#   git clone https://github.com/Matse2005/vscode-presets.git .vscode
#   .vscode/install.sh [--schemas] [--force]
#
# The presets come from "vscodePresets.enabled" in .vscode/settings.local.json. It is idempotent; the
# "Sync vscode-presets" task runs it on every folder open.
#
#   --schemas  also run the schemas hook of the enabled presets (needs the containers running)
#   --force    overwrite generated files and snippet copies that were changed by hand
set -euo pipefail
CDPATH=
# jq on Windows can end raw output lines with \r; every jq -r below strips it before the value is used.

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
schemas=false
force=false
for arg in "$@"; do
    case $arg in
        --schemas) schemas=true ;;
        --force) force=true ;;
        *) echo "Unknown option $arg" >&2; exit 2 ;;
    esac
done

# The container sees the project at /app, and so this checkout at /app/.vscode: the presets point the container at
# files in here (oro-dotenv.php, the schema scripts) instead of copying them.
if [ "$(basename "$repo")" != .vscode ]; then
    echo "Clone this repo as the project's .vscode folder: git clone <url> .vscode" >&2
    exit 1
fi
command -v jq >/dev/null || { echo "jq is required" >&2; exit 1; }

vscode=$repo
project=$(dirname "$repo")
state=$vscode/.install-state
local_settings=$vscode/settings.local.json
mkdir -p "$state"
cd "$project"

if [ ! -f "$local_settings" ]; then
    cp "$repo/settings.local.default.json" "$local_settings"
    echo "Created .vscode/settings.local.json from settings.local.default.json; change vscodePresets.enabled there"
    available=$(for dir in "$repo"/presets/*/; do name=$(basename "$dir"); [ "$name" = base ] || printf '%s ' "$name"; done)
    echo "if this project needs other presets (available: ${available% })."
fi
if ! jq -e 'type == "object"' "$local_settings" >/dev/null 2>&1; then
    echo ".vscode/settings.local.json is not valid JSON (comments are not allowed) or not an object; fix it first." >&2
    echo "Nothing was changed." >&2
    exit 1
fi
if ! jq -e '(."vscodePresets.enabled" // []) | type == "array" and all(type == "string" and test("^[a-z0-9_-]+$"))' "$local_settings" >/dev/null; then
    echo "vscodePresets.enabled in .vscode/settings.local.json must be a list of preset names. Nothing was changed." >&2
    exit 1
fi

# Enabled presets plus everything they require, dependencies first. base is always on.
resolved=()
resolving=" "
resolve() {
    local name=$1 dependency
    [ -f "$repo/presets/$name/preset.json" ] || { echo "Unknown preset \"$name\"" >&2; exit 1; }
    for dependency in ${resolved[@]+"${resolved[@]}"}; do
        [ "$dependency" = "$name" ] && return
    done
    # A preset that was already resolved returned above, so one that is still in progress means a cycle.
    case $resolving in *" $name "*) echo "Presets require each other in a cycle: $resolving$name" >&2; exit 1 ;; esac
    resolving="$resolving$name "
    while IFS= read -r dependency; do
        [ -n "$dependency" ] && resolve "$dependency"
    done < <(jq -r '.requires[]?' "$repo/presets/$name/preset.json" | tr -d '\r')
    resolved+=("$name")
}
resolve base
while IFS= read -r name; do
    resolve "$name"
done < <(jq -r '."vscodePresets.enabled"[]?' "$local_settings" | tr -d '\r')
echo "Presets: ${resolved[*]}"

# Placeholder values: vscodePresets.<name> in settings.local.json, else the default of an enabled preset.
variables=$(
    for name in "${resolved[@]}"; do jq '.defaults // {}' "$repo/presets/$name/preset.json"; done \
        | jq -s --slurpfile local "$local_settings" '
            add + ($local[0] | with_entries(select(.key | startswith("vscodePresets.")) | .key |= ltrimstr("vscodePresets.")))
            | del(.enabled)
            | if .runner then .runner |= ([splits("\\s+") | select(length > 0)] | join(" ")) else . end'
)
# Values are written into the generated files as they are: a path of your own stays in settings.local.json and is not
# turned into a host path here (a task expands ~ itself when it runs).
if jq -e '.runner == ""' <<< "$variables" >/dev/null; then
    echo "vscodePresets.runner is empty" >&2
    exit 1
fi

# {{name}} is replaced by the variable. {{runner}} as a whole array element becomes one element per word, so
# ["{{runner}}", "php"] turns into ["kevin", "app", "php"]. An unknown variable is an error, not an empty string.
render() {
    jq --argjson vars "$variables" '
        def value($name): $vars[$name] // error("vscodePresets.\($name) is not set in settings.local.json and no enabled preset has a default for it");
        walk(if type == "array" then map(if type == "string" and test("^\\{\\{[a-zA-Z]+\\}\\}$") then (value(.[2:-2]) | [splits(" ")] | .[]) else . end) else . end)
        | walk(if type == "string" then gsub("\\{\\{(?<name>[a-zA-Z]+)\\}\\}"; value(.name)) else . end)'
}

# Settings fragments are merged in preset order, then settings.local.json. A key replaces the earlier value, objects
# merge per key, and a key ending in + appends to the earlier array. vscodePresets.* keys only configure this script.
merged_settings() {
    {
        for name in "${resolved[@]}"; do
            if [ -f "$repo/presets/$name/settings.json" ]; then cat "$repo/presets/$name/settings.json"; fi
        done
        jq 'with_entries(select(.key | startswith("vscodePresets.") | not))' "$local_settings"
    } | jq -s '
        reduce .[] as $layer ({};
            (. * ($layer | with_entries(select(.key | endswith("+") | not))))
            | reduce ($layer | to_entries[] | select(.key | endswith("+"))) as $entry
                (.; .[$entry.key | rtrimstr("+")] += $entry.value))' | render
}

merged_tasks() {
    for name in "${resolved[@]}"; do
        if [ -f "$repo/presets/$name/tasks.json" ]; then cat "$repo/presets/$name/tasks.json"; fi
    done | jq -s '{version: "2.0.0", tasks: (map(.tasks) | add // [])}' | render
}

# Every file install.sh puts in the root of .vscode is only overwritten while it still matches what this script wrote
# last time, or when it already has the new content. Otherwise someone changed it (often the VS Code settings UI for
# settings.json), and that change would be lost. $2 is a file with the new content.
install_file() {
    local file=$1 new=$2
    local target=$vscode/$file last=$state/$file
    if cmp -s "$new" "$target"; then
        cp "$new" "$last" || return 1
    elif [ -f "$target" ] && ! $force && ! { [ -f "$last" ] && cmp -s "$target" "$last"; }; then
        echo "$file was changed since the last install. Move the change to settings.local.json or the repo, or rerun" >&2
        echo "with --force to overwrite it. Diff against what would be written:" >&2
        diff -u "$target" "$new" >&2 || true
        return 1
    else
        # Written next to the target and moved over it, so nothing ever reads a half-written file. The state copy comes
        # after; if that fails, the next run sees target and new content equal and repairs it.
        cp "$new" "$state/.new" && mv "$state/.new" "$target" || return 1
        cp "$new" "$last" || return 1
        echo "Updated .vscode/$file"
    fi
}

# Both files are built before anything in .vscode changes: a failing jq (a typo, an unknown variable) must leave the
# project exactly as it was, not with an empty settings.json or half the presets applied.
header="// Generated by .vscode/install.sh from the enabled presets. Edit .vscode/settings.local.json or the presets instead."
if ! settings_body=$(merged_settings) || [ -z "$settings_body" ] || ! tasks_body=$(merged_tasks) || [ -z "$tasks_body" ]; then
    echo "Could not build settings.json or tasks.json (see the error above). Nothing was changed." >&2
    exit 1
fi
status=0
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
printf '%s\n%s\n' "$header" "$settings_body" > "$tmp"
install_file settings.json "$tmp" || status=1
printf '%s\n%s\n' "$header" "$tasks_body" > "$tmp"
install_file tasks.json "$tmp" || status=1

# VS Code only reads snippets from the root of .vscode, so they are copied there as <preset>-<file>; the prefix shows
# where each one comes from. The sync on folder open keeps the copies current.
installed=()
for name in "${resolved[@]}"; do
    for snippet in "$repo/presets/$name/snippets/"*.code-snippets; do
        [ -e "$snippet" ] || continue
        file=$name-$(basename "$snippet")
        installed+=("$file")
        install_file "$file" "$snippet" || status=1
    done
done

# Copies from an earlier install that no enabled preset wants any more (a disabled preset, a removed snippet). A copy
# that was changed since is kept.
list=$state/installed.txt
if [ -f "$list" ]; then
    while IFS= read -r file; do
        [ -n "$file" ] || continue
        case " ${installed[*]-} " in *" $file "*) continue ;; esac
        if [ ! -e "$vscode/$file" ]; then
            rm -f "$state/$file"
        elif cmp -s "$vscode/$file" "$state/$file"; then
            rm "$vscode/$file" "$state/$file" && echo "Removed .vscode/$file"
        else
            echo "Kept .vscode/$file: no enabled preset installs it any more, but it was changed since the last install." >&2
        fi
    done < "$list"
fi
printf '%s\n' ${installed[@]+"${installed[@]}"} > "$list" || status=1
export VSCODE_PRESETS_REPO=$repo
VSCODE_PRESETS_RUNNER=$(jq -r '.runner // ""' <<< "$variables" | tr -d '\r')
export VSCODE_PRESETS_RUNNER
run_hooks() {
    local name hook
    for name in "${resolved[@]}"; do
        hook=$repo/presets/$name/hooks/$1.sh
        [ -e "$hook" ] || continue
        if [ -x "$hook" ]; then "$hook" || status=1; else echo "Skipped .vscode/presets/$name/hooks/$1.sh: it is not executable (chmod +x)." >&2; status=1; fi
    done
}
run_hooks install
if $schemas; then
    run_hooks schemas
fi

if git -C "$project" rev-parse --is-inside-work-tree >/dev/null 2>&1 && ! git -C "$project" check-ignore -q .vscode/install.sh; then
    echo "Warning: .vscode/ is not ignored by git here. Add .vscode/ to your global gitignore (core.excludesFile)." >&2
fi

exit $status
