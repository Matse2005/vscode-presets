# YAML schemas

The oro preset ships JSON schemas for Oro and Symfony YAML files. The Red Hat YAML extension uses them for hover text and completion, and they are deliberately loose so they never flag config that works.

## Coverage

| Schema | Files |
| --- | --- |
| `oro-<kind>` | `config/oro/<kind>.yml`, `config/oro/<kind>/**/*.yml` and `**/Resources/config/oro/<kind>.yml` for datagrids, actions, navigation, ACLs, workflows (also split into `Resources/config/oro/workflows/**/*.yml`), processes, API, features, search and the other Oro kinds |
| `oro-layout` | Storefront layout updates in `**/Resources/views/layouts/**/*.yml` |
| `symfony-config` | `config/config*.yml`, `config/security*.yml`, `config/packages/**/*.yml` and `Resources/config/oro/app.yml`, with one property per bundle |
| `symfony-services` | `config/services*.yml`, `config/services/**/*.yml` and `Resources/config/services*.yml` |
| `symfony-routing` | `config/routing*.yml`, `config/routing/**/*.yml` and `Resources/config/oro/routing.yml` |

The exact mapping is `yaml.schemas` in `presets/oro/settings.json`. That is the only place it is defined; `validate.php` reads it from the generated `settings.json`.

## Generated and hand-written parts

`presets/oro/schemas/generator/generate.php` builds the schemas from the config trees of the Oro and Symfony versions installed in the project: each Oro config kind from the class Oro validates it with, and the bundle config from every bundle extension, the way `config:dump-reference` does. `info()` texts, defaults and enum values become the description; `example()` values and defaults become examples. Many keys, Symfony's in particular, have no `info()`, so they get completion without a description.

The overlays in `presets/oro/schemas/overlay/` are hand-written descriptions and examples, merged over the generated schemas. They cover the kinds used daily (datagrids, operations, workflows, navigation, ACLs, API, services, routing), and are the whole schema for kinds without a config tree: ACLs, service files, routing, jsmodules, assets, bundles, twig, websocket routing, entity config scopes and storefront layout updates.

The output goes to `presets/oro/schemas/generated/`, which is gitignored because it depends on the project's Oro and Symfony versions.

## Regenerating

After an Oro or Symfony upgrade, or after editing an overlay, run "Regenerate YAML schemas" or:

    .vscode/install.sh --schemas

That runs the generator and, when it succeeds, the validator in the container. To run one of them by hand for some schemas only:

    kevin app sh -c "ONLY=oro-datagrids,symfony-services php -d memory_limit=2G -d auto_prepend_file=/app/.vscode/presets/oro/oro-dotenv.php /app/.vscode/presets/oro/schemas/generator/generate.php"
    kevin app sh -c "ONLY=oro-datagrids php -d memory_limit=4G /app/.vscode/presets/oro/schemas/generator/validate.php"

The generator never boots the kernel, because a container compile fails as soon as a service points to a class that is still being written. It lists what failed at the end and then exits with 1.

## Validation

`validate.php` checks every mapped YAML file in `vendor/` and in the project against its schema, skipping folders named `var`, `node_modules`, `public`, `.git` and `Tests`. Files it cannot parse are reported as unchecked, and it exits with 1 when there are errors or unchecked files. An error means a schema is too strict, because Oro and Symfony accept those files: relax the overlay, do not change the config.

Types are loose because Symfony normalizes values before it validates them. Unknown keys are allowed, and every key accepts `~`.

## Known gaps

- `sanitize.yml` and storefront `theme.yml` have no schema; their config trees need container services.
- The API schema leaves out the feature keys under `api`.
- `options.toolbarOptions` in datagrids is hand-written only.
- The service and routing schemas are Symfony-level but live in the oro preset, because the generator builds on Oro's config classes and `AppKernel`. Splitting the generator would let the symfony preset ship them.
