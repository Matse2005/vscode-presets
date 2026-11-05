# The install script

`install.sh` turns the enabled presets and `settings.local.json` into the files VS Code reads. It is idempotent: a second run without changes leaves `settings.json`, `tasks.json` and the snippet copies untouched and only refreshes `.install-state/`.

    .vscode/install.sh [--schemas] [--force]

| Option | Effect |
| --- | --- |
| `--schemas` | Also runs the `hooks/schemas.sh` of the enabled presets. The oro one needs the containers running |
| `--force` | Overwrites generated files and snippet copies that were changed by hand |

It only runs from a checkout named `.vscode`; the project is the folder above it.

## Steps

1. Create `settings.local.json` from `settings.local.default.json` when it does not exist.
2. Check `settings.local.json`: valid JSON (no comments), an object, and `vscodePresets.enabled` a list of preset names.
3. Resolve the presets: `base` first, then each enabled preset after the presets it requires. A cycle in `requires` or an unknown preset stops the install.
4. Collect the placeholder values and normalise `runner` (extra spaces removed; an empty runner stops the install).
5. Build `settings.json` and `tasks.json` in memory. If either fails, stop before `settings.json`, `tasks.json` or a snippet copy changes.
6. Write `settings.json`, `tasks.json` and the snippet copies.
7. Remove snippet copies that no enabled preset installs any more.
8. Run `hooks/install.sh` of each preset, then `hooks/schemas.sh` with `--schemas`.
9. Warn when the project's git does not ignore `.vscode/`.

## Merge rules

Settings are merged in preset order (dependencies first), and `settings.local.json` is merged last:

- A key replaces the earlier value.
- Objects, such as `yaml.schemas` or `files.watcherExclude`, merge key by key.
- A key ending in `+` appends to the earlier array instead of replacing it. `"intelephense.files.exclude+": ["**/build/**"]` adds one exclude to the list the presets built.
- Keys starting with `vscodePresets.` only configure `install.sh` and never reach `settings.json`.

Tasks are not merged; the task lists of all enabled presets are concatenated.

## settings.local.json keys

| Key | Meaning |
| --- | --- |
| `vscodePresets.enabled` | The presets for this project |
| `vscodePresets.runner` | Overrides the container command, for example `ddev exec` |
| `vscodePresets.oroDocumentation` | The Oro documentation checkout for "Link Oro documentation" |
| any other `vscodePresets.<name>` | Value for `{{name}}` in a preset |
| any other key | A VS Code setting, merged over the presets |

## Files it writes

| Path | Contents |
| --- | --- |
| `.vscode/settings.json` | Generated settings, with a header comment |
| `.vscode/tasks.json` | Generated tasks, with a header comment |
| `.vscode/<preset>-*.code-snippets` | Copies of the presets' snippets; VS Code only reads snippets from the root of `.vscode` |
| `.vscode/.install-state/` | What it wrote last time, and `installed.txt` with the snippet copies it manages |
| `.vscode/settings.local.json` | Only on the first run, from the default |

All of these are in the repo's `.gitignore`. Hooks can write more: the php hook writes `.php-cs-fixer.local.php` in the project root, and the oro schemas hook writes `presets/oro/schemas/generated/`.

## Protection of your changes

`install.sh` only writes a generated file or a snippet copy when it does not exist yet, when it still matches the copy in `.install-state/`, or when it already has the new content. When something else changed it (for `settings.json` that was the VS Code settings UI in my case), it prints the diff and leaves the file alone. Move the change to `settings.local.json` or to a preset, or run with `--force` to drop it.

A snippet copy that no enabled preset installs any more is removed only when it is unchanged. A changed one is kept, with a message.

Files are written to `.install-state/.new` and then moved over the target, so VS Code never reads a half-written file.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Everything was installed |
| 1 | Something was refused or failed: the checkout is not named `.vscode`, `jq` is missing, invalid `settings.local.json`, unknown preset, cycle, missing variable, empty runner, a file changed by hand, a failing or non-executable hook. When the failure is in building the settings or tasks, `settings.json`, `tasks.json` and the snippet copies are unchanged |
| 2 | Unknown option |
