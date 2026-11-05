# YAML schemas

The symfony and oro presets ship JSON schemas for Symfony and Oro YAML files. The Red Hat YAML extension uses them for hover text and completion, and they are deliberately loose so they never flag config that works.

## Coverage

| Schema | Preset | Files |
| --- | --- | --- |
| `symfony-config` | symfony | `config/config*`, `config/security*` and `config/packages/**`, as `.yml` and `.yaml`, with one property per bundle. The oro preset adds `**/Resources/config/oro/app.yml` |
| `symfony-services` | symfony | `config/services*`, `config/services/**` and `**/Resources/config/services*`, as `.yml` and `.yaml` |
| `symfony-routing` | symfony | `config/routing*`, `config/routing/**`, `config/routes*` and `config/routes/**`, as `.yml` and `.yaml`. The oro preset adds `**/Resources/config/oro/routing.yml` |
| `oro-<kind>` | oro | `config/oro/<kind>.yml`, `config/oro/<kind>/**/*.yml` and `**/Resources/config/oro/<kind>.yml` for datagrids, actions, navigation, ACLs, workflows (also split into `Resources/config/oro/workflows/**/*.yml`), processes, API, features, search and the other Oro kinds |
| `oro-layout` | oro | Storefront layout updates in `**/Resources/views/layouts/**/*.yml` |

The mapping is `yaml.schemas` in `presets/symfony/settings.json` and `presets/oro/settings.json`. The oro preset repeats the symfony keys for the bundle config and routing schemas with its own files added, because a later preset replaces a list under the same key. `validate.php` reads the merged mapping from the generated `settings.json`.

## Generator and overlays

Each preset has the same layout under `presets/<preset>/schemas/`:

| Path | Contents |
| --- | --- |
| `generator/generate.php` | Builds the preset's schemas from the config trees of the versions installed in the project |
| `overlay/*.schema.json` | Hand-written descriptions and examples, merged over the generated schemas |
| `generated/` | The output, gitignored because it depends on the project's versions |

The shared code lives in the symfony preset: `presets/symfony/schemas/generator/schema-lib.php` turns config trees into schemas and merges the overlays, and `validate.php` checks the result. The oro generator includes that library, which is why the oro preset requires symfony.

The symfony generator builds the bundle config the way `config:dump-reference` does, from every bundle extension of the project's kernel: `App\Kernel` in a plain Symfony project, `AppKernel` in Oro, or the class in the `KERNEL_CLASS` environment variable. The service and routing schemas come from their overlays alone, because Symfony has no config tree for those files. The oro generator builds each Oro config kind from the class Oro validates it with.

`info()` texts, defaults and enum values become the description; `example()` values and defaults become examples. Many keys, Symfony's in particular, have no `info()`, so they get completion without a description. The overlays add descriptions and examples for the files used daily (datagrids, operations, workflows, navigation, ACLs, API, services, routing), and are the whole schema for kinds without a config tree: ACLs, service files, routing, jsmodules, assets, bundles, twig, websocket routing, entity config scopes and storefront layout updates.

## Regenerating

After an upgrade, or after editing an overlay, run "Regenerate YAML schemas" or:

    .vscode/install.sh --schemas

That runs the schemas hook of every enabled preset in dependency order: the symfony hook generates and validates the `symfony-*` schemas, then the oro hook does the same for `oro-*`. Each validator only runs when its generator succeeded. To run a step by hand for some schemas only (with Kevin):

    kevin app sh -c "php -d memory_limit=2G /app/.vscode/presets/symfony/schemas/generator/generate.php"
    kevin app sh -c "ONLY=oro-datagrids,oro-api php -d memory_limit=2G -d auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php /app/.vscode/presets/oro/schemas/generator/generate.php"
    kevin app sh -c "ONLY='oro-*' php -d memory_limit=4G /app/.vscode/presets/symfony/schemas/generator/validate.php"

`ONLY` takes names and patterns such as `oro-*`; when nothing matches, the generator says so and exits with 1. The generators never boot the kernel, because a container compile fails as soon as a service points to a class that is still being written. They list what failed at the end and then exit with 1.

## Validation

`validate.php` checks every mapped YAML file in `vendor/` and in the project against its schema, skipping folders named `var`, `node_modules`, `public`, `.git` and `Tests`. Files it cannot parse are reported as unchecked, and it exits with 1 when there are errors or unchecked files. An error means a schema is too strict, because the framework accepts those files: relax the overlay, do not change the config.

Types are loose because Symfony normalizes values before it validates them. Unknown keys are allowed, and every key accepts `~`.

## Known gaps

- `sanitize.yml` and storefront `theme.yml` have no schema; their config trees need container services.
- The API schema leaves out the feature keys under `api`.
- `options.toolbarOptions` in datagrids is hand-written only.
- The symfony generator has only run against an Oro project so far. A plain Symfony project such as Marello 7 should work through `App\Kernel`, but that has not been tried yet.
