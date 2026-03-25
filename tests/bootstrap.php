<?php

declare(strict_types=1);

global $pushpull_test_options;

$pushpull_test_options ??= [];
$GLOBALS['pushpull_test_generateblocks_posts'] ??= [];
$GLOBALS['pushpull_test_generateblocks_meta'] ??= [];
$GLOBALS['pushpull_test_next_post_id'] ??= 1;

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';

        return trim($title, '-');
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        $key = strtolower($key);

        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        $value = strip_tags($value);

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return '2026-03-24 09:00:00';
    }
}

if (! function_exists('post_type_exists')) {
    function post_type_exists(string $postType): bool
    {
        return $postType === 'gblocks_styles';
    }
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public function __construct(
            public int $ID = 0,
            public string $post_title = '',
            public string $post_name = '',
            public string $post_status = 'publish',
            public int $menu_order = 0
        ) {
        }
    }
}

if (! function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        $posts = $GLOBALS['pushpull_test_generateblocks_posts'] ?? [];
        usort(
            $posts,
            static fn (WP_Post $left, WP_Post $right): int => $left->ID <=> $right->ID
        );

        return $posts;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key, bool $single = false): mixed
    {
        $value = $GLOBALS['pushpull_test_generateblocks_meta'][$postId][$key] ?? ($single ? '' : []);

        return $single ? $value : [$value];
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        $GLOBALS['pushpull_test_generateblocks_meta'][$postId][$key] = $value;

        return true;
    }
}

if (! function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $key): bool
    {
        unset($GLOBALS['pushpull_test_generateblocks_meta'][$postId][$key]);

        return true;
    }
}

if (! function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr): int
    {
        $id = (int) ($GLOBALS['pushpull_test_next_post_id'] ?? 1);
        $GLOBALS['pushpull_test_next_post_id'] = $id + 1;
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new WP_Post(
            $id,
            (string) ($postarr['post_title'] ?? ''),
            (string) ($postarr['post_name'] ?? ''),
            (string) ($postarr['post_status'] ?? 'publish'),
            (int) ($postarr['menu_order'] ?? 0)
        );

        return $id;
    }
}

if (! function_exists('wp_update_post')) {
    function wp_update_post(array $postarr): int
    {
        foreach ($GLOBALS['pushpull_test_generateblocks_posts'] as $index => $post) {
            if ($post->ID !== (int) ($postarr['ID'] ?? 0)) {
                continue;
            }

            $GLOBALS['pushpull_test_generateblocks_posts'][$index] = new WP_Post(
                $post->ID,
                (string) ($postarr['post_title'] ?? $post->post_title),
                (string) ($postarr['post_name'] ?? $post->post_name),
                (string) ($postarr['post_status'] ?? $post->post_status),
                (int) ($postarr['menu_order'] ?? $post->menu_order)
            );

            return $post->ID;
        }

        return 0;
    }
}

if (! function_exists('wp_delete_post')) {
    function wp_delete_post(int $postId, bool $forceDelete = false): bool
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = array_values(array_filter(
            $GLOBALS['pushpull_test_generateblocks_posts'] ?? [],
            static fn (WP_Post $post): bool => $post->ID !== $postId
        ));
        unset($GLOBALS['pushpull_test_generateblocks_meta'][$postId]);

        return true;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        global $pushpull_test_options;

        return $pushpull_test_options[$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool $autoload = false): bool
    {
        global $pushpull_test_options;

        $pushpull_test_options[$option] = $value;

        return true;
    }
}

if (! function_exists('add_option')) {
    function add_option(string $option, mixed $value, string $deprecated = '', string|bool $autoload = 'yes'): bool
    {
        global $pushpull_test_options;

        if (array_key_exists($option, $pushpull_test_options)) {
            return false;
        }

        $pushpull_test_options[$option] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        global $pushpull_test_options;

        unset($pushpull_test_options[$option]);

        return true;
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 1;
    }
}

