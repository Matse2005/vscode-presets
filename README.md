# vscode-presets

My VS Code setup, organised in presets per project type: settings, tasks, YAML snippets, YAML schemas with hover text, the Symfony LSP fix and PHPUnit through the container. A project switches presets on with one line. It is for me only: it is cloned as a project's `.vscode/` folder, which my global gitignore ignores, so nothing reaches the team.

Anyone (or any Claude session) asked to fix "something that stopped working" in the editor should read this file first and check the project against it. In a project, this file is `.vscode/README.md`.

## Presets

| Preset | Requires | What it adds |
| --- | --- | --- |
| `base` | | Always on: the "Sync vscode-presets" task and the Prettier config |
| `php` | | php-cs-fixer on save, Intelephense excludes, PHPUnit in the container (`{{runner}} php bin/phpunit`) |
| `symfony` | `php` | Symfony LSP in the container, YAML custom tags (`!tagged_iterator`, ...), snippets for Symfony YAML tags and service definitions |
| `oro` | `symfony` | The Symfony LSP env fix (`oro-dotenv.php`), Oro snippets, generated YAML schemas with hover text, "Link Oro documentation" |
| `kevin` | | Sets the container command to `kevin app`, and the "Restart Kevin with a clean cache" task |

A new project type is a new folder under `presets/` with the same parts (all optional except `preset.json`):

| Path in a preset | Purpose |
| --- | --- |
| `preset.json` | `description`, `requires` (presets it builds on) and `defaults` for placeholder variables |
| `settings.json` | Settings fragment, merged after the presets it requires |
| `tasks.json` | `{"tasks": [...]}`, appended to the other presets' tasks |
| `snippets/*.code-snippets` | Copied to the root of `.vscode/` as `<preset>-<file>` |
| other files | Used in place, for example by the container through `/app/.vscode/presets/<preset>/` |
| `hooks/install.sh` | Runs on every sync, in the project root |
| `hooks/schemas.sh` | Runs on `install.sh --schemas` |
| `scripts/` | Helper scripts, called from tasks or hooks |

Hooks get `VSCODE_PRESETS_REPO` (this repo) and `VSCODE_PRESETS_RUNNER` (the container command) in their environment.

## Installation

This repo is cloned as a project's `.vscode` folder, so every project has its own checkout. The container sees the project at `/app` and the checkout at `/app/.vscode`, which is why the presets can point the container at files in here (`oro-dotenv.php`, the schema scripts) without copying them.

On a new machine, make sure of two things once:

1. The global gitignore (`git config --global core.excludesFile`) contains `.vscode/`, `.php-cs-fixer.local.php` and `.orodocumentation`. The presets write the last two into the project root. `install.sh` and the php hook warn when something is not ignored, because the project repo would otherwise see it.
2. `jq` is on the PATH. Recent macOS versions ship it in `/usr/bin`; otherwise `brew install jq`.

In a project, from its root:

    git clone https://github.com/Matse2005/vscode-presets.git .vscode
    .vscode/install.sh

The first run creates `.vscode/settings.local.json` and stops. List the presets there, for example `{"vscodePresets.enabled": ["oro", "kevin"]}`, and run `.vscode/install.sh` again, or `.vscode/install.sh --schemas` to also build the YAML schemas (needs the containers running).

After that the "Sync vscode-presets" task runs `install.sh` on every folder open, so a changed presets line or a `git -C .vscode pull` reaches the editor without anything else. It only runs automatically after I accept "Allow Automatic Tasks in Folder" once; otherwise run it from the Command Palette with "Tasks: Run Task". Reload the window after a sync that changed `settings.json`.

Changes to the presets are made in any project's `.vscode`, committed and pushed there, and pulled in the other projects.

## Project folder after install

Next to the files of this repo, `install.sh` writes these into `.vscode/`. The repo's `.gitignore` ignores all of them, so the checkout stays clean:

| Path in `.vscode/` | What it is |
| --- | --- |
| `settings.local.json` | Mine to edit; the only file that differs per project |
| `settings.json`, `tasks.json` | Generated from the enabled presets and `settings.local.json`. Do not edit |
| `<preset>-*.code-snippets` | Copies of the presets' snippets. VS Code only reads snippets from the root of `.vscode/` |
| `presets/oro/schemas/generated/` | The YAML schemas built by `install.sh --schemas` |
| `.install-state/` | What `install.sh` wrote last time and the list of snippet copies, to detect hand edits and remove what is no longer wanted |

Nothing in this repo, and nothing `install.sh` writes, contains a path outside the project: tasks use `${workspaceFolder}/.vscode`, and paths of your own (such as the Oro documentation checkout) only go in `settings.local.json`. The "Link Oro documentation" task reads that path from its environment when it runs. Switching a preset off removes its snippets, settings and tasks on the next sync.

