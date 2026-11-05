<?php

declare(strict_types=1);

// Fail soft: a fatal error here would stop the LSP bridge and hide the cause behind its protocol output.
$autoload = '/app/vendor/autoload.php';
if (!is_file($autoload) || !is_file('/app/.env-app')) {
    return;
}

require $autoload;

try {
    (new Symfony\Component\Dotenv\Dotenv('ORO_ENV', 'ORO_DEBUG'))->loadEnv('/app/.env-app');
} catch (Throwable $error) {
    error_log('oro-dotenv.php: '.$error->getMessage());
}
