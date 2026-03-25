<?php

declare(strict_types=1);

namespace PushPull\Plugin;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    private static function autoload(string $class): void
    {
        $prefix = 'PushPull\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path = PUSHPULL_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
