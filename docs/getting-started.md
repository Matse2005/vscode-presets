# Getting started

This repo is cloned as a project's `.vscode` folder. Every project gets its own checkout, and `install.sh` turns the presets enabled for that project into the files VS Code reads.

## Requirements on a machine

These are set up once per machine:

1. The global gitignore contains `.vscode/`, `.php-cs-fixer.local.php` and `.orodocumentation`. Check where it is with `git config --global core.excludesFile`. The checkout itself must stay out of the project's git, and the presets write the other two files into the project root. `install.sh` warns when `.vscode/` is not ignored, and the php hook warns when an existing `.php-cs-fixer.local.php` is not. Nothing checks `.orodocumentation`.
2. `jq` is on the PATH. Recent macOS versions ship it in `/usr/bin`; otherwise `brew install jq`. On Windows, `winget install jqlang.jq`.
3. For the php, symfony and oro presets: the project runs in a container that mounts it at `/app`, with a command to run PHP in it (`kevin app` through the kevin preset, otherwise `vscodePresets.runner`, see [presets](presets.md)).

On Windows, everything runs in Git Bash from Git for Windows: `install.sh`, the hooks, the scripts and the VS Code tasks. The base preset sets `terminal.integrated.automationProfile.windows` to `${env:ProgramFiles}\Git\bin\bash.exe`, the standard Git for Windows location, so VS Code runs the tasks there instead of in PowerShell. When Git is installed elsewhere, override that setting in `settings.local.json`. A plain `bash` is not used because on Windows it often resolves to WSL, which does not run in the same environment. Run the commands below from Git Bash. "Link Oro documentation" needs a real symlink, which Windows only allows with Developer Mode on or from an administrator shell; the script stops with an error instead of copying the folder.

## Installing in a project

From the project root:

    git clone https://github.com/Matse2005/vscode-presets.git .vscode
    .vscode/install.sh

The first run copies `settings.local.default.json` to `.vscode/settings.local.json`. That default enables `php`, `symfony`, `oro` and `kevin` and points `vscodePresets.oroDocumentation` at `~/Work/orodocumentation`. The install then continues with those presets. For another kind of project, change `vscodePresets.enabled` in `.vscode/settings.local.json` and run `.vscode/install.sh` again.

To also build the YAML schemas of the oro preset, start the containers and run:

    .vscode/install.sh --schemas

Reload the VS Code window afterwards, so the extensions read the new settings. VS Code then offers to install the extensions in the generated `.vscode/extensions.json` that are missing, which on a new machine sets them all up at once.

## Keeping it up to date

The "Sync vscode-presets" task runs `install.sh` on every folder open. In my setup VS Code only ran it automatically after I allowed automatic tasks for the folder once; otherwise run it from the Command Palette with "Tasks: Run Task".

To get changes from GitHub, run the "Update vscode-presets" task, or:

    git -C .vscode pull --ff-only
    .vscode/install.sh

The pull is fast-forward only, so it stops instead of merging when the checkout has commits of its own that are not pushed yet. Pulling is never automatic; only the install runs on folder open.

"Check vscode-presets" (`.vscode/install.sh --check`) reports the state of the project without changing anything: the generated files, the git ignore, the container, and the checks of each preset. Run it first when something seems off.

`.vscode/settings.local.json` and `presets/*/preset.json` get hover text and completion from the schemas in `presets/base/schemas/`, so the available preset names and keys show up while typing.

## Changing the presets

Every checkout is a full clone. Change a preset in any project's `.vscode`, commit and push there, and pull in the other projects. Files that `install.sh` generates are gitignored, so `git -C .vscode status` only shows real changes to the presets.

Per-project changes do not go in the presets. They go in `.vscode/settings.local.json`, see [the install script](install-script.md) for the merge rules.

## Removing it from a project

Delete the `.vscode` folder. Outside it, the presets may have created `.php-cs-fixer.local.php` (php preset) and `.orodocumentation` (the "Link Oro documentation" task) in the project root; delete those too if they are not wanted.
