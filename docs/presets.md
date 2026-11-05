# Presets

A preset is a folder under `presets/` with everything one kind of project needs. A project lists the presets it wants in `vscodePresets.enabled` in `.vscode/settings.local.json`; `install.sh` adds the presets they require and always adds `base`.

## Available presets

| Preset | Requires | Adds |
| --- | --- | --- |
| `base` | | The "Sync vscode-presets", "Update vscode-presets" and "Check vscode-presets" tasks, hover and completion in `settings.local.json` and `preset.json` (which also opens `settings.local.json` as JSON with comments), the Prettier config (`presets/base/prettierrc.json`) with the Prettier extension recommended, and Git Bash as the task shell on Windows. Always enabled |
| `php` | | php-cs-fixer on save, Intelephense and file watcher excludes, PHPUnit in the container, an Xdebug listen configuration, recommendations for the php-cs-fixer, Intelephense, PHPUnit and Xdebug extensions, the "Create php-cs-fixer local config" task |
| `symfony` | `php` | Symfony LSP in the container, YAML custom tags (`!tagged_iterator`, `!tagged_locator`, `!php/const`, ...), snippets for Symfony YAML tags and service definitions, the YAML schemas for bundle config, services and routing (`.yml` and `.yaml`), recommendations for the Symfony and YAML extensions |
| `oro` | `symfony` | The Symfony LSP env fix (`oro-dotenv.php`), Oro snippets, the YAML schemas for the Oro config files, the "Link Oro documentation" and "Regenerate YAML schemas" tasks |
| `kevin` | | `kevin app` as the container command, the "Restart Kevin with a clean cache" task |

Listing a required preset explicitly changes nothing; `["oro", "kevin"]` and `["php", "symfony", "oro", "kevin"]` give the same result.

## Contents of a preset

Every file except `preset.json` is optional.

| Path | Purpose |
| --- | --- |
| `preset.json` | `description`, `requires` (presets it builds on) and `defaults` for placeholder variables |
| `settings.json` | Settings fragment, merged after the presets it requires |
| `tasks.json` | `{"tasks": [...]}`, appended to the tasks of the other presets |
| `extensions.json` | `{"recommendations": [...]}`, combined with the other presets into `.vscode/extensions.json`, each extension once |
| `launch.json` | `{"configurations": [...]}`, appended to the configurations of the other presets in `.vscode/launch.json` |
| `schemas/` | YAML schemas: `generator/`, `overlay/` and the gitignored `generated/`, see [YAML schemas](yaml-schemas.md) |
| `snippets/*.code-snippets` | Copied to the root of `.vscode/` as `<preset>-<file>` |
| `hooks/install.sh` | Runs on every install, in the project root |
| `hooks/schemas.sh` | Runs on `install.sh --schemas`, in the project root |
| `hooks/check.sh` | Runs on `install.sh --check`, in the project root. Reports with `ok`, `warn` and `fail` from `presets/base/scripts/check-lib.sh` and ends with `finish`; it must not change anything |
| `scripts/` | Helper scripts, called from tasks or hooks |
| other files | Used in place, for example by the container through `/app/.vscode/presets/<preset>/` |

Hooks get two environment variables: `VSCODE_PRESETS_REPO` (the checkout) and `VSCODE_PRESETS_RUNNER` (the container command, possibly empty). A hook that fails or is not executable makes `install.sh` exit with 1, but the other hooks still run.

## Placeholders

A preset's `settings.json`, `tasks.json` and `launch.json`, and `settings.local.json` itself, can contain `{{name}}`. It is filled from `vscodePresets.<name>` in `settings.local.json`, and otherwise from the `defaults` of an enabled preset. A name without a value stops the install with an error; it never becomes an empty string.

When a placeholder such as `{{runner}}` is a whole element of an array, it becomes one element per word: `["{{runner}}", "php"]` turns into `["kevin", "app", "php"]`. Inside a longer string it is replaced as text.

Placeholder values are written as they are, apart from extra spaces in `runner`. `install.sh` does not expand `~` and does not add paths of its own.

