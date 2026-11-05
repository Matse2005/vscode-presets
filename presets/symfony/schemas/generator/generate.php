<?php

declare(strict_types=1);

/*
 * Generates the Symfony schemas in presets/symfony/schemas/generated: the bundle config (config*.yml, packages/*.yaml)
 * from the config trees of the installed bundles, and the service and routing files from their overlays.
 *
 * Run it inside the container through install.sh --schemas: it reads vendor/ through the container's /app mount.
 * It never boots the kernel, because a container compile fails whenever the project is half way a change.
 */

const ROOT = '/app';

require ROOT.'/vendor/autoload.php';
require __DIR__.'/schema-lib.php';

runSchemas(
    ROOT.'/.vscode/presets/symfony/schemas/generated',
    ROOT.'/.vscode/presets/symfony/schemas/overlay',
    ['symfony-config' => ['Symfony bundle config', fn (array &$skipped) => bundleConfigSchema($skipped)]],
);
