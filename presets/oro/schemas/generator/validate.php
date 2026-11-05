<?php

declare(strict_types=1);

/*
 * Checks every YAML file that settings.json maps to a schema against that schema, in vendor/ and in the project.
 * The schemas are only meant for hover and completion, so any error here means a schema is too strict, not that the
 * config is wrong: Oro and Symfony accept these files.
 */

use JsonSchema\Validator;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

const ROOT = '/app';

require ROOT.'/vendor/autoload.php';

if (!class_exists(Validator::class)) {
    fwrite(STDERR, "justinrainbow/json-schema is not installed in vendor/.\n");
    exit(1);
}

// settings.json is JSONC: install.sh starts it with a // comment line.
$settings = json_decode(
    preg_replace('#^\s*//.*$#m', '', file_get_contents(ROOT.'/.vscode/settings.json')),
    true,
    flags: JSON_THROW_ON_ERROR
);
$mapping = $settings['yaml.schemas'];

function globToRegex(string $glob): string
{
    $regex = preg_quote($glob, '#');
    $regex = str_replace(['\*\*/', '\*\*', '\*'], ['(?:.*/)?', '.*', '[^/]*'], $regex);

    return '#^'.$regex.'$#';
}

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator(ROOT, FilesystemIterator::SKIP_DOTS),
    fn (SplFileInfo $file) => !in_array($file->getFilename(), ['var', 'node_modules', 'public', '.git', 'Tests'], true)
));
foreach ($iterator as $file) {
    if (str_ends_with($file->getFilename(), '.yml')) {
        $files[] = substr($file->getPathname(), strlen(ROOT) + 1);
    }
}

/** Custom tags (!tagged_iterator, !php/const, ...) are kept as plain values, the way the schemas expect them. */
function plain(mixed $value): mixed
{
    if ($value instanceof TaggedValue) {
        return plain($value->getValue());
    }
    if ($value instanceof stdClass) {
        return (object) array_map(plain(...), get_object_vars($value));
    }

    return is_array($value) ? array_map(plain(...), $value) : $value;
}

$only = array_filter(explode(',', (string) getenv('ONLY')));
$total = 0;
foreach ($mapping as $schemaPath => $globs) {
    $name = basename($schemaPath, '.schema.json');
    if ($only && !in_array($name, $only, true)) {
        continue;
    }
    $schemaFile = ROOT.'/'.preg_replace('#^\./#', '', $schemaPath);
    // A file:// reference lets the validator resolve the relative $ref to symfony-services.schema.json.
    $schema = (object) ['$ref' => 'file://'.$schemaFile];
    $regexes = array_map(globToRegex(...), $globs);
    $matched = array_filter($files, fn ($file) => array_filter($regexes, fn ($regex) => preg_match($regex, $file)));

    $errors = 0;
    $unchecked = 0;
    foreach ($matched as $file) {
        try {
            $data = plain(Yaml::parseFile(ROOT.'/'.$file, Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_CONSTANT | Yaml::PARSE_OBJECT_FOR_MAP));
            // Validate the YAML the way the editor sees it: maps as objects, lists as arrays.
            $data = json_decode(json_encode($data ?? new stdClass(), JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            ++$unchecked;
            echo "  $file: not checked: {$error->getMessage()}\n";
            continue;
        }
        $validator = new Validator();
        $validator->validate($data, $schema, JsonSchema\Constraints\Constraint::CHECK_MODE_NORMAL);
        foreach ($validator->getErrors() as $error) {
            if (++$errors <= 5) {
                echo "  $file: {$error['property']}: {$error['message']}\n";
            }
        }
    }
    $total += $errors + $unchecked;
    printf("%-28s %4d files %5d errors %3d unchecked\n", $name, count($matched) - $unchecked, $errors, $unchecked);
}

exit($total > 0 ? 1 : 0);
