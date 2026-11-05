# Troubleshooting

Problems that do not come from project code. Run "Check vscode-presets" (`.vscode/install.sh --check`) first: it runs most of the checks below and changes nothing. Then use the sections below for the details.

## Empty extend class map after saving (oro preset)

Symptoms, in the order they show up:

1. After saving something in the back office: `Class "Extend\Entity\EV_Auth_Status" does not exist` (or any other `EV_*` class).
2. After a reload, you are logged out.
3. Then: `Call to a member function hasMethod() on null` in `doctrine/orm/.../ProxyFactory.php:190`.

The Symfony Language Tools extension (`symfony.language-tools`) runs PHP in the container through `symfonyLsp.phpCommand`. Its bridge script (`var/symfony-lsp/<version>/<hash>/bridge/context.php`, `loadEnvironment()`) loads the environment through `SymfonyRuntime` with only `project_dir`, `env` and `debug`, and only when the project has a `.env`, `.env.dist` or `.env.local.php`. It never passes `extra.runtime.dotenv_path: .env-app` from `composer.json`, and an Oro project has no `.env`, so no env file loads: `.env-app` and `.env-app.local` are skipped. `ORO_DB_URL` and `ORO_REDIS_URL` then fall back to `127.0.0.1` from `config/config.yml`, and the database is unreachable. On a rebuild the bridge also deletes `var/cache/dev/AppKernelDevDebugContainer.php.meta`. In the investigation behind this fix, the container then rebuilt inside that process and wrote an empty class map to `var/cache/dev/oro_entities/Extend/Entity/classes.php`, which the web app shares.

The fix is `presets/oro/oro-dotenv.php`, prepended through `-d auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php` in the `symfonyLsp.phpCommand` of the oro preset. It loads `.env-app` with Oro's variable names (`ORO_ENV`, `ORO_DEBUG`) before the bridge runs. It fails soft: when `vendor/` or `.env-app` is missing it does nothing, so the LSP still starts.

### Checks

1. `wc -c var/cache/dev/oro_entities/Extend/Entity/classes.php`. Around 32 bytes means the class map is empty and the problem is back.
2. `grep -c "Connection refused" var/logs/dev.log` before and after saving a PHP file and letting the LSP index. A growing count means the LSP still runs without the Oro env.
3. `grep -n 'running command "debug:form' var/logs/dev.log | tail` shows when the LSP last ran.
4. `grep -A6 symfonyLsp.phpCommand .vscode/settings.json` must contain `auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php`. If it does not, check that `oro` is enabled and run "Sync vscode-presets".
5. After an extension update, open the new `var/symfony-lsp/<version>/.../bridge/context.php` and check `loadEnvironment()`. If it now respects `dotenv_path`, the prepend is no longer needed. If the bridge stopped passing extra `php` arguments, the prepend silently stops working.
6. Check the prepend in the container with a script file; `php -r` ignores `auto_prepend_file`, so a `php -r` test always looks broken:
   `kevin app php -d auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php /app/var/some-check.php`, where the script prints whether `$_SERVER["ORO_DB_URL"]` is set.

### Recovery

1. Run "Sync vscode-presets", or `.vscode/install.sh` from the project root.
2. `kevin console cache:clear` to rebuild the extend classes from the real database.
3. Log in again and reload the VS Code window, so the LSP restarts with the right command.

## Sync refuses to overwrite a file

`install.sh` prints "... was changed since the last install" with a diff. Something changed a generated file or a snippet copy; for `settings.json` it is usually the VS Code settings UI. Move the change to `.vscode/settings.local.json` (or to a preset) and run the sync again, or run `.vscode/install.sh --force` to drop the change. See [the install script](install-script.md).

## Sync stops before changing anything

The messages name the cause: `settings.local.json` is not valid JSON (comments are not allowed), `vscodePresets.enabled` is not a list of names, a preset is unknown, presets require each other in a cycle, the runner is empty, or a placeholder has no value. The generated files and snippet copies are left as they were.

## php-cs-fixer on save does nothing

Check that `.php-cs-fixer.local.php` exists in the project root (the php hook creates it when `.php-cs-fixer.php` exists, or run "Create php-cs-fixer local config") and that `bin/php-cs-fixer` exists.

## PHPUnit tests do not run or do not link to files

The PHPUnit extension runs `kevin app php bin/phpunit` (with the kevin preset). Without `bin/phpunit`, set `"phpunit.phpunit": "vendor/bin/phpunit"` in `settings.local.json`. If results stop linking to files on macOS, set `phpunit.paths` in `settings.local.json` with the absolute project path instead of `${workspaceFolder}`; the extension documents that the variable does not always resolve there. Reload the window after changing settings; in my experience the extension only picked them up after a reload.

## Xdebug does not stop at breakpoints

The php preset adds "Listen for Xdebug in the container" to `launch.json`: port 9003 with `/app` mapped to the project. With Kevin, Xdebug is off in the application container by default; the container's start script removes the extension while `XDEBUG_MODE` is `off`. To switch it on:

1. Set `XDEBUG_MODE=debug` in `.env.kevin.local` in the project root.
2. Recreate the container: `kevin recreate <project>-application`.
3. Start "Listen for Xdebug in the container" in VS Code.

The project's PHP overrides (`.25carat/docker/php/php-overrides.ini`) set `xdebug.start_with_request=yes` and `xdebug.client_host=host.docker.internal`, and no port, so Xdebug uses its default 9003. For a CLI command, Kevin's documentation adds `XDEBUG_TRIGGER=yes`, for example `XDEBUG_TRIGGER=yes kevin console oro:check-requirements`. Set `XDEBUG_MODE` back to `off` and recreate the container afterwards, because Xdebug slows every request down.

## YAML hover text missing

Run `.vscode/install.sh --schemas` with the containers running, then reload the window. The bundle config, service and routing schemas come from the symfony preset and the Oro ones from the oro preset, so both have to be enabled for an Oro project. If the validator reports errors, a schema is too strict; see [YAML schemas](yaml-schemas.md).