if (! function_exists('maybe_unserialize')) {
    function maybe_unserialize(mixed $data): mixed
    {
        if (! is_string($data)) {
            return $data;
        }

        $trimmed = trim($data);

        if ($trimmed === '' || ! preg_match('/^(a|s|i|d|b|O|C):/', $trimmed)) {
            return $data;
        }

        $result = @unserialize($data, ['allowed_classes' => false]);

        return $result === false && $data !== 'b:0;' ? $data : $result;
    }
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public int $insert_id = 0;

        /** @var array<string, array<string, array<string, mixed>>> */
        private array $tables = [];
        /** @var array<string, int> */
        private array $autoIncrement = [];

        public function insert(string $table, array $data, array $format = []): bool
        {
            if ((! isset($data['id']) || $data['id'] === null) && $this->tableUsesAutoIncrement($table)) {
                $this->autoIncrement[$table] = ($this->autoIncrement[$table] ?? 0) + 1;
                $data['id'] = $this->autoIncrement[$table];
            }

            $primaryKey = $this->primaryKeyForTable($table, $data);
            $this->tables[$table][$primaryKey] = $data;
            $this->insert_id = isset($data['id']) ? (int) $data['id'] : 0;

            return true;
        }

        public function replace(string $table, array $data, array $format = []): bool
        {
            return $this->insert($table, $data, $format);
        }

        public function query(string $query): int|false
        {
            if (preg_match('/^\s*DELETE\s+FROM\s+([a-zA-Z0-9_]+)/i', $query, $matches) === 1) {
                $table = $matches[1];
                $count = isset($this->tables[$table]) ? count($this->tables[$table]) : 0;
                $this->tables[$table] = [];
                $this->autoIncrement[$table] = 0;

                return $count;
            }

            return false;
        }

        public function prepare(string $query, mixed ...$args): array
        {
            return ['query' => $query, 'args' => $args];
        }

        public function get_row(mixed $query, string $output = ARRAY_A): ?array
        {
            if (! is_array($query) || ! isset($query['query'], $query['args'][0])) {
                return null;
            }

            $table = $this->extractTableName((string) $query['query']);

            if ($table === null || ! isset($this->tables[$table])) {
                return null;
            }

            foreach ($this->tables[$table] as $row) {
                if ($this->rowMatchesQuery((string) $query['query'], $query['args'], $row)) {
                    return $row;
                }
            }

            return null;
        }

        public function get_results(mixed $query, string $output = ARRAY_A): array
        {
            if (is_string($query)) {
                $table = $this->extractTableName($query);

                if ($table === null || ! isset($this->tables[$table])) {
                    return [];
                }

                return array_values($this->tables[$table]);
            }

            if (! is_array($query) || ! isset($query['query'], $query['args'])) {
                return [];
            }

            $table = $this->extractTableName((string) $query['query']);

            if ($table === null || ! isset($this->tables[$table])) {
                return [];
            }

            return array_values(array_filter(
                $this->tables[$table],
                fn (array $row): bool => $this->rowMatchesQuery((string) $query['query'], $query['args'], $row)
            ));
        }

        private function extractTableName(string $query): ?string
        {
            if (preg_match('/FROM\s+([a-zA-Z0-9_]+)/', $query, $matches) === 1) {
                return $matches[1];
            }

            return null;
        }

        private function primaryKeyForTable(string $table, array $data): string
        {
            foreach (['hash', 'ref_name', 'id'] as $column) {
                if (isset($data[$column])) {
                    return (string) $data[$column];
                }
            }

            return md5(serialize($data));
        }

        /**
         * @param mixed[] $args
         * @param array<string, mixed> $row
         */
        private function rowMatchesQuery(string $query, array $args, array $row): bool
        {
            $matchCount = preg_match_all('/([a-z_]+)\s*=\s*%(?:s|d)/i', $query, $matches);

            if ($matchCount === false || $matchCount === 0) {
                foreach (['hash', 'ref_name', 'id'] as $column) {
                    if (isset($row[$column], $args[0]) && (string) $row[$column] === (string) $args[0]) {
                        return true;
                    }
                }

                return false;
            }

            foreach ($matches[1] as $index => $column) {
                if (! array_key_exists($column, $row)) {
                    return false;
                }

                if ((string) $row[$column] !== (string) ($args[$index] ?? '')) {
                    return false;
                }
            }

            return true;
        }

        private function tableUsesAutoIncrement(string $table): bool
        {
            return str_contains($table, 'working_state') || str_contains($table, 'content_map') || str_contains($table, 'operations');
        }
    }
}

if (! defined('PUSHPULL_PLUGIN_DIR')) {
    define('PUSHPULL_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (is_readable(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
} else {
    require_once dirname(__DIR__) . '/src/Plugin/Autoloader.php';
    \PushPull\Plugin\Autoloader::register();
}
