<?php

declare(strict_types=1);

if (! defined('PUSHPULL_VERSION')) {
    define('PUSHPULL_VERSION', 'dev');
}

if (! defined('PUSHPULL_PLUGIN_URL')) {
    define('PUSHPULL_PLUGIN_URL', 'http://example.test/wp-content/plugins/pushpull/');
}

if (! defined('PUSHPULL_PLUGIN_DIR')) {
    define('PUSHPULL_PLUGIN_DIR', __DIR__ . '/');
}

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (! function_exists('dbDelta')) {
    function dbDelta(string $sql): array
    {
        return [$sql];
    }
}
