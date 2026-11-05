<?php

declare(strict_types=1);

/*
 * Generates the JSON schemas in presets/oro/schemas/generated from the config trees of the installed Oro and Symfony
 * versions, then merges the hand-written descriptions and examples from presets/oro/schemas/overlay on top.
 *
 * Run it inside the container through install.sh --schemas: it reads vendor/ through the container's /app mount.
 * It never boots the kernel, because a container compile fails whenever the project is half way a change.
 */

use Oro\Bundle\ApiBundle\Config\Definition\ApiConfiguration;
use Oro\Bundle\ApiBundle\Config\Extension as ApiExtension;
use Oro\Bundle\ApiBundle\Filter\FilterOperatorRegistry;
use Oro\Bundle\ApiBundle\PostProcessor\PostProcessorRegistry;
use Oro\Bundle\ApiBundle\Processor\ActionProcessorBagInterface;
use Oro\Bundle\ApiBundle\Util\RequestExpressionMatcher;
use Oro\Bundle\DataGridBundle\Extension as Grid;
use Oro\Bundle\FeatureToggleBundle\Configuration\ConfigurationExtension;
use Oro\Bundle\FeatureToggleBundle\Configuration\FeatureToggleConfiguration;
use Oro\Bundle\WorkflowBundle\Configuration as Workflow;
use Oro\Component\ChainProcessor\ActionProcessorInterface;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Yaml\Yaml;

const ROOT = '/app';
const OUT = ROOT.'/.vscode/presets/oro/schemas/generated';
const OVERLAY = ROOT.'/.vscode/presets/oro/schemas/overlay';
const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

// Symfony resolves %parameter% and %env(...)% before it validates, so an enum value may also be one of those.
const PARAMETER = ['type' => 'string', 'pattern' => '^%.+%$'];
const RECURSIVE_KEYS = ['oro-navigation' => ['children'], 'oro-search' => ['relation_fields']];

require ROOT.'/vendor/autoload.php';

is_dir(OUT) || mkdir(OUT, 0777, true);

$skipped = [];
$written = [];
// ONLY=oro-navigation,oro-api regenerates just those schemas.
$only = array_filter(explode(',', (string) getenv('ONLY')));

function treeSchema(ConfigurationInterface $configuration): array
{
    return nodeSchema($configuration->getConfigTreeBuilder()->buildTree());
}

/**
 * Types are left out on purpose: before validation Symfony normalizes a lot (true to enabled: true, a string to a
 * list, ...), so a type in the schema would flag config that works. Keys, enums, descriptions and examples are what
 * the editor needs for hover and completion.
 */
function nodeSchema(NodeInterface $node): array
{
    $schema = [];
    $description = [];

    if ($node instanceof BaseNode) {
        if ($node->getInfo()) {
            $description[] = rtrim($node->getInfo(), '.').'.';
        }
        if ($node->isDeprecated()) {
            $description[] = 'Deprecated: '.$node->getDeprecation($node->getName(), $node->getPath())['message'];
        }
        if (null !== $node->getExample() && jsonable($node->getExample())) {
            $schema['examples'] = [$node->getExample()];
        }
    }

    if ($node instanceof EnumNode && $node->getValues()) {
        $values = array_map(enumValue(...), $node->getValues());
        $schema['anyOf'] = [['enum' => $values], PARAMETER];
        $description[] = 'One of: '.implode(', ', array_map(fn ($value) => json_encode($value), $values)).'.';
    } elseif ($node instanceof PrototypedArrayNode) {
        $prototype = nodeSchema($node->getPrototype());
        $schema['additionalProperties'] = $prototype;
        $schema['items'] = $prototype;
    } elseif ($node instanceof ArrayNode) {
        foreach ($node->getChildren() as $name => $child) {
            $schema['properties'][$name] = nodeSchema($child);
        }
    }

    if (!$node instanceof ArrayNode && $node->hasDefaultValue()) {
        $default = $node->getDefaultValue();
        if (is_scalar($default)) {
            $description[] = 'Default: '.json_encode($default, JSON_UNESCAPED_SLASHES).'.';
            $schema['examples'] ??= '' === $default ? null : [$default];
        }
    }
    if ($node->isRequired()) {
        $description[] = 'Required.';
    }
    if ($description) {
        $schema['description'] = implode(' ', $description);
    }

    return array_filter($schema, fn ($value) => null !== $value);
}

