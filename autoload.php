<?php

declare(strict_types=1);

/**
 * Minimal PSR-4-style autoloader for KintB24 namespace.
 * No composer needed.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'KintB24\\';
    $base   = __DIR__ . '/src/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