`install.sh` is careful with what it did not write itself:

- It builds `settings.json` and `tasks.json` before it changes anything. When that fails (a typo in `settings.local.json`, an unknown preset, a missing variable), it stops and leaves `.vscode/` exactly as it was.
- It only overwrites a generated file or a snippet copy while it still matches what it wrote last time. When something changed it (often the VS Code settings UI, which writes to the workspace `settings.json`), it shows the diff and leaves the file alone. Move the change to `settings.local.json` or the presets, or rerun with `--force` to drop it.
- A snippet copy that no enabled preset installs any more is removed, unless it was changed since the last install.

## Settings per project

The settings fragments of the enabled presets are merged in dependency order, then `settings.local.json` on top:

- A key replaces the earlier value; objects such as `yaml.schemas` merge key by key.
- A key ending in `+` appends to the earlier array instead of replacing it: `"intelephense.files.exclude+": ["**/build/**"]`. Presets use this too, which is how `oro` adds `public/` folders to the excludes of `php`.
- `vscodePresets.*` keys only configure `install.sh` and do not end up in `settings.json`:
  - `vscodePresets.enabled`: the presets for this project.
  - `vscodePresets.runner`: the command that runs something in the PHP container. The `kevin` preset sets `kevin app`; without it, set the runner yourself, for example `ddev exec`. It is used for the Symfony LSP, PHPUnit and the schema scripts.
  - `vscodePresets.oroDocumentation`: the local Oro documentation checkout for "Link Oro documentation". No default, because it lives outside the project; the task asks for it when it is not set. A leading `~` is expanded by the task when it runs.

Any `{{name}}` in a preset's settings or tasks is filled from `vscodePresets.<name>`, falling back to the `defaults` of an enabled preset. A missing variable is an error, not an empty string. Values are written as they are: `install.sh` does not expand `~` or add any path of its own, so a path of yours only ever appears where you put it. Paths into this checkout use `${workspaceFolder}/.vscode`.

## Tasks

| Task | Preset | What it does |
| --- | --- | --- |
| Sync vscode-presets | base | Runs `install.sh`. Runs on folder open |
| Create php-cs-fixer local config | php | Creates `.php-cs-fixer.local.php` (every sync does this too when `.php-cs-fixer.php` exists) |
| Link Oro documentation | oro | Links the Oro documentation checkout as `.orodocumentation` in the project root |
| Regenerate YAML schemas | oro | Runs `install.sh --schemas` |
| Restart Kevin with a clean cache | kevin | Stops Kevin, empties `var/cache` and starts Kevin again; refuses when the folder has no `bin/console` |

## Symfony LSP and the empty extend class map (oro preset)

Symptoms, in the order they show up:

1. After saving something in the back office: `Class "Extend\Entity\EV_Auth_Status" does not exist` (or any other `EV_*` class).
2. After a reload I am logged out.
3. Then: `Call to a member function hasMethod() on null` in `doctrine/orm/.../ProxyFactory.php:190`.

None of this comes from project code. The Symfony Language Tools extension (`symfony.language-tools`) needs `symfonyLsp.phpCommand` to run PHP inside the container (`kevin app php`, project root `/app`). Its bridge script (`var/symfony-lsp/<version>/<hash>/bridge/context.php`, `loadEnvironment()`) boots the kernel with `SymfonyRuntime` and only a `project_dir`. That means it reads `.env` and ignores `extra.runtime.dotenv_path: .env-app` from `composer.json`, so `.env-app` and `.env-app.local` never load. `ORO_DB_URL` and `ORO_REDIS_URL` then fall back to `127.0.0.1` from `config/config.yml`, and the database is unreachable. On a rebuild the bridge also deletes `var/cache/dev/AppKernelDevDebugContainer.php.meta`. The container then rebuilds inside that broken process and writes an empty class map to `var/cache/dev/oro_entities/Extend/Entity/classes.php`, and the web app shares that cache.

The fix is `oro-dotenv.php`, prepended through `-d auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php` in `symfonyLsp.phpCommand` (set by the oro preset). It loads `.env-app` with Oro's variable names (`ORO_ENV`, `ORO_DEBUG`) before the bridge runs. It fails soft: when `vendor/` or `.env-app` is missing it does nothing, so the LSP still starts.

### Checks when it breaks again