function enumValue(mixed $value): mixed
{
    return $value instanceof UnitEnum ? '!php/enum '.$value::class.'::'.$value->name : $value;
}

function jsonable(mixed $value): bool
{
    return false !== json_encode($value);
}

/** Every file of one Oro config kind in vendor/ and the project, used to find out how the kind is wrapped. */
function oroFiles(string $kind): array
{
    static $all;
    if (null === $all) {
        $all = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT.'/vendor', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (preg_match('#/Resources/config/oro/([a-z_]+)\.yml$#', $path, $match) && !str_contains($path, '/Tests/')) {
                $all[$match[1]][] = $path;
            }
        }
        foreach (glob(ROOT.'/config/oro/*', GLOB_ONLYDIR) as $dir) {
            $all[basename($dir)] = array_merge($all[basename($dir)] ?? [], glob($dir.'/*.yml'));
        }
    }

    return $all[$kind] ?? [];
}

function topKeys(string $file): array
{
    try {
        $data = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
    } catch (Throwable) {
        return [];
    }

    return is_array($data) ? array_keys($data) : [];
}

/**
 * Some providers hand the whole file to the config tree, others only the value under the tree's root key. The files
 * themselves tell which one it is: when they start with the root key, the tree sits under that key.
 */
function fileSchema(string $kind, ConfigurationInterface $configuration): array
{
    $tree = $configuration->getConfigTreeBuilder()->buildTree();
    $schema = nodeSchema($tree);
    $wrapped = 0;
    foreach (oroFiles($kind) as $file) {
        $wrapped += in_array($tree->getName(), topKeys($file), true) ? 1 : -1;
    }

    return $wrapped > 0 ? withImports(['properties' => [$tree->getName() => $schema]]) : withImports($schema);
}

function withImports(array $schema): array
{
    $schema['properties']['imports'] = [
        'description' => 'Other yml files merged into this one, relative to this file.',
        'items' => ['properties' => ['resource' => ['description' => 'Path relative to this file.']]],
        'examples' => [[['resource' => 'other_file.yml']]],
    ];

    return $schema;
}

/** Values of one attribute of a service tag, collected from every service yml, for config trees that check them. */
function tagAttributeValues(string $tag, string $attribute): array
{
    $values = [];
    $files = new AppendIterator();
    foreach ([ROOT.'/vendor', ROOT.'/config'] as $dir) {
        $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)));
    }
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.yml') || !(str_contains($path, '/Resources/config/') || str_starts_with($path, ROOT.'/config/'))) {
            continue;
        }
        preg_match_all(
            '#name:\s*[\'"]?'.preg_quote($tag, '#').'[\'"]?\s*,[^}]*?\b'.preg_quote($attribute, '#').':\s*[\'"]?([\w.-]+)#',
            file_get_contents($file->getPathname()),
            $matches
        );
        array_push($values, ...$matches[1]);
    }
    $values = array_values(array_unique($values));
    sort($values);

    return $values;
}

function apiConfiguration(): ApiConfiguration
{
    $actions = tagAttributeValues('oro.api.processor', 'action');
    $actionBag = new class($actions) implements ActionProcessorBagInterface {
        public function __construct(private array $actions)
        {
        }

        public function addProcessor(ActionProcessorInterface $processor): void
        {
        }

        public function getProcessor(string $action): ActionProcessorInterface
        {
            throw new LogicException('Only the action names are needed to build the config tree.');
        }

        public function getActions(): array
        {
            return $this->actions;
        }
    };

    $registry = new ApiExtension\ConfigExtensionRegistry(1);
    $registry->addExtension(new ApiExtension\FiltersConfigExtension(new FilterOperatorRegistry([])));
    $registry->addExtension(new ApiExtension\SortersConfigExtension());
    $registry->addExtension(new ApiExtension\ActionsConfigExtension($actionBag));
    $registry->addExtension(new ApiExtension\SubresourcesConfigExtension($actionBag, new FilterOperatorRegistry([])));
    $registry->addExtension(new ApiExtension\PostProcessorConfigExtension(
        new PostProcessorRegistry([], new Container(), new RequestExpressionMatcher())
    ));
    $registry->addExtension(new ApiExtension\TwigPostProcessorConfigExtension());

    return new ApiConfiguration($registry, 1);
}

