<?php

declare(strict_types=1);

/*
 * Generates the Oro schemas in presets/oro/schemas/generated from the config classes Oro validates each config kind
 * with, then merges the hand-written overlays from presets/oro/schemas/overlay on top. Builds on the shared library of
 * the symfony preset, which the oro preset requires.
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
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Yaml\Yaml;

const ROOT = '/app';

require ROOT.'/vendor/autoload.php';
require __DIR__.'/../../../symfony/schemas/generator/schema-lib.php';

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

$generators = [];
foreach ($oroKinds as $kind => $factory) {
    $generators['oro-'.str_replace('_', '-', $kind)] = ["Oro $kind", fn () => fileSchema($kind, $factory())];
}
$generators['oro-datagrids'] = ['Oro datagrids', fn (array &$skipped) => datagridSchema($skipped)];
$generators['oro-workflows'] = ['Oro workflows', fn () => workflowSchema()];
$generators['oro-processes'] = ['Oro processes', fn () => processSchema()];

runSchemas(
    ROOT.'/.vscode/presets/oro/schemas/generated',
    ROOT.'/.vscode/presets/oro/schemas/overlay',
    $generators,
    ['oro-navigation' => ['children'], 'oro-search' => ['relation_fields']],
);