1. `wc -c var/cache/dev/oro_entities/Extend/Entity/classes.php`. Around 32 bytes means the class map is empty and this problem is back.
2. `grep -c "Connection refused" var/logs/dev.log` before and after saving a PHP file and letting the LSP index. If the count grows, the LSP still runs without the Oro env.
3. `grep -n 'running command "debug:form' var/logs/dev.log | tail` shows when the LSP last ran.
4. `grep -A6 symfonyLsp.phpCommand .vscode/settings.json` must contain `auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php`, and `.vscode/presets/oro/oro-dotenv.php` must exist. If not, run "Sync vscode-presets".
5. After an extension update, open the new `var/symfony-lsp/<version>/.../bridge/context.php` and check `loadEnvironment()`. If it now respects `dotenv_path`, the prepend is no longer needed. If the bridge stopped honouring extra `php` arguments, the prepend silently stops working.
6. Verify the prepend in the container with a script file. `php -r` ignores `auto_prepend_file`, so a `php -r` test always looks broken:
   `kevin app php -d auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php /app/var/some-check.php`, where the script prints `$_SERVER["ORO_DB_URL"]`.

### Recovery

1. Run "Sync vscode-presets", or `install.sh` from the project root.
2. `kevin console cache:clear` to rebuild the extend classes from the real database.
3. Log in again and reload the VS Code window so the LSP restarts with the new command.

## php-cs-fixer on save (php preset)

The php preset points the extension at `.php-cs-fixer.local.php` in the project root. VS Code runs the fixer from another directory, so that file does `chdir(__DIR__)` and then returns the committed `.php-cs-fixer.php`. That keeps on-save fixing identical to CI. If on-save fixing does nothing or fixes the wrong files, check that `.php-cs-fixer.local.php` exists (every sync creates it when the project has `.php-cs-fixer.php`, or run "Create php-cs-fixer local config") and that `bin/php-cs-fixer` exists.

## PHPUnit (php preset)

The PHPUnit extension (`recca0120.vscode-phpunit`) runs `{{runner}} php bin/phpunit` and maps the project to `/app` through `phpunit.paths`. `bin/phpunit` is the Composer proxy to PHPUnit in projects that set Composer's `bin-dir` to `bin`; in a project without it, set `"phpunit.phpunit": "vendor/bin/phpunit"` in `settings.local.json`. If test results stop linking to files on macOS, set `phpunit.paths` in `settings.local.json` with the absolute project path instead of `${workspaceFolder}`; the extension documents that the variable does not always resolve there. Xdebug through the container is not set up.

## YAML schemas (oro preset)

The schemas exist for hover text and completion, not to enforce anything. `generate.php` builds them from the config trees of the installed versions: each Oro config kind (`config/oro/<kind>.yml`) from the class Oro validates it with, and `config*.yml` from every bundle extension, the way `config:dump-reference` does. `info()` texts, defaults and enum values become the description, `example()` and defaults become examples. Many keys, Symfony's above all, have no `info()`, so they get completion without a description. The overlays in `presets/oro/schemas/overlay/` add hand-written descriptions and examples for the kinds used daily (datagrids, operations, workflows, navigation, ACLs, API, services, routing), and are the only source for kinds without a config tree: ACLs, service files, routing, jsmodules, assets, bundles, twig, websocket routing, entity config scopes and storefront layout updates.

Regenerate after an Oro or Symfony upgrade or after editing an overlay, with "Regenerate YAML schemas" or `install.sh --schemas`. That runs the generator and then the validator. To run one of them by hand, limited to some schemas:

    kevin app sh -c "ONLY=oro-datagrids,symfony-services php -d memory_limit=2G -d auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php /app/.vscode/presets/oro/schemas/generator/generate.php"
    kevin app sh -c "ONLY=oro-datagrids php -d memory_limit=4G /app/.vscode/presets/oro/schemas/generator/validate.php"

`generate.php` never boots the kernel, because a container compile fails as soon as a service points to a class that is still being written. It lists anything that failed at the end and then exits with 1. It does not attempt `sanitize.yml` (its config tree needs processor registries) or storefront `theme.yml` (needs layout services). The API schema leaves out the feature keys under `api`, and `options.toolbarOptions` in datagrids is hand-written only. `validate.php` exits with 1 on errors and reports files it could not parse as unchecked.

Types are left loose on purpose: Symfony normalizes values before it validates them, unknown keys are allowed, and every key accepts `~`. `validate.php` checks every mapped file in `vendor/` and the project; any error it reports means a schema is too strict, so relax the overlay instead of changing the config. The file to schema mapping lives only in `yaml.schemas` in `presets/oro/settings.json`, and `validate.php` reads it from the project's `settings.json`.

## Container assumptions

The `php`, `symfony` and `oro` presets assume the project is mounted at `/app` in the container: `phpunit.paths`, `symfonyLsp.containerProjectRoot`, `oro-dotenv.php` and the schema scripts use it. Another runner works through `vscodePresets.runner` as long as the mount stays `/app`. The schema generator lives in the `oro` preset because it builds on Oro's config classes and `AppKernel`; the service and routing schemas would belong in `symfony` once the generator is split for a non-Oro project.
