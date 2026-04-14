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

if (! class_exists('WP_CLI_Command')) {
    class WP_CLI_Command
    {
    }
}

if (! class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static function line(string $message): void
        {
        }

        public static function success(string $message): void
        {
        }

        public static function warning(string $message): void
        {
        }

        /**
         * @return never
         */
        public static function error(string $message): void
        {
            throw new RuntimeException($message);
        }

        public static function add_command(string $name, object $command): void
        {
        }
    }
}

if (! class_exists('WPML_Installation')) {
    class WPML_Installation
    {
        public function __construct(object $wpdb, object $sitepress)
        {
        }

        public function finish_step1(string $initialLanguageCode): void
        {
        }

        /**
         * @param string[] $activeLanguages
         */
        public function finish_step2(array $activeLanguages): bool
        {
            return true;
        }

        /**
         * @param string[] $activeLanguages
         */
        public function set_active_languages(array $activeLanguages): bool
        {
            return true;
        }

        public function finish_step3(): void
        {
        }

        public function finish_installation(): void
        {
        }
    }
}

if (! function_exists('dbDelta')) {
    function dbDelta(string $sql): array
    {
        return [$sql];
    }
}

if (! function_exists('_wp_rml_root')) {
    function _wp_rml_root(): int
    {
        return -1;
    }
}

if (! function_exists('wp_attachment_folder')) {
    function wp_attachment_folder(int|array $attachmentId, mixed $default = null): mixed
    {
        return $default;
    }
}

if (! function_exists('wp_rml_get_by_id')) {
    function wp_rml_get_by_id(int $id, ?array $allowed = null, bool $mustBeFolderObject = false, bool $nullForRoot = true): ?object
    {
        return null;
    }
}

if (! function_exists('wp_rml_get_by_absolute_path')) {
    function wp_rml_get_by_absolute_path(string $path, ?array $allowed = null): ?object
    {
        return null;
    }
}

if (! function_exists('wp_rml_create_or_return_existing_id')) {
    function wp_rml_create_or_return_existing_id(
        string $name,
        int $parent,
        int $type,
        array $restrictions = [],
        bool $supress_validation = false
    ): int|array {
        return 0;
    }
}

if (! function_exists('wp_rml_move')) {
    function wp_rml_move(int $folderId, array $attachmentIds, bool $supress_validation = false, bool $isShortcut = false): bool|array
    {
        return true;
    }
}
