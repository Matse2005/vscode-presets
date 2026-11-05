<?php

declare(strict_types=1);

/*
 * Shared by the schema generators of the presets: turns Symfony config trees into JSON schemas for the Red Hat YAML
 * extension, merges the hand-written overlays on top and writes the result. Include it after the autoloader.
 */

use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

// Symfony resolves %parameter% and %env(...)% before it validates, so an enum value may also be one of those.
const PARAMETER = ['type' => 'string', 'pattern' => '^%.+%$'];

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

function withImports(array $schema): array
{
    $schema['properties']['imports'] = [
        'description' => 'Other yml files merged into this one, relative to this file.',
        'items' => ['properties' => ['resource' => ['description' => 'Path relative to this file.']]],
        'examples' => [[['resource' => 'other_file.yml']]],
    ];

    return $schema;
}

/** config.yml and friends: one property per bundle extension alias, built like config:dump-reference does. */
function bundleConfigSchema(array &$skipped): array
{
    // A plain Symfony app (also Marello 7) has App\Kernel, Oro has AppKernel; KERNEL_CLASS overrides both.
    $class = getenv('KERNEL_CLASS') ?: (class_exists('App\\Kernel') ? 'App\\Kernel' : 'AppKernel');
    $kernel = new $class('dev', true);
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

/** Merges the overlay of $name (if any) over $generated and writes $out/$name.schema.json. */
function writeSchema(string $out, string $overlayDir, string $name, array $generated, string $title, array $recursiveKeys): void
{
    echo "writing $name\n";
    $overlayFile = "$overlayDir/$name.schema.json";
    if (is_file($overlayFile)) {
        $overlay = json_decode(file_get_contents($overlayFile), true, flags: JSON_THROW_ON_ERROR);
        $overlay = unwrapNullable(resolveRefs($overlay, $overlay));
        unset($overlay['definitions'], $overlay['$schema'], $overlay['title']);
        $generated = mergeSchema($generated, $overlay);
    }
    foreach ($recursiveKeys as $key) {
        $generated = foldRecursion($generated, $key);
    }
    $schema = ['$schema' => 'http://json-schema.org/draft-07/schema#', 'title' => $title] + emptyAsObject(allowNull($generated));
    if (false === file_put_contents("$out/$name.schema.json", json_encode($schema, JSON_FLAGS | JSON_THROW_ON_ERROR)."\n")) {
        throw new RuntimeException("Could not write $name.schema.json");
    }
    echo "wrote $name.schema.json\n";
}

/**
 * Writes every generated schema, then every overlay that has no generator (kinds without a config tree), and exits
 * with 1 when anything failed. $generators maps a schema name to [title, factory]; a factory gets the list of skipped
 * parts by reference and returns the schema. ONLY=name,prefix-* limits the run.
 *
 * @param array<string, array{string, callable(array): array}> $generators
 * @param array<string, list<string>>                           $recursiveKeys keys folded into a self-reference, per schema
 */
function runSchemas(string $out, string $overlayDir, array $generators, array $recursiveKeys = []): never
{
    is_dir($out) || mkdir($out, 0777, true);
    $only = array_filter(explode(',', (string) getenv('ONLY')));
    $wanted = fn (string $name): bool => !$only || (bool) array_filter($only, fn ($pattern) => fnmatch($pattern, $name));
    $skipped = [];
    $done = [];
    $written = 0;

    foreach ($generators as $name => [$title, $factory]) {
        if (!$wanted($name)) {
            continue;
        }
        try {
            writeSchema($out, $overlayDir, $name, $factory($skipped), $title, $recursiveKeys[$name] ?? []);
            $done[] = $name;
            ++$written;
        } catch (Throwable $error) {
            $skipped[] = "$name: ".$error->getMessage();
            $done[] = $name;
        }
    }

    foreach (glob("$overlayDir/*.schema.json") as $overlayFile) {
        $name = basename($overlayFile, '.schema.json');
        if (in_array($name, $done, true) || !$wanted($name)) {
            continue;
        }
        try {
            $title = json_decode(file_get_contents($overlayFile), true, flags: JSON_THROW_ON_ERROR)['title'] ?? $name;
            writeSchema($out, $overlayDir, $name, [], $title, $recursiveKeys[$name] ?? []);
            ++$written;
        } catch (Throwable $error) {
            $skipped[] = "$name: ".$error->getMessage();
        }
    }

    if ($skipped) {
        echo "\nskipped:\n - ".implode("\n - ", $skipped)."\n";
        exit(1);
    }
    // A typo in ONLY would otherwise pass quietly without writing anything.
    if ($only && 0 === $written) {
        echo 'No schema matched ONLY='.implode(',', $only)."\n";
        exit(1);
    }
    exit(0);
}
