<?php

declare(strict_types=1);

/*
 * Tests the schema library of the symfony preset (presets/symfony/schemas/generator/schema-lib.php) on its own, with
 * hand-built config trees and the overlays of the repo, plus the JSON schemas of the base preset. No project, container
 * or kernel is involved. Run: composer install --working-dir tests/php && php tests/php/run.php
 */

use JsonSchema\Validator;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

$repo = dirname(__DIR__, 2);
require __DIR__.'/vendor/autoload.php';
require $repo.'/presets/symfony/schemas/generator/schema-lib.php';

$passed = 0;
$failed = 0;

function check(string $description, bool $result, mixed $detail = null): void
{
    global $passed, $failed;
    if ($result) {
        ++$passed;
        echo "  ok   $description\n";

        return;
    }
    ++$failed;
    echo "  FAIL $description\n";
    if (null !== $detail) {
        echo '       | '.str_replace("\n", "\n       | ", json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n";
    }
}

function tree(callable $children): ConfigurationInterface
{
    return new class($children) implements ConfigurationInterface {
        public function __construct(private $children)
        {
        }

        public function getConfigTreeBuilder(): TreeBuilder
        {
            $builder = new TreeBuilder('root');
            ($this->children)($builder->getRootNode()->children());

            return $builder;
        }
    };
}

function json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

function validates(object|array $schema, mixed $data): bool
{
    $validator = new Validator();
    $data = json_decode(json_encode($data));
    $validator->validate($data, json_decode(json_encode($schema)));

    return $validator->isValid();
}

function tempDir(): string
{
    static $dirs = null;
    if (null === $dirs) {
        $dirs = [];
        register_shutdown_function(function () use (&$dirs) {
            foreach ($dirs as $dir) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        });
    }
    $dir = sys_get_temp_dir().'/schema-lib-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    $dirs[] = $dir;

    return $dir;
}

echo "config trees to schemas\n";
$schema = treeSchema(tree(function ($children) {
    $children
        ->scalarNode('name')->info('The name')->defaultValue('x')->example('y')->end()
        ->booleanNode('enabled')->defaultTrue()->end()
        ->enumNode('mode')->values(['fast', 'safe'])->end()
        ->arrayNode('tags')->scalarPrototype()->end()->end()
        ->arrayNode('nested')->children()->scalarNode('deep')->isRequired()->end()->end()->end()
        ->scalarNode('old')->setDeprecated('acme/app', '1.2', 'Use name.')->end();
}));
$properties = $schema['properties'];
check('info and default make the description', 'The name. Default: "x".' === ($properties['name']['description'] ?? null), $properties['name']);
check('an example() wins over the default as example', ['y'] === ($properties['name']['examples'] ?? null), $properties['name']);
check('a default becomes the example when there is no example()', [true] === ($properties['enabled']['examples'] ?? null), $properties['enabled']);
check('an enum lists its values and accepts a %parameter%', [['enum' => ['fast', 'safe']], PARAMETER] === ($properties['mode']['anyOf'] ?? null), $properties['mode']);
check('an enum names its values in the description', str_contains($properties['mode']['description'] ?? '', 'One of: "fast", "safe".'), $properties['mode']);
check('a prototyped node allows a map and a list of the prototype', array_key_exists('additionalProperties', $properties['tags']) && array_key_exists('items', $properties['tags']), $properties['tags']);
check('children of an array node become properties', isset($properties['nested']['properties']['deep']), $properties['nested']);
check('a required key says so', 'Required.' === ($properties['nested']['properties']['deep']['description'] ?? null), $properties['nested']);
check('a deprecated key says so', str_starts_with($properties['old']['description'] ?? '', 'Deprecated: Use name.'), $properties['old']);
check('no types are emitted, apart from the %parameter% branch of an enum', !str_contains(str_replace(json(PARAMETER), '', json($schema)), '"type"'), $schema);

echo "overlays\n";
$merged = mergeSchema(
    ['properties' => ['a' => ['description' => 'generated', 'examples' => [1]], 'b' => ['description' => 'kept']]],
    ['properties' => ['a' => ['description' => 'hand-written'], 'c' => ['description' => 'added']]],
);
check('the overlay description wins', 'hand-written' === $merged['properties']['a']['description'], $merged);
check('generated parts the overlay does not mention stay', [1] === $merged['properties']['a']['examples'] && 'kept' === $merged['properties']['b']['description'], $merged);
check('keys only in the overlay are added', 'added' === ($merged['properties']['c']['description'] ?? null), $merged);
$merged = mergeSchema(['anyOf' => [['enum' => ['a']]]], ['type' => 'string', 'description' => 'd']);
check('an overlay type next to a generated enum is dropped', !isset($merged['type']) && isset($merged['anyOf']), $merged);

$overlay = [
    'properties' => ['grid' => ['$ref' => '#/definitions/grid', 'description' => 'A grid']],
    'definitions' => ['grid' => ['properties' => ['children' => ['$ref' => '#/definitions/grid']], 'description' => 'from definition']],
];
$resolved = resolveRefs($overlay, $overlay);
check('a $ref is replaced by its definition', isset($resolved['properties']['grid']['properties']['children']), $resolved);
check('the description next to a $ref wins over the definition', 'A grid' === $resolved['properties']['grid']['description'], $resolved);
check('a self-reference stops instead of recursing', !str_contains(json($resolved['properties']), '$ref'), $resolved);

$unwrapped = unwrapNullable(['description' => 'outer', 'anyOf' => [['properties' => ['x' => ['type' => ['string', 'null']]]], ['type' => 'null']]]);
check('a nullable anyOf is unwrapped and keeps the outer description', 'outer' === $unwrapped['description'] && isset($unwrapped['properties']) && !isset($unwrapped['anyOf']), $unwrapped);
check('a nullable type list becomes a single type', 'string' === $unwrapped['properties']['x']['type'], $unwrapped);

$nullable = allowNull(['properties' => [
    'type' => ['type' => 'string'],
    'enum' => ['enum' => ['a']],
    'both' => ['type' => 'string', 'enum' => ['a']],
    'any' => ['anyOf' => [['enum' => ['a']]]],
]]);
check('a type accepts null', ['string', 'null'] === $nullable['properties']['type']['type'], $nullable);
check('an enum accepts null', ['a', null] === $nullable['properties']['enum']['enum'], $nullable);
check('a type with an enum accepts null in both', ['string', 'null'] === $nullable['properties']['both']['type'] && ['a', null] === $nullable['properties']['both']['enum'], $nullable);
check('an anyOf gets a null branch', in_array(['type' => 'null'], $nullable['properties']['any']['anyOf'], true), $nullable);
check('a property named "type" is treated as a property, not as a keyword', isset($nullable['properties']['type']['type']), $nullable);

$deep = ['properties' => ['menu' => ['properties' => ['children' => ['additionalProperties' => ['properties' => [
    'children' => ['additionalProperties' => ['properties' => ['children' => ['additionalProperties' => []]]]],
]]]]]]];
$folded = foldRecursion($deep, 'children');
check('a recursive key is folded into one definition', isset($folded['definitions']['children']), $folded);
check('every occurrence refers to that definition', 2 === substr_count(json($folded), '#/definitions/children'), $folded);

$encoded = json(emptyAsObject(['properties' => ['anything' => []]]));
check('an empty schema is written as {} and not as []', '{"properties":{"anything":{}}}' === $encoded, $encoded);

echo "writing and running\n";
$out = tempDir();
$overlays = tempDir();
file_put_contents("$overlays/demo.schema.json", json([
    'title' => 'ignored',
    'properties' => ['name' => ['description' => 'From the overlay', '$ref' => '#/definitions/text']],
    'definitions' => ['text' => ['type' => 'string']],
]));
writeSchema($out, $overlays, 'demo', ['properties' => ['name' => ['description' => 'generated'], 'extra' => []]], 'Demo', []);
$written = json_decode((string) file_get_contents("$out/demo.schema.json"), true);
check('the schema declares draft-07 and the given title', 'http://json-schema.org/draft-07/schema#' === ($written['$schema'] ?? null) && 'Demo' === ($written['title'] ?? null), $written);
check('the overlay is merged and its $ref resolved', 'From the overlay' === $written['properties']['name']['description'] && ['string', 'null'] === $written['properties']['name']['type'], $written);
check('the written schema accepts ~ and a string, and rejects a list', validates($written, ['name' => null]) && validates($written, ['name' => 'x']) && !validates($written, ['name' => ['x']]), $written);

$out = tempDir();
writeSchema($out, tempDir(), 'empty', [], 'Empty', []);
$empty = json_decode((string) file_get_contents("$out/empty.schema.json"), true);
check('an empty schema is written with only $schema and title', ['$schema', 'title'] === array_keys($empty ?? []), $empty);

$runner = function (string $only, string $generators) use ($repo): array {
    $out = tempDir();
    $overlays = tempDir();
    file_put_contents("$overlays/overlay-only.schema.json", '{"title": "Overlay only", "properties": {"a": {}}}');
    $script = tempDir().'/run.php';
    file_put_contents($script, '<?php require '.var_export(__DIR__.'/vendor/autoload.php', true).'; require '
        .var_export($repo.'/presets/symfony/schemas/generator/schema-lib.php', true).';'
        .'runSchemas('.var_export($out, true).', '.var_export($overlays, true).', '.$generators.');');
    $output = [];
    exec('ONLY='.escapeshellarg($only).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $code);

    return [$code, implode("\n", $output), array_map('basename', glob("$out/*.json"))];
};
$ok = "['good' => ['Good', fn () => ['properties' => ['a' => []]]]]";
$broken = "['good' => ['Good', fn () => []], 'bad' => ['Bad', function () { throw new RuntimeException('broken'); }]]";
[$code, , $files] = $runner('', $ok);
check('a successful run exits with 0', 0 === $code, $code);
check('it writes the generated and the overlay-only schemas', ['good.schema.json', 'overlay-only.schema.json'] === $files, $files);
[$code, $output, $files] = $runner('', $broken);
check('a failing generator makes the run exit with 1', 1 === $code, $output);
check('the failure is listed with the schema name', str_contains($output, 'bad: broken'), $output);
check('the other schemas are still written', in_array('good.schema.json', $files, true), $files);
[$code, $output] = $runner('goood', $ok);
check('ONLY that matches nothing exits with 1', 1 === $code && str_contains($output, 'No schema matched ONLY=goood'), $output);
[$code, , $files] = $runner('overlay-*', $ok);
check('ONLY takes patterns', 0 === $code && ['overlay-only.schema.json'] === $files, $files);

echo "bundle config\n";
final class TestBundle
{
    public function getName(): string
    {
        return 'TestBundle';
    }

    public function getContainerExtension(): object
    {
        return new class {
            public function getAlias(): string
            {
                return 'acme';
            }

            public function getConfiguration(array $config, object $container): ConfigurationInterface
            {
                return tree(fn ($children) => $children->scalarNode('api_key')->info('The API key')->end());
            }
        };
    }
}

final class TestKernel
{
    private array $bundles = [];

    public function __construct(string $environment, bool $debug)
    {
    }

    public function getBundles(): array
    {
        return $this->bundles;
    }

    protected function initializeBundles(): void
    {
        $this->bundles = [new TestBundle()];
    }

    protected function getKernelParameters(): array
    {
        return ['kernel.debug' => true];
    }
}
putenv('KERNEL_CLASS=TestKernel');
$skipped = [];
$config = bundleConfigSchema($skipped);
check('KERNEL_CLASS picks the kernel, and each extension becomes a property', 'The API key.' === ($config['properties']['acme']['properties']['api_key']['description'] ?? null), $config);
check('the bundle config refers to the services schema for services', 'symfony-services.schema.json#/properties/services' === ($config['properties']['services']['$ref'] ?? null), $config);
check('the bundle config takes imports and parameters', isset($config['properties']['imports'], $config['properties']['parameters']), array_keys($config['properties']));
check('nothing was skipped', [] === $skipped, $skipped);

echo "overlays of the repo\n";
foreach (glob($repo.'/presets/*/schemas/overlay/*.schema.json') as $file) {
    $name = basename($file, '.schema.json');
    $out = tempDir();
    try {
        writeSchema($out, dirname($file), $name, [], $name, []);
        $result = json_decode((string) file_get_contents("$out/$name.schema.json"), true, flags: JSON_THROW_ON_ERROR);
        check("$name builds without a generator", is_array($result['properties'] ?? $result['additionalProperties'] ?? null));
    } catch (Throwable $error) {
        check("$name builds without a generator", false, $error->getMessage());
    }
}
$out = tempDir();
writeSchema($out, $repo.'/presets/symfony/schemas/overlay', 'symfony-services', [], 'Services', []);
$services = json_decode((string) file_get_contents("$out/symfony-services.schema.json"));
$sample = ['services' => [
    '_defaults' => ['autowire' => true, 'autoconfigure' => true],
    'App\\Foo' => ['arguments' => ['$bar' => '@bar'], 'tags' => [['name' => 'kernel.event_listener', 'event' => 'kernel.request']]],
    'App\\Alias' => '@App\\Foo',
    'App\\Plain' => null,
]];
check('a typical services file validates against the services schema', validates($services, $sample));

echo "schemas of the base preset\n";
$settingsSchema = json_decode((string) file_get_contents($repo.'/presets/base/schemas/settings-local.schema.json'));
$presetSchema = json_decode((string) file_get_contents($repo.'/presets/base/schemas/preset.schema.json'));
$default = json_decode((string) file_get_contents($repo.'/settings.local.default.json'));
check('settings.local.default.json matches its schema', validates($settingsSchema, $default));
check('an unknown preset name is rejected', !validates($settingsSchema, ['vscodePresets.enabled' => ['nope']]));
check('a placeholder value must be a string', !validates($settingsSchema, ['vscodePresets.oroDocumentation' => 5]) && !validates($settingsSchema, ['vscodePresets.something' => true]));
check('a runner of only spaces is rejected', !validates($settingsSchema, ['vscodePresets.runner' => '   ']) && validates($settingsSchema, ['vscodePresets.runner' => 'kevin app']));
$names = array_values(array_filter(array_map('basename', glob($repo.'/presets/*', GLOB_ONLYDIR)), fn ($name) => 'base' !== $name));
$enum = json_decode((string) file_get_contents($repo.'/presets/base/schemas/settings-local.schema.json'), true)['properties']['vscodePresets.enabled']['items']['enum'];
sort($names);
sort($enum);
check('the schema lists exactly the presets in presets/ (without base)', $names === $enum, ['folders' => $names, 'schema' => $enum]);
foreach (glob($repo.'/presets/*/preset.json') as $file) {
    check(basename(dirname($file)).'/preset.json matches its schema', validates($presetSchema, json_decode((string) file_get_contents($file))));
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
