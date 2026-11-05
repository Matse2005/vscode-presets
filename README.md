# vscode-presets

My VS Code setup, organised in presets per project type: settings, tasks, YAML snippets, YAML schemas with hover text, the Symfony LSP fix and PHPUnit through the container. The repo is cloned as a project's `.vscode` folder, and a project switches presets on with one line in `.vscode/settings.local.json`. It is for me only; my global gitignore keeps `.vscode/` out of the project's git.

    git clone https://github.com/Matse2005/vscode-presets.git .vscode
    .vscode/install.sh

The first run enables `php`, `symfony`, `oro` and `kevin` from `settings.local.default.json`. Change `vscodePresets.enabled` in `.vscode/settings.local.json` for another kind of project.

When something in the editor or the environment stops working (`Class "Extend\Entity\EV_..." does not exist`, unexpected logouts, the database unreachable at 127.0.0.1), start with [troubleshooting](docs/troubleshooting.md).

## Documentation

| Document | Contents |
| --- | --- |
| [Getting started](docs/getting-started.md) | Machine requirements, installing in a project, updating, changing the presets |
| [Presets](docs/presets.md) | What each preset adds, placeholders and variables, adding a preset |
| [The install script](docs/install-script.md) | Options, merge rules, the files it writes, protection of hand edits, exit codes |
| [YAML schemas](docs/yaml-schemas.md) | Coverage, the generator and overlays, regenerating, validation, known gaps |
| [Troubleshooting](docs/troubleshooting.md) | The empty extend class map, a refused sync, php-cs-fixer, PHPUnit, Xdebug, missing hover text |
| [Notes for AI agents](docs/ai-agents.md) | Rules for changes, verifying them, a topic index |

## Layout

| Path | Contents |
| --- | --- |
| `install.sh` | Installs the enabled presets into this folder |
| `settings.local.default.json` | The starting `settings.local.json` for a new project |
| `presets/<name>/` | One folder per preset: `preset.json`, settings and task fragments, snippets, hooks, scripts |
| `docs/` | This documentation |
| `tests/` | `run.sh` tests the install script, hooks and scripts in throwaway projects; `lint.sh` checks JSON, PHP syntax, shellcheck and executable bits |
| `.gitattributes` | Keeps LF line endings in a Windows checkout, so the bash scripts run |
| `.github/workflows/tests.yml` | Runs both on every push, the install tests on Ubuntu, on macOS with bash 3.2 and on Windows in Git Bash |
| `.github/dependabot.yml` | Opens a pull request when an action used by the workflow has a new version |

The generated files, `settings.json`, `tasks.json`, `extensions.json`, `launch.json`, `settings.local.json`, the `<preset>-*.code-snippets` copies and `.install-state/`, are written by `install.sh`, and `presets/<preset>/schemas/generated/` by the schemas hooks. All of them are ignored by the repo's `.gitignore`.