## Variables in use

| Variable | Set by | Used for |
| --- | --- | --- |
| `runner` | `kevin` (`kevin app`), or `vscodePresets.runner` | `symfonyLsp.phpCommand`, `phpunit.command`, the oro schemas hook |
| `oroDocumentation` | `vscodePresets.oroDocumentation`; the oro default is empty | The "Link Oro documentation" task, through its environment |

Without the `kevin` preset, a project with `php` must set `vscodePresets.runner` itself, for example `ddev exec`.

## php preset

php-cs-fixer runs on save with `.php-cs-fixer.local.php` as its config. VS Code runs the fixer from another directory, so that file does `chdir(__DIR__)` and returns the committed `.php-cs-fixer.php`; on-save fixing then uses the same config as a `php-cs-fixer` run from the project root. The install hook creates the file when the project has `.php-cs-fixer.php` and the file is missing.

"Listen for Xdebug in the container" in `launch.json` listens on port 9003, the Xdebug 3 default, and maps `/app` in the container to the project. It needs Xdebug switched on in the container; for Kevin that is `XDEBUG_MODE=debug` in `.env.kevin.local` and `kevin recreate <project>-application`, see [troubleshooting](troubleshooting.md).

The PHPUnit extension (`recca0120.vscode-phpunit`) runs `{{runner}} php bin/phpunit` and maps the project to `/app` with `phpunit.paths`. `bin/phpunit` exists in projects that set Composer's `bin-dir` to `bin`. In other projects, set `"phpunit.phpunit": "vendor/bin/phpunit"` in `settings.local.json`.

## symfony preset

The Symfony Language Tools extension runs PHP through `{{runner}} php` with `/app` as the project root in the container. `yaml.customTags` makes the Red Hat YAML extension accept Symfony's YAML tags.

The preset ships the YAML schemas for the bundle config, service files and routing, mapped to both `.yml` (Oro and older Symfony) and `.yaml` (current Symfony, for example Marello 7). It also holds the shared schema library and the validator that the oro preset uses. See [YAML schemas](yaml-schemas.md).

## oro preset

The oro preset replaces `symfonyLsp.phpCommand` with a version that prepends `oro-dotenv.php`, which loads `.env-app` before the Symfony LSP boots the kernel. [Troubleshooting](troubleshooting.md) explains why. It also ships the schemas for the Oro config files (`config/oro/`, `Resources/config/oro/`) and adds Oro's `app.yml` and `routing.yml` to the bundle config and routing schemas of the symfony preset, see [YAML schemas](yaml-schemas.md).

Its check reports whether the Symfony LSP command prepends `oro-dotenv.php`, the size of the extend class map, and whether the prepend loads the Oro env in the container.

"Link Oro documentation" links the folder in `vscodePresets.oroDocumentation` as `.orodocumentation` in the project root. The task passes the setting to `scripts/link-documentation.sh` through its environment, and the script expands a leading `~` when it runs.

## kevin preset

Sets `runner` to `kevin app`. Its check reports whether `kevin` is on the PATH and the Xdebug mode in `.env.kevin.local`. "Restart Kevin with a clean cache" runs `scripts/start-kevin.sh`, which stops Kevin, empties `var/cache` and starts Kevin again. It refuses to run in a folder without `bin/console`.

## Adding a preset

1. Create `presets/<name>/preset.json` with a `description` and `requires`. Names are lowercase letters, digits, `-` and `_`.
2. Add the fragments the preset needs, using the table above. Settings that append to an earlier preset's list use a key ending in `+`.
3. Refer to files in the checkout as `${workspaceFolder}/.vscode/...` in tasks, and as `/app/.vscode/...` for the container. Do not put paths outside the project in the preset.
4. Enable it in a project and run `.vscode/install.sh`. Check the generated files, and that a second run changes nothing.
5. Add tests for it to `tests/run.sh`.

A new preset never needs a change to `install.sh` unless it needs a new kind of fragment.