function featureConfiguration(): FeatureToggleConfiguration
{
    $extensions = [];
    foreach (get_declared_classes_in('FeatureConfigurationExtension') as $class) {
        $extensions[] = new $class();
    }

    return new FeatureToggleConfiguration(new ConfigurationExtension($extensions));
}

/** Feature extensions live in several bundles under the same short name; find them through the class map. */
function get_declared_classes_in(string $shortName): array
{
    $classes = [];
    foreach (require ROOT.'/vendor/composer/autoload_classmap.php' as $class => $file) {
        if (str_ends_with($class, '\\'.$shortName) && !str_contains($file, '/Tests/')
            && is_subclass_of($class, Oro\Bundle\FeatureToggleBundle\Configuration\ConfigurationExtensionInterface::class)
            && !(new ReflectionClass($class))->getConstructor()?->getNumberOfRequiredParameters()
        ) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/** A datagrid is checked piece by piece by its extensions; this puts each extension's tree where it reads it. */
function datagridSchema(array &$skipped): array
{
    $propertyTypes = tagAttributeValues('oro_datagrid.extension.formatter.property', 'type');
    $filterTypes = tagAttributeValues('oro_filter.extension.orm_filter.filter', 'type');

    $parts = [
        'columns' => fn () => new Grid\Formatter\Configuration($propertyTypes, 'columns'),
        'properties' => fn () => new Grid\Formatter\Configuration($propertyTypes, 'properties'),
        'sorters' => fn () => new Grid\Sorter\Configuration(),
        'filters' => fn () => new Oro\Bundle\FilterBundle\Grid\Extension\Configuration($filterTypes),
        'totals' => fn () => new Grid\Totals\Configuration(),
        'inline_editing' => fn () => new Grid\InlineEditing\Configuration('inline_editing'),
        'appearances' => fn () => new Grid\Appearance\Configuration(),
        'fields_acl' => fn () => new Grid\FieldAcl\Configuration(),
        'features' => fn () => new Grid\Feature\Configuration(),
    ];
    $grid = [];
    foreach ($parts as $key => $factory) {
        try {
            $grid['properties'][$key] = treeSchema($factory());
        } catch (Throwable $error) {
            $skipped[] = "datagrids.$key: ".$error->getMessage();
        }
    }

    $nested = [
        'source.query' => fn () => new Oro\Bundle\DataGridBundle\Datasource\Orm\QueryConverter\QueryConfiguration(),
        'options.export' => fn () => new Grid\Export\Configuration(),
        'mass_actions.delete' => fn () => new Grid\MassAction\DeleteMassActionConfiguration(),
    ];
    foreach ($nested as $path => $factory) {
        [$parent, $key] = explode('.', $path);
        try {
            $grid['properties'][$parent]['properties'][$key] = treeSchema($factory());
        } catch (Throwable $error) {
            $skipped[] = "datagrids.$path: ".$error->getMessage();
        }
    }
    try {
        $grid['properties']['appearances']['properties']['board']['additionalProperties']
            = treeSchema(new Grid\Board\Configuration());
    } catch (Throwable $error) {
        $skipped[] = 'datagrids.appearances.board: '.$error->getMessage();
    }

    return withImports(['properties' => ['datagrids' => ['additionalProperties' => $grid]]]);
}

function workflowSchema(): array
{
    $list = new Workflow\WorkflowListConfiguration(new Workflow\WorkflowConfiguration());

    return withImports(['properties' => ['workflows' => treeSchema($list)]]);
}

function processSchema(): array
{
    $definitions = new Workflow\ProcessDefinitionListConfiguration(new Workflow\ProcessDefinitionConfiguration());
    $triggers = new Workflow\ProcessTriggerListConfiguration(new Workflow\ProcessTriggerConfiguration());

    return withImports(['properties' => ['processes' => ['properties' => [
        'definitions' => treeSchema($definitions),
        'triggers' => treeSchema($triggers),
    ]]]]);
}

/** config.yml and friends: one property per bundle extension alias, built like config:dump-reference does. */
function bundleConfigSchema(array &$skipped): array
{
    $kernel = new AppKernel('dev', true);
    (fn () => $this->initializeBundles())->call($kernel);
    $parameters = (fn () => $this->getKernelParameters())->call($kernel);
    $container = new ContainerBuilder(new ParameterBag($parameters));

    $schema = ['properties' => []];
    foreach ($kernel->getBundles() as $bundle) {
        try {
            $extension = $bundle->getContainerExtension();
            $configuration = $extension?->getConfiguration([], $container);
            if ($configuration instanceof ConfigurationInterface) {
                $schema['properties'][$extension->getAlias()] = treeSchema($configuration);
            }
        } catch (Throwable $error) {
            $skipped[] = 'bundle '.$bundle->getName().': '.$error->getMessage();
        }
    }
    ksort($schema['properties']);
    $schema['properties']['parameters'] = [
        'description' => 'Container parameters, used elsewhere as %name%.',
        'examples' => [['app.parameter' => 'value']],
    ];
    $schema['properties']['services'] = ['$ref' => 'symfony-services.schema.json#/properties/services'];

    return withImports($schema);
}

// Overlay handling. Overlays use $ref and nullable wrappers for readability; both are flattened before the merge so
// that generated keys and hand-written descriptions end up in the same node.

function resolveRefs(mixed $schema, array $root, array $stack = []): mixed
{
    if (!is_array($schema)) {
        return $schema;
    }
    if (isset($schema['$ref']) && str_starts_with($schema['$ref'], '#/definitions/')) {
        $name = substr($schema['$ref'], strlen('#/definitions/'));
        unset($schema['$ref']);
        // A recursive overlay definition (menu children) stops here; the generated tree already has the depth.
        $target = in_array($name, $stack, true)
            ? []
            : resolveRefs($root['definitions'][$name], $root, [...$stack, $name]);

        return mergeSchema($target, resolveRefs($schema, $root, $stack));
    }
    foreach ($schema as $key => $value) {
        if ('definitions' !== $key) {
            $schema[$key] = resolveRefs($value, $root, $stack);
        }
    }

    return $schema;
}

function unwrapNullable(mixed $schema): mixed
{
    if (!is_array($schema) || isList($schema)) {
        return $schema;
    }
    if (isset($schema['anyOf']) && 2 === count($schema['anyOf']) && in_array(['type' => 'null'], $schema['anyOf'], true)) {
        $inner = array_values(array_filter($schema['anyOf'], fn ($branch) => ['type' => 'null'] !== $branch))[0];
        unset($schema['anyOf']);
        $schema = mergeSchema($inner, $schema);
    }
    if (isset($schema['type']) && is_array($schema['type'])) {
        $types = array_values(array_diff($schema['type'], ['null']));
        $schema['type'] = 1 === count($types) ? $types[0] : $types;
    }
    if (isset($schema['enum'])) {
        $schema['enum'] = array_values(array_filter($schema['enum'], fn ($value) => null !== $value));
    }

    return mapSubschemas($schema, unwrapNullable(...));
}

/** Applies $callback to every nested schema, and never to a properties map whose keys could look like keywords. */
function mapSubschemas(array $schema, callable $callback): array
{
    foreach (['properties', 'definitions'] as $key) {
        if (isset($schema[$key]) && is_array($schema[$key])) {
            $schema[$key] = array_map($callback, $schema[$key]);
        }
    }
    foreach (['additionalProperties', 'items'] as $key) {
        if (isset($schema[$key]) && is_array($schema[$key])) {
            $schema[$key] = $callback($schema[$key]);
        }
    }
    if (isset($schema['anyOf'])) {
        $schema['anyOf'] = array_map($callback, $schema['anyOf']);
    }

    return $schema;
}

function isList(mixed $value): bool
{
    return is_array($value) && array_is_list($value);
}

/** The overlay wins on every value; nested schemas are merged so generated keys the overlay does not mention stay. */
function mergeSchema(array $generated, array $overlay): array
{
    foreach ($overlay as $key => $value) {
        if (in_array($key, ['properties', 'definitions'], true) && is_array($value) && is_array($generated[$key] ?? null)) {
            foreach ($value as $name => $child) {
                $generated[$key][$name] = is_array($generated[$key][$name] ?? null) && is_array($child) && !isList($child)
                    ? mergeSchema($generated[$key][$name], $child)
                    : $child;
            }
        } elseif (in_array($key, ['additionalProperties', 'items'], true) && is_array($value) && !isList($value)
            && is_array($generated[$key] ?? null) && !isList($generated[$key])
        ) {
            $generated[$key] = mergeSchema($generated[$key], $value);
        } else {
            $generated[$key] = $value;
        }
    }

    // A generated enum is exact; an overlay type next to it would only loosen or contradict it.
    if (isset($generated['anyOf'], $generated['type'])) {
        unset($generated['type']);
    }

    return $generated;
}

/** Oro config accepts ~ for almost every key (reset in an extending grid, an empty list), so everything allows null. */
function allowNull(mixed $schema): mixed
{
    if (!is_array($schema) || isList($schema)) {
        return $schema;
    }
    foreach (['properties', 'definitions'] as $key) {
        foreach ($schema[$key] ?? [] as $name => $child) {
            $schema[$key][$name] = allowNull($child);
        }
    }
    foreach (['additionalProperties', 'items'] as $key) {
        if (isset($schema[$key]) && is_array($schema[$key])) {
            $schema[$key] = allowNull($schema[$key]);
        }
    }
    if (isset($schema['anyOf'])) {
        $schema['anyOf'] = array_map(allowNull(...), $schema['anyOf']);
        if (!in_array(['type' => 'null'], $schema['anyOf'], true)) {
            $schema['anyOf'][] = ['type' => 'null'];
        }
    } else {
        if (isset($schema['type'])) {
            $schema['type'] = array_values(array_unique([...(array) $schema['type'], 'null']));
        }
        if (isset($schema['enum']) && !in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }
    }

    return $schema;
}

/**
 * Config trees with a recursive key (menu children, search relation fields) are built a fixed number of levels deep,
 * which makes the schema megabytes large. This folds every occurrence of $key into one self-referencing definition.
 */
function foldRecursion(array $schema, string $key): array
{
    $definition = null;
    $replace = function (array $node) use (&$replace, &$definition, $key): array {
        if (isset($node['properties'][$key]) && is_array($node['properties'][$key])) {
            $definition ??= $node['properties'][$key];
            $node['properties'][$key] = array_filter([
                'description' => $node['properties'][$key]['description'] ?? null,
                'anyOf' => [['$ref' => '#/definitions/'.$key]],
            ]);
        }

        return mapSubschemas($node, fn ($child) => is_array($child) && !isList($child) ? $replace($child) : $child);
    };
    $schema = $replace($schema);
    if (null !== $definition) {
        unset($definition['description']);
        $schema['definitions'][$key] = mapSubschemas($definition, fn ($child) => is_array($child) && !isList($child) ? $replace($child) : $child);
    }

    return $schema;
}

/** An empty schema means "anything", but PHP would encode it as the JSON list [], which is not a schema. */
function emptyAsObject(array $schema): array|stdClass
{
    $schema = mapSubschemas($schema, fn ($child) => is_array($child) && !isList($child) ? emptyAsObject($child) : ([] === $child ? new stdClass() : $child));

    return [] === $schema ? new stdClass() : $schema;
}

function write(string $name, array $generated, string $title): void
{
    global $written;
    echo "writing $name\n";
    $overlayFile = OVERLAY."/$name.schema.json";
    if (is_file($overlayFile)) {
        $overlay = json_decode(file_get_contents($overlayFile), true, flags: JSON_THROW_ON_ERROR);
        $overlay = unwrapNullable(resolveRefs($overlay, $overlay));
        unset($overlay['definitions'], $overlay['$schema'], $overlay['title']);
        $generated = mergeSchema($generated, $overlay);
    }
    foreach (RECURSIVE_KEYS[$name] ?? [] as $key) {
        $generated = foldRecursion($generated, $key);
    }
    $schema = ['$schema' => 'http://json-schema.org/draft-07/schema#', 'title' => $title] + emptyAsObject(allowNull($generated));
    if (false === file_put_contents(OUT."/$name.schema.json", json_encode($schema, JSON_FLAGS | JSON_THROW_ON_ERROR)."\n")) {
        throw new RuntimeException("Could not write $name.schema.json");
    }
    $written[] = $name;
    echo "wrote $name.schema.json\n";
}

$oroKinds = [
    'acl_categories' => fn () => new Oro\Bundle\UserBundle\Configuration\PrivilegeCategoryConfiguration(),
    'actions' => fn () => new Oro\Bundle\ActionBundle\Configuration\Configuration(),
    'address_format' => fn () => new Oro\Bundle\LocaleBundle\Configuration\AddressFormatConfiguration(),
    'api' => apiConfiguration(...),
    'charts' => fn () => new Oro\Bundle\ChartBundle\Model\Configuration(),
    'configurable_permissions' => fn () => new Oro\Bundle\SecurityBundle\Configuration\ConfigurablePermissionConfiguration(),
    'dashboards' => fn () => new Oro\Bundle\DashboardBundle\Model\Configuration(),
    'entity' => fn () => new Oro\Bundle\EntityBundle\Configuration\EntityConfiguration(),
    'entity_extend' => fn () => new Oro\Bundle\EntityExtendBundle\Configuration\EntityExtendConfiguration(),
    'features' => featureConfiguration(...),
    'help' => fn () => new Oro\Bundle\HelpBundle\Configuration\HelpConfiguration(),
    'integrations' => fn () => new Oro\Bundle\IntegrationBundle\DependencyInjection\IntegrationConfiguration(),
    'locale_data' => fn () => new Oro\Bundle\LocaleBundle\Configuration\LocaleDataConfiguration(),
    'name_format' => fn () => new Oro\Bundle\LocaleBundle\Configuration\NameFormatConfiguration(),
    'navigation' => fn () => new Oro\Bundle\NavigationBundle\Configuration\NavigationConfiguration(),
    'organization_types' => fn () => new Oro\Bundle\OrganizationProBundle\Provider\OrganizationTypeConfiguration(),
    'permissions' => fn () => new Oro\Bundle\SecurityBundle\Configuration\PermissionConfiguration(),
    'placeholders' => fn () => new Oro\Bundle\UIBundle\Placeholder\PlaceholderConfiguration(),
    'query_designer' => fn () => new Oro\Bundle\QueryDesignerBundle\QueryDesigner\Configuration(
        tagAttributeValues('oro_filter.extension.orm_filter.filter', 'type')
    ),
    'search' => fn () => new Oro\Bundle\SearchBundle\Configuration\MappingConfiguration(),
];

foreach ($oroKinds as $kind => $factory) {
    if ($only && !in_array('oro-'.str_replace('_', '-', $kind), $only, true)) {
        continue;
    }
    try {
        write('oro-'.str_replace('_', '-', $kind), fileSchema($kind, $factory()), "Oro $kind");
    } catch (Throwable $error) {
        $skipped[] = "$kind: ".$error->getMessage();
    }
}

$special = [
    'oro-datagrids' => ['Oro datagrids', function () use (&$skipped) {
        return datagridSchema($skipped);
    }],
    'oro-workflows' => ['Oro workflows', workflowSchema(...)],
    'oro-processes' => ['Oro processes', processSchema(...)],
    'symfony-config' => ['Symfony and Oro bundle config', function () use (&$skipped) {
        return bundleConfigSchema($skipped);
    }],
];
foreach ($special as $name => [$title, $factory]) {
    if ($only && !in_array($name, $only, true)) {
        continue;
    }
    try {
        write($name, $factory(), $title);
    } catch (Throwable $error) {
        $skipped[] = "$name: ".$error->getMessage();
    }
}

// Kinds without a config tree (ACLs, services, routing, jsmodules, ...) only have their hand-written overlay.
foreach (glob(OVERLAY.'/*.schema.json') as $overlayFile) {
    $name = basename($overlayFile, '.schema.json');
    $failed = array_filter($skipped, fn ($line) => str_starts_with($line, $name.':')
        || str_starts_with($line, str_replace('-', '_', substr($name, 4)).':'));
    if (in_array($name, $written, true) || $failed || ($only && !in_array($name, $only, true))) {
        continue;
    }
    try {
        write($name, [], json_decode(file_get_contents($overlayFile), true, flags: JSON_THROW_ON_ERROR)['title'] ?? $name);
    } catch (Throwable $error) {
        $skipped[] = "$name: ".$error->getMessage();
    }
}

if ($skipped) {
    echo "\nskipped:\n - ".implode("\n - ", $skipped)."\n";
    exit(1);
}
