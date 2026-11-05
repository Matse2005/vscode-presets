# Notes for AI agents

You are working in a checkout of this repo that is a project's `.vscode` folder. Read this before changing anything here, and before debugging an editor or environment problem in the project.

## Rules for changes

1. Never edit `settings.json`, `tasks.json`, `extensions.json`, `launch.json` or the `<preset>-*.code-snippets` files in the root of `.vscode`. They are generated; the next sync overwrites them or refuses to run. Change a preset under `presets/`, or the project's `settings.local.json`.
2. Per-project settings go in `.vscode/settings.local.json`, never in a preset.
3. No paths outside the project in the presets or in anything `install.sh` writes. Refer to the checkout as `${workspaceFolder}/.vscode` in tasks and as `/app/.vscode` for the container. The only exception is the documentation path in `settings.local.default.json`. It reaches only gitignored files, `settings.local.json` and, through the "Link Oro documentation" task, `tasks.json`, and stays a `~` path in both.
4. No symlinks in `.vscode`. Files VS Code needs in the root of `.vscode` are copied; everything else is used in place. The only link is `.orodocumentation` in the project root, made by the "Link Oro documentation" task.
5. Do not turn `~` into a home directory in `install.sh`; a task or script expands it when it runs.
6. Keep `install.sh`, the hooks and the scripts working with bash 3.2 (macOS), with Git Bash on Windows (strip `\r` from `jq -r` output, no reliance on the execute bit or on symlinks), and with `set -euo pipefail`. CI runs the tests on all three systems.
7. A new kind of project gets a new preset folder, and `install.sh` stays as it is. See [presets](presets.md).
8. Do not commit or push unless the user asks.

## Verifying a change

Start with the automated checks; they need no containers and also run in GitHub Actions on every push:

    .vscode/tests/run.sh
    .vscode/tests/lint.sh

A change to `install.sh`, a hook or a script needs a test in `tests/run.sh`. Then run these from the project root after changing a preset or `install.sh`:

1. `.vscode/install.sh` exits with 0.
2. A second `.vscode/install.sh` prints only the `Presets:` line (plus hook messages) and changes no file outside `.install-state/`.
3. `git -C .vscode status --porcelain` shows only the files you meant to change; generated files must not appear.
4. `grep -n "/Users/\|$HOME" .vscode/settings.json .vscode/tasks.json` finds nothing.
5. After a change to the schemas (the library and validator in `presets/symfony/schemas/generator/`, a generator or an overlay): `.vscode/install.sh --schemas` with the containers running, and the validators report 0 errors for every schema.
6. The tests cover the failure paths of `install.sh`; a new failure path gets a test that it exits with 1 and leaves `.vscode` unchanged.

Test risky changes on a scratch project first: create an empty folder with `git init`, copy the checkout into it as `.vscode` without the gitignored files, and run the install there.

## Debugging editor problems

Read [troubleshooting](troubleshooting.md) first and run its checks. For the empty extend class map (`Class "Extend\Entity\EV_..." does not exist`, unexpected logouts), troubleshooting traces the cause to the Symfony LSP rather than to project code.

Booting the kernel in the container can rebuild the cache. Before and after anything that might, check that `var/cache/dev/oro_entities/Extend/Entity/classes.php` is not around 32 bytes.

## Topic index

| Topic | File |
| --- | --- |
| Installing in a project | [getting-started.md](getting-started.md) |
| What each preset adds, adding a preset | [presets.md](presets.md) |
| Merge rules, written files, exit codes | [install-script.md](install-script.md) |
| YAML schemas, generators, overlays | [yaml-schemas.md](yaml-schemas.md) |
| Known problems and their checks | [troubleshooting.md](troubleshooting.md) |
| Automated tests | `tests/run.sh`, `tests/lint.sh`, `.github/workflows/tests.yml` |
