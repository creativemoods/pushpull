<?php

declare(strict_types=1);

global $pushpull_test_options;

$pushpull_test_options ??= [];
$GLOBALS['pushpull_test_cron_events'] ??= [];
$GLOBALS['pushpull_test_generateblocks_posts'] ??= [];
$GLOBALS['pushpull_test_generateblocks_meta'] ??= [];
$GLOBALS['pushpull_test_comments'] ??= [];
$GLOBALS['pushpull_test_comment_meta'] ??= [];
$GLOBALS['pushpull_test_terms'] ??= [];
$GLOBALS['pushpull_test_object_terms'] ??= [];
$GLOBALS['pushpull_test_term_meta'] ??= [];
$GLOBALS['pushpull_test_next_term_id'] ??= 1;
$GLOBALS['pushpull_test_next_post_id'] ??= 1;
$GLOBALS['pushpull_test_wpml_translations'] ??= [];
$GLOBALS['pushpull_test_wpml_site_keys'] ??= [];
$GLOBALS['pushpull_test_wpml_save_site_key_calls'] ??= [];
$GLOBALS['pushpull_test_wpml_save_site_key_error'] ??= '';
$GLOBALS['pushpull_test_deleted_site_transients'] ??= [];
$GLOBALS['pushpull_test_actions'] ??= [];
$GLOBALS['pushpull_test_theme_mods'] ??= [];

if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('WP_CLI')) {
    define('WP_CLI', false);
}

if (! class_exists('WP_CLI_Command')) {
    class WP_CLI_Command
    {
    }
}

if (! class_exists('WP_CLI')) {
    class WP_CLI
    {
        /** @var string[] */
        public static array $lines = [];
        /** @var string[] */
        public static array $successes = [];
        /** @var string[] */
        public static array $warnings = [];
        /** @var string[] */
        public static array $errors = [];
        /** @var array<string, object> */
        public static array $commands = [];

        public static function line(string $message): void
        {
            self::$lines[] = $message;
        }

        public static function success(string $message): void
        {
            self::$successes[] = $message;
        }

        public static function warning(string $message): void
        {
            self::$warnings[] = $message;
        }

        public static function error(string $message): never
        {
            self::$errors[] = $message;
            throw new RuntimeException($message);
        }

        public static function add_command(string $name, object $command): void
        {
            self::$commands[$name] = $command;
        }
    }
}

if (! class_exists('WP_Installer')) {
    class WP_Installer
    {
        private static ?self $instance = null;

        public static function instance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public function save_site_key(array $args = []): array
        {
            $GLOBALS['pushpull_test_wpml_save_site_key_calls'][] = $args;

            $repositoryId = (string) ($args['repository_id'] ?? '');
            $siteKey = preg_replace('/[^A-Za-z0-9]/', '', (string) ($args['site_key'] ?? '')) ?? '';
            $nonce = (string) ($args['nonce'] ?? '');

            if ($repositoryId === '' || ! wp_verify_nonce($nonce, 'save_site_key_' . $repositoryId)) {
                return ['error' => 'Invalid site key request.'];
            }

            $configuredError = (string) ($GLOBALS['pushpull_test_wpml_save_site_key_error'] ?? '');

            if ($configuredError !== '') {
                return ['error' => $configuredError];
            }

            $GLOBALS['pushpull_test_wpml_site_keys'][$repositoryId] = $siteKey;
            do_action('check_posthog_should_record');

            return ['error' => ''];
        }

        public function get_repository_site_key(string $repositoryId): string|false
        {
            return $GLOBALS['pushpull_test_wpml_site_keys'][$repositoryId] ?? false;
        }
    }
}

if (! class_exists('PushPull_Test_SitePress')) {
    class PushPull_Test_SitePress
    {
        public function get_setting(string $key, mixed $default = false): mixed
        {
            $settings = get_option('icl_sitepress_settings', []);

            return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : $default;
        }

        public function set_setting(string $key, mixed $value, bool $save = false): void
        {
            $settings = get_option('icl_sitepress_settings', []);
            $settings = is_array($settings) ? $settings : [];
            $settings[$key] = $value;

            if ($save) {
                update_option('icl_sitepress_settings', $settings);
            } else {
                $GLOBALS['pushpull_test_options']['icl_sitepress_settings'] = $settings;
            }
        }

        public function save_settings(): void
        {
            $settings = get_option('icl_sitepress_settings', []);
            update_option('icl_sitepress_settings', is_array($settings) ? $settings : []);
        }

        public function get_default_language(): string
        {
            return (string) $this->get_setting('default_language', '');
        }

        public function set_default_language(string $code): void
        {
            $this->set_setting('default_language', $code, true);
            $this->set_setting('admin_default_language', $code, true);
        }

        /**
         * @return array<string, array{code: string, active: string}>
         */
        public function get_active_languages(bool $refresh = false): array
        {
            $languages = [];

            foreach ((array) $this->get_setting('active_languages', []) as $languageCode) {
                $languageCode = (string) $languageCode;

                if ($languageCode !== '') {
                    $languages[$languageCode] = [
                        'code' => $languageCode,
                        'active' => '1',
                    ];
                }
            }

            return $languages;
        }

        public function is_setup_complete(): bool
        {
            return (bool) $this->get_setting('setup_complete', false);
        }

        public function cpt_slug_translation_turned_on(string $postType): bool
        {
            $settings = get_option('icl_sitepress_settings', []);
            $settings = is_array($settings) ? $settings : [];

            return ! empty($settings['posts_slug_translation']['types'][$postType])
                && (bool) get_option('wpml_base_slug_translation', false);
        }

        public function verify_post_translations(string $postType): void
        {
            $GLOBALS['pushpull_test_verified_post_translations'][] = $postType;
        }
    }
}

if (! class_exists('WPML_Installation')) {
    class WPML_Installation
    {
        public function __construct(private readonly wpdb $wpdb, private readonly PushPull_Test_SitePress $sitepress)
        {
        }

        public function finish_step1(string $initialLanguageCode): void
        {
            $this->sitepress->set_setting('existing_content_language_verified', 1, true);
            $this->sitepress->set_setting('default_language', $initialLanguageCode, true);
            $this->sitepress->set_setting('admin_default_language', $initialLanguageCode, true);
            $this->sitepress->set_setting('setup_wizard_step', 2, true);
        }

        /**
         * @param string[] $activeLanguages
         */
        public function finish_step2(array $activeLanguages): bool
        {
            return $this->set_active_languages($activeLanguages);
        }

        /**
         * @param string[] $activeLanguages
         */
        public function set_active_languages(array $activeLanguages): bool
        {
            $this->sitepress->set_setting('active_languages', array_values($activeLanguages), true);
            $this->sitepress->set_setting('setup_wizard_step', 3, true);

            return true;
        }

        public function finish_step3(): void
        {
            $this->sitepress->set_setting('setup_wizard_step', 4, true);
        }

        public function finish_installation(): void
        {
            $this->sitepress->set_setting('setup_complete', 1, true);
            update_option('wpml_start_version', 'test-version');
            do_action('wpml_setup_completed');
        }
    }
}

if (! class_exists('PushPull_Test_WPML_Core_LanguageNegotiation')) {
    class PushPull_Test_WPML_Core_LanguageNegotiation
    {
        public static function saveMode(int|string $mode): void
        {
            $mappedMode = match ($mode) {
                'directory', 1 => 1,
                'domain', 2 => 2,
                'parameter', 3 => 3,
                default => 1,
            };

            if (! isset($GLOBALS['sitepress']) || ! $GLOBALS['sitepress'] instanceof PushPull_Test_SitePress) {
                $GLOBALS['sitepress'] = new PushPull_Test_SitePress();
            }

            $GLOBALS['sitepress']->set_setting('language_negotiation_type', $mappedMode, true);
        }
    }

    class_alias('PushPull_Test_WPML_Core_LanguageNegotiation', 'WPML\\Core\\LanguageNegotiation');
}

$GLOBALS['sitepress'] ??= new PushPull_Test_SitePress();

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

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value, bool $remove_breaks = false): string
    {
        $value = strip_tags($value);

        if ($remove_breaks) {
            $value = preg_replace('/[\r\n\t ]+/', ' ', $value) ?? $value;
        }

        return trim($value);
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return 'nonce:' . $action;
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
    {
        return $nonce === 'nonce:' . $action ? 1 : false;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                $value[$key] = wp_unslash($entry);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return stripslashes($value);
    }
}

if (! function_exists('wp_slash')) {
    function wp_slash(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                $value[$key] = wp_slash($entry);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return addslashes($value);
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (! function_exists('__')) {
    function __(string $text, ?string $domain = null): string
    {
        return $text;
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hookName, mixed ...$args): void
    {
        $GLOBALS['pushpull_test_actions'][] = [
            'hook' => $hookName,
            'args' => $args,
        ];
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, ?string $domain = null): string
    {
        return $text;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return $text;
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://source.example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('delete_site_transient')) {
    function delete_site_transient(string $transient): bool
    {
        $GLOBALS['pushpull_test_deleted_site_transients'][] = $transient;

        return true;
    }
}

if (! function_exists('add_query_arg')) {
    /**
     * @param array<string, scalar|null> $args
     */
    function add_query_arg(array $args, string $url): string
    {
        $query = http_build_query(array_filter(
            $args,
            static fn (mixed $value): bool => $value !== null
        ));

        if ($query === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return $GLOBALS['pushpull_test_current_user_can'] ?? true;
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return (object) [
            'display_name' => $GLOBALS['pushpull_test_current_user_display_name'] ?? 'Jane Doe',
            'user_email' => $GLOBALS['pushpull_test_current_user_email'] ?? 'jane@example.com',
        ];
    }
}

if (! function_exists('is_admin_bar_showing')) {
    function is_admin_bar_showing(): bool
    {
        return $GLOBALS['pushpull_test_admin_bar_showing'] ?? true;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}

if (! function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = ''): mixed
    {
        return $GLOBALS['pushpull_test_object_cache'][$group][$key] ?? false;
    }
}

if (! function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['pushpull_test_object_cache'][$group][$key] = $data;

        return true;
    }
}

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset($GLOBALS['pushpull_test_object_cache'][$group][$key]);

        return true;
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return '2026-03-24 09:00:00';
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string
    {
        $base = 'https://source.example.test';

        if ($path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (! function_exists('site_url')) {
    function site_url(string $path = '', ?string $scheme = null): string
    {
        $base = 'https://source.example.test';

        if ($path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (! function_exists('post_type_exists')) {
    function post_type_exists(string $postType): bool
    {
        return in_array($postType, get_post_types([], 'names'), true);
    }
}

if (! class_exists('WP_Post_Type')) {
    class WP_Post_Type
    {
        /**
         * @param string[] $taxonomies
         */
        public function __construct(
            public string $name = '',
            public string $label = '',
            public bool $hierarchical = false,
            public bool $show_ui = true,
            public bool $_builtin = false,
            public array $taxonomies = []
        ) {
        }
    }
}

if (! class_exists('WP_Taxonomy')) {
    class WP_Taxonomy
    {
        /**
         * @param string[] $object_type
         */
        public function __construct(
            public string $name = '',
            public string $label = '',
            public bool $hierarchical = false,
            public bool $show_ui = true,
            public bool $_builtin = false,
            public array $object_type = []
        ) {
        }
    }
}

if (! function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names', string $operator = 'and'): array
    {
        $postTypes = $GLOBALS['pushpull_test_post_types'] ?? [
            'attachment' => new WP_Post_Type('attachment', 'Media', false, true, true),
            'custom_css' => new WP_Post_Type('custom_css', 'Custom CSS', false, true, true),
            'gblocks_condition' => new WP_Post_Type('gblocks_condition', 'Conditions', false, true, false),
            'gblocks_styles' => new WP_Post_Type('gblocks_styles', 'Global Styles', false, true, false),
            'gp_elements' => new WP_Post_Type('gp_elements', 'Elements', false, true, false),
            'nav_menu_item' => new WP_Post_Type('nav_menu_item', 'Menu Item', false, false, true),
            'page' => new WP_Post_Type('page', 'Pages', true, true, true),
            'post' => new WP_Post_Type('post', 'Posts', false, true, true),
            'wp_block' => new WP_Post_Type('wp_block', 'Patterns', false, true, true),
        ];

        $postTypes = array_filter($postTypes, static function (mixed $postType) use ($args, $operator): bool {
            if (! $postType instanceof WP_Post_Type) {
                return false;
            }

            $matches = [];

            foreach ($args as $property => $expected) {
                $actual = $postType->{$property} ?? null;
                $matches[] = $actual === $expected;
            }

            if ($matches === []) {
                return true;
            }

            return $operator === 'or'
                ? in_array(true, $matches, true)
                : ! in_array(false, $matches, true);
        });

        if ($output === 'names') {
            $names = [];

            foreach ($postTypes as $slug => $postType) {
                if ($postType instanceof WP_Post_Type) {
                    $names[(string) $slug] = $postType->name;
                }
            }

            return $names;
        }

        return $postTypes;
    }
}

if (! function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool
    {
        return in_array($taxonomy, get_taxonomies([], 'names'), true);
    }
}

if (! function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names', string $operator = 'and'): array
    {
        $taxonomies = $GLOBALS['pushpull_test_taxonomies'] ?? [
            'category' => new WP_Taxonomy('category', 'Categories', true, true, true, ['post']),
            'gblocks_condition_cat' => new WP_Taxonomy('gblocks_condition_cat', 'Condition Categories', false, true, false, ['gblocks_condition']),
            'gblocks_pattern_collections' => new WP_Taxonomy('gblocks_pattern_collections', 'Pattern Collections', false, true, false, ['wp_block']),
            'language' => new WP_Taxonomy('language', 'Languages', false, true, false, ['wp_block']),
            'nav_menu' => new WP_Taxonomy('nav_menu', 'Menus', true, false, true, ['nav_menu_item']),
            'post_tag' => new WP_Taxonomy('post_tag', 'Tags', false, true, true, ['post']),
        ];

        $taxonomies = array_filter($taxonomies, static function (mixed $taxonomy) use ($args, $operator): bool {
            if (! $taxonomy instanceof WP_Taxonomy) {
                return false;
            }

            $matches = [];

            foreach ($args as $property => $expected) {
                $actual = $taxonomy->{$property} ?? null;
                $matches[] = $actual === $expected;
            }

            if ($matches === []) {
                return true;
            }

            return $operator === 'or'
                ? in_array(true, $matches, true)
                : ! in_array(false, $matches, true);
        });

        if ($output === 'names') {
            $names = [];

            foreach ($taxonomies as $slug => $taxonomy) {
                if ($taxonomy instanceof WP_Taxonomy) {
                    $names[(string) $slug] = $taxonomy->name;
                }
            }

            return $names;
        }

        return $taxonomies;
    }
}

if (! function_exists('get_object_taxonomies')) {
    function get_object_taxonomies(string $objectType, string $output = 'names'): array
    {
        $taxonomies = [];

        foreach (get_taxonomies([], 'objects') as $taxonomy) {
            if (! $taxonomy instanceof WP_Taxonomy) {
                continue;
            }

            if (in_array($objectType, $taxonomy->object_type, true)) {
                $taxonomies[$taxonomy->name] = $output === 'objects' ? $taxonomy : $taxonomy->name;
            }
        }

        return array_values($taxonomies);
    }
}

if (! function_exists('get_terms')) {
    function get_terms(array|string $args = [], array $deprecated = []): array
    {
        $taxonomy = '';

        if (is_string($args)) {
            $taxonomy = $args;
        } elseif (is_array($args)) {
            $taxonomy = (string) ($args['taxonomy'] ?? '');
        }

        if ($taxonomy === '') {
            return [];
        }

        $terms = array_values(array_filter(
            $GLOBALS['pushpull_test_terms'][$taxonomy] ?? [],
            static fn (mixed $term): bool => $term instanceof WP_Term
        ));

        usort($terms, static fn (WP_Term $left, WP_Term $right): int => [$left->slug, $left->term_id] <=> [$right->slug, $right->term_id]);

        return $terms;
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
            public int $menu_order = 0,
            public string $post_type = 'gblocks_styles',
            public string $post_content = '',
            public string $post_date = '2026-03-24 09:00:00',
            public string $post_modified = '2026-03-24 09:00:00',
            public string $post_excerpt = '',
            public string $post_mime_type = '',
            public int $post_parent = 0
        ) {
        }
    }
}

if (! class_exists('WP_Term')) {
    class WP_Term
    {
        public function __construct(
            public int $term_id = 0,
            public int $term_taxonomy_id = 0,
            public string $taxonomy = '',
            public string $slug = '',
            public string $name = '',
            public string $description = '',
            public int $parent = 0
        ) {
        }
    }
}

if (! class_exists('WP_Admin_Bar')) {
    class WP_Admin_Bar
    {
        /** @var array<string, array<string, mixed>> */
        public array $nodes = [];

        /**
         * @param array<string, mixed> $node
         */
        public function add_node(array $node): void
        {
            if (! isset($node['id']) || ! is_string($node['id'])) {
                return;
            }

            $this->nodes[$node['id']] = $node;
        }
    }
}

if (! class_exists('WP_Comment')) {
    class WP_Comment
    {
        public function __construct(
            public int $comment_ID = 0,
            public int $comment_post_ID = 0,
            public int $comment_parent = 0,
            public string $comment_author = '',
            public string $comment_author_email = '',
            public string $comment_author_url = '',
            public string $comment_date = '2026-03-24 09:00:00',
            public string $comment_date_gmt = '2026-03-24 09:00:00',
            public string $comment_content = '',
            public string $comment_approved = '1',
            public string $comment_type = ''
        ) {
        }
    }
}

if (! class_exists('PushPull_Test_RmlFolder')) {
    class PushPull_Test_RmlFolder
    {
        public function __construct(
            private readonly int $id,
            private readonly string $absolutePath
        ) {
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getAbsolutePath(): string
        {
            return $this->absolutePath;
        }
    }
}

if (! function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        $posts = $GLOBALS['pushpull_test_generateblocks_posts'] ?? [];

        if (isset($args['post_type']) && is_string($args['post_type']) && $args['post_type'] !== '') {
            $requestedPostType = $args['post_type'];
            $posts = array_values(array_filter(
                $posts,
                static fn (WP_Post $post): bool => $post->post_type === $requestedPostType
            ));
        }

        usort(
            $posts,
            static fn (WP_Post $left, WP_Post $right): int => $left->ID <=> $right->ID
        );

        $limit = null;

        if (isset($args['posts_per_page'])) {
            $limit = (int) $args['posts_per_page'];
        } elseif (isset($args['numberposts'])) {
            $limit = (int) $args['numberposts'];
        } else {
            $limit = 5;
        }

        if ($limit >= 0) {
            $posts = array_slice($posts, 0, $limit);
        }

        return $posts;
    }
}

if (! function_exists('get_comments')) {
    function get_comments(array $args = []): array
    {
        $comments = $GLOBALS['pushpull_test_comments'] ?? [];

        if (isset($args['post_id'])) {
            $postId = (int) $args['post_id'];
            $comments = array_values(array_filter(
                $comments,
                static fn (WP_Comment $comment): bool => $comment->comment_post_ID === $postId
            ));
        }

        usort(
            $comments,
            static fn (WP_Comment $left, WP_Comment $right): int => $left->comment_ID <=> $right->comment_ID
        );

        return $comments;
    }
}

if (! function_exists('get_comment_meta')) {
    function get_comment_meta(int $commentId, string $key = '', bool $single = false): mixed
    {
        if ($key === '') {
            $allMeta = $GLOBALS['pushpull_test_comment_meta'][$commentId] ?? [];
            $normalized = [];

            foreach ($allMeta as $metaKey => $value) {
                $normalized[(string) $metaKey] = is_array($value) && array_is_list($value) ? $value : [$value];
            }

            return $normalized;
        }

        $value = $GLOBALS['pushpull_test_comment_meta'][$commentId][$key] ?? ($single ? '' : []);

        if (! $single) {
            return [$value];
        }

        if (is_array($value) && array_is_list($value)) {
            return $value[0] ?? '';
        }

        return $value;
    }
}

if (! function_exists('add_comment_meta')) {
    function add_comment_meta(int $commentId, string $key, mixed $value, bool $unique = false): bool
    {
        $value = wp_unslash($value);
        $existing = $GLOBALS['pushpull_test_comment_meta'][$commentId][$key] ?? null;

        if ($existing === null) {
            $GLOBALS['pushpull_test_comment_meta'][$commentId][$key] = [$value];
            return true;
        }

        if (! is_array($existing) || ! array_is_list($existing)) {
            $existing = [$existing];
        }

        if ($unique && in_array($value, $existing, true)) {
            return false;
        }

        $existing[] = $value;
        $GLOBALS['pushpull_test_comment_meta'][$commentId][$key] = $existing;

        return true;
    }
}

if (! function_exists('delete_comment_meta')) {
    function delete_comment_meta(int $commentId, string $key): bool
    {
        unset($GLOBALS['pushpull_test_comment_meta'][$commentId][$key]);

        return true;
    }
}

if (! function_exists('wp_insert_comment')) {
    function wp_insert_comment(array $commentdata): int
    {
        $commentdata = wp_unslash($commentdata);
        $id = (int) ($GLOBALS['pushpull_test_next_comment_id'] ?? 1);
        $GLOBALS['pushpull_test_next_comment_id'] = $id + 1;
        $GLOBALS['pushpull_test_comments'][] = new WP_Comment(
            $id,
            (int) ($commentdata['comment_post_ID'] ?? 0),
            (int) ($commentdata['comment_parent'] ?? 0),
            (string) ($commentdata['comment_author'] ?? ''),
            (string) ($commentdata['comment_author_email'] ?? ''),
            (string) ($commentdata['comment_author_url'] ?? ''),
            (string) ($commentdata['comment_date'] ?? '2026-03-24 09:00:00'),
            (string) ($commentdata['comment_date_gmt'] ?? '2026-03-24 09:00:00'),
            (string) ($commentdata['comment_content'] ?? ''),
            (string) ($commentdata['comment_approved'] ?? '1'),
            (string) ($commentdata['comment_type'] ?? '')
        );

        return $id;
    }
}

if (! function_exists('wp_update_comment')) {
    function wp_update_comment(array $commentdata): int
    {
        $commentdata = wp_unslash($commentdata);

        foreach ($GLOBALS['pushpull_test_comments'] as $index => $comment) {
            if ($comment->comment_ID !== (int) ($commentdata['comment_ID'] ?? 0)) {
                continue;
            }

            $GLOBALS['pushpull_test_comments'][$index] = new WP_Comment(
                $comment->comment_ID,
                (int) ($commentdata['comment_post_ID'] ?? $comment->comment_post_ID),
                (int) ($commentdata['comment_parent'] ?? $comment->comment_parent),
                (string) ($commentdata['comment_author'] ?? $comment->comment_author),
                (string) ($commentdata['comment_author_email'] ?? $comment->comment_author_email),
                (string) ($commentdata['comment_author_url'] ?? $comment->comment_author_url),
                (string) ($commentdata['comment_date'] ?? $comment->comment_date),
                (string) ($commentdata['comment_date_gmt'] ?? $comment->comment_date_gmt),
                (string) ($commentdata['comment_content'] ?? $comment->comment_content),
                (string) ($commentdata['comment_approved'] ?? $comment->comment_approved),
                (string) ($commentdata['comment_type'] ?? $comment->comment_type)
            );

            return $comment->comment_ID;
        }

        return 0;
    }
}

if (! function_exists('wp_delete_comment')) {
    function wp_delete_comment(int $commentId, bool $forceDelete = false): bool
    {
        $GLOBALS['pushpull_test_comments'] = array_values(array_filter(
            $GLOBALS['pushpull_test_comments'] ?? [],
            static fn (WP_Comment $comment): bool => $comment->comment_ID !== $commentId
        ));
        unset($GLOBALS['pushpull_test_comment_meta'][$commentId]);

        return true;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        if ($key === '') {
            $allMeta = $GLOBALS['pushpull_test_generateblocks_meta'][$postId] ?? [];
            $normalized = [];

            foreach ($allMeta as $metaKey => $value) {
                $normalized[(string) $metaKey] = is_array($value) && array_is_list($value) ? $value : [$value];
            }

            return $normalized;
        }

        $value = $GLOBALS['pushpull_test_generateblocks_meta'][$postId][$key] ?? ($single ? '' : []);

        if (! $single) {
            return [$value];
        }

        if (is_array($value) && array_is_list($value)) {
            return $value[0] ?? '';
        }

        return $value;
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        $GLOBALS['pushpull_test_generateblocks_meta'][$postId][$key] = wp_unslash($value);

        return true;
    }
}

if (! function_exists('add_post_meta')) {
    function add_post_meta(int $postId, string $key, mixed $value, bool $unique = false): bool
    {
        $value = wp_unslash($value);
        $existing = $GLOBALS['pushpull_test_generateblocks_meta'][$postId][$key] ?? null;

        if ($existing === null) {
            $GLOBALS['pushpull_test_generateblocks_meta'][$postId][$key] = [$value];
            return true;
        }

        if (! is_array($existing) || ! array_is_list($existing)) {
            $existing = [$existing];
        }

        if ($unique && in_array($value, $existing, true)) {
            return false;
        }

        $existing[] = $value;
        $GLOBALS['pushpull_test_generateblocks_meta'][$postId][$key] = $existing;

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
        $postarr = wp_unslash($postarr);
        $id = (int) ($GLOBALS['pushpull_test_next_post_id'] ?? 1);
        $GLOBALS['pushpull_test_next_post_id'] = $id + 1;
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new WP_Post(
            $id,
            (string) ($postarr['post_title'] ?? ''),
            (string) ($postarr['post_name'] ?? ''),
            (string) ($postarr['post_status'] ?? 'publish'),
            (int) ($postarr['menu_order'] ?? 0),
            (string) ($postarr['post_type'] ?? 'gblocks_styles'),
            (string) ($postarr['post_content'] ?? ''),
            (string) ($postarr['post_date'] ?? '2026-03-24 09:00:00'),
            (string) ($postarr['post_modified'] ?? '2026-03-24 09:00:00'),
            (string) ($postarr['post_excerpt'] ?? ''),
            (string) ($postarr['post_mime_type'] ?? ''),
            (int) ($postarr['post_parent'] ?? 0)
        );

        return $id;
    }
}

if (! function_exists('wp_update_post')) {
    function wp_update_post(array $postarr): int
    {
        $postarr = wp_unslash($postarr);
        foreach ($GLOBALS['pushpull_test_generateblocks_posts'] as $index => $post) {
            if ($post->ID !== (int) ($postarr['ID'] ?? 0)) {
                continue;
            }

            $GLOBALS['pushpull_test_generateblocks_posts'][$index] = new WP_Post(
                $post->ID,
                (string) ($postarr['post_title'] ?? $post->post_title),
                (string) ($postarr['post_name'] ?? $post->post_name),
                (string) ($postarr['post_status'] ?? $post->post_status),
                (int) ($postarr['menu_order'] ?? $post->menu_order),
                (string) ($postarr['post_type'] ?? $post->post_type),
                (string) ($postarr['post_content'] ?? $post->post_content),
                (string) ($postarr['post_date'] ?? $post->post_date),
                (string) ($postarr['post_modified'] ?? $post->post_modified),
                (string) ($postarr['post_excerpt'] ?? $post->post_excerpt),
                (string) ($postarr['post_mime_type'] ?? $post->post_mime_type),
                (int) ($postarr['post_parent'] ?? $post->post_parent)
            );

            return $post->ID;
        }

        return 0;
    }
}

if (! function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array
    {
        $baseDir = '/tmp/pushpull-test-uploads';
        $baseUrl = 'https://source.example.test/app/uploads';

        return [
            'path' => $baseDir,
            'url' => $baseUrl,
            'subdir' => '',
            'basedir' => $baseDir,
            'baseurl' => $baseUrl,
            'error' => false,
        ];
    }
}

if (! function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        if (is_dir($target)) {
            return true;
        }

        return mkdir($target, 0777, true);
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
        unset($GLOBALS['pushpull_test_object_terms'][$postId]);

        return true;
    }
}

if (! function_exists('_wp_rml_root')) {
    function _wp_rml_root(): int
    {
        return -1;
    }
}

if (! defined('RML_TYPE_FOLDER')) {
    define('RML_TYPE_FOLDER', 0);
}

if (! function_exists('wp_attachment_folder')) {
    function wp_attachment_folder(int|array $attachmentId, mixed $default = null): mixed
    {
        if (is_array($attachmentId)) {
            $result = [];

            foreach ($attachmentId as $id) {
                $result[(int) $id] = $GLOBALS['pushpull_test_rml_attachment_folders'][(int) $id] ?? $default;
            }

            return $result;
        }

        return $GLOBALS['pushpull_test_rml_attachment_folders'][(int) $attachmentId] ?? $default;
    }
}

if (! function_exists('wp_rml_get_by_id')) {
    function wp_rml_get_by_id(int $id, ?array $allowed = null, bool $mustBeFolderObject = false, bool $nullForRoot = true): ?PushPull_Test_RmlFolder
    {
        if ($id === _wp_rml_root()) {
            return $nullForRoot ? null : new PushPull_Test_RmlFolder(_wp_rml_root(), '/');
        }

        $path = $GLOBALS['pushpull_test_rml_folders'][$id] ?? null;

        if (! is_string($path)) {
            return null;
        }

        return new PushPull_Test_RmlFolder($id, $path);
    }
}

if (! function_exists('wp_rml_get_by_absolute_path')) {
    function wp_rml_get_by_absolute_path(string $path, ?array $allowed = null): ?PushPull_Test_RmlFolder
    {
        $normalized = '/' . trim($path, '/');

        if ($normalized === '/') {
            return new PushPull_Test_RmlFolder(_wp_rml_root(), '/');
        }

        foreach (($GLOBALS['pushpull_test_rml_folders'] ?? []) as $id => $folderPath) {
            if ($folderPath === $normalized) {
                return new PushPull_Test_RmlFolder((int) $id, $folderPath);
            }
        }

        return null;
    }
}

if (! function_exists('wp_rml_create_or_return_existing_id')) {
    function wp_rml_create_or_return_existing_id(string $name, int $parent, int $type, array $restrictions = [], bool $supress_validation = false): int|array
    {
        $name = trim($name);

        if ($name === '') {
            return ['Folder name cannot be empty.'];
        }

        $parentPath = '/';

        if ($parent !== _wp_rml_root()) {
            $parentPath = $GLOBALS['pushpull_test_rml_folders'][$parent] ?? null;

            if (! is_string($parentPath)) {
                return ['Parent folder does not exist.'];
            }
        }

        $path = rtrim($parentPath, '/');
        $path = ($path !== '' ? $path : '') . '/' . $name;
        $path = '/' . trim($path, '/');

        foreach (($GLOBALS['pushpull_test_rml_folders'] ?? []) as $id => $existingPath) {
            if ($existingPath === $path) {
                return (int) $id;
            }
        }

        $id = (int) ($GLOBALS['pushpull_test_next_rml_folder_id'] ?? 2);
        $GLOBALS['pushpull_test_next_rml_folder_id'] = $id + 1;
        $GLOBALS['pushpull_test_rml_folders'][$id] = $path;

        return $id;
    }
}

if (! function_exists('wp_rml_move')) {
    function wp_rml_move(int $folderId, array $attachmentIds, bool $supress_validation = false, bool $isShortcut = false): bool|array
    {
        if ($folderId !== _wp_rml_root() && ! isset($GLOBALS['pushpull_test_rml_folders'][$folderId])) {
            return ['Target folder does not exist.'];
        }

        foreach ($attachmentIds as $attachmentId) {
            $GLOBALS['pushpull_test_rml_attachment_folders'][(int) $attachmentId] = $folderId;
        }

        return true;
    }
}

if (! function_exists('wp_generate_attachment_metadata')) {
    function wp_generate_attachment_metadata(int $attachmentId, string $file): array
    {
        $relativePath = (string) get_post_meta($attachmentId, '_wp_attached_file', true);

        return [
            'file' => $relativePath !== '' ? $relativePath : basename($file),
            'generated' => true,
            'sizes' => [
                'thumbnail' => [
                    'file' => 'thumb-' . basename($file),
                    'width' => 150,
                    'height' => 150,
                ],
            ],
        ];
    }
}

if (! function_exists('wp_update_attachment_metadata')) {
    function wp_update_attachment_metadata(int $attachmentId, array $data): bool
    {
        return update_post_meta($attachmentId, '_wp_attachment_metadata', $data);
    }
}

if (! function_exists('wp_get_object_terms')) {
    function wp_get_object_terms(int $objectId, string|array $taxonomy, array $args = []): array
    {
        $taxonomies = is_array($taxonomy) ? $taxonomy : ($taxonomy === '' ? [] : [$taxonomy]);
        if ($taxonomies === []) {
            $taxonomies = array_keys($GLOBALS['pushpull_test_object_terms'][$objectId] ?? []);
        }

        $terms = [];

        foreach ($taxonomies as $taxonomyName) {
            $termIds = $GLOBALS['pushpull_test_object_terms'][$objectId][$taxonomyName] ?? [];

            foreach ($termIds as $termId) {
                $term = $GLOBALS['pushpull_test_terms'][$taxonomyName][$termId] ?? null;

                if ($term instanceof WP_Term) {
                    $terms[] = $term;
                }
            }
        }

        usort(
            $terms,
            static fn (WP_Term $left, WP_Term $right): int => $left->slug <=> $right->slug
        );

        return $terms;
    }
}

if (! function_exists('term_exists')) {
    function term_exists(string $term, string $taxonomy): array|int|null
    {
        foreach ($GLOBALS['pushpull_test_terms'][$taxonomy] ?? [] as $termObject) {
            if (! $termObject instanceof WP_Term) {
                continue;
            }

            if ($termObject->slug === $term) {
                return [
                    'term_id' => $termObject->term_id,
                    'term_taxonomy_id' => $termObject->term_taxonomy_id,
                ];
            }
        }

        return null;
    }
}

if (! function_exists('wp_insert_term')) {
    function wp_insert_term(string $term, string $taxonomy, array $args = []): array
    {
        $slug = sanitize_title((string) ($args['slug'] ?? $term));
        $id = (int) ($GLOBALS['pushpull_test_next_term_id'] ?? 1);
        $GLOBALS['pushpull_test_next_term_id'] = $id + 1;
        $termObject = new WP_Term(
            $id,
            $id,
            $taxonomy,
            $slug,
            $term,
            (string) ($args['description'] ?? ''),
            (int) ($args['parent'] ?? 0)
        );
        $GLOBALS['pushpull_test_terms'][$taxonomy][$id] = $termObject;

        return [
            'term_id' => $id,
            'term_taxonomy_id' => $id,
        ];
    }
}

if (! function_exists('wp_update_term')) {
    function wp_update_term(int $termId, string $taxonomy, array $args = []): array
    {
        $existing = $GLOBALS['pushpull_test_terms'][$taxonomy][$termId] ?? null;

        if (! $existing instanceof WP_Term) {
            return ['term_id' => 0, 'term_taxonomy_id' => 0];
        }

        $GLOBALS['pushpull_test_terms'][$taxonomy][$termId] = new WP_Term(
            $existing->term_id,
            $existing->term_taxonomy_id,
            $taxonomy,
            sanitize_title((string) ($args['slug'] ?? $existing->slug)),
            (string) ($args['name'] ?? $existing->name),
            (string) ($args['description'] ?? $existing->description),
            (int) ($args['parent'] ?? $existing->parent)
        );

        return [
            'term_id' => $termId,
            'term_taxonomy_id' => $termId,
        ];
    }
}

if (! function_exists('wp_set_object_terms')) {
    function wp_set_object_terms(int $objectId, array $terms, string $taxonomy, bool $append = false): array
    {
        $termIds = [];

        foreach ($terms as $term) {
            $termIds[] = (int) $term;
        }

        sort($termIds);
        $GLOBALS['pushpull_test_object_terms'][$objectId][$taxonomy] = $termIds;

        return array_map(static fn (int $termId): string => (string) $termId, $termIds);
    }
}

if (! function_exists('get_term')) {
    function get_term(int $termId, string $taxonomy): ?WP_Term
    {
        $term = $GLOBALS['pushpull_test_terms'][$taxonomy][$termId] ?? null;

        return $term instanceof WP_Term ? $term : null;
    }
}

if (! function_exists('get_term_meta')) {
    function get_term_meta(int $termId, string $key = '', bool $single = false): mixed
    {
        if ($key === '') {
            $allMeta = $GLOBALS['pushpull_test_term_meta'][$termId] ?? [];
            $normalized = [];

            foreach ($allMeta as $metaKey => $value) {
                $normalized[(string) $metaKey] = is_array($value) && array_is_list($value) ? $value : [$value];
            }

            return $normalized;
        }

        $value = $GLOBALS['pushpull_test_term_meta'][$termId][$key] ?? ($single ? '' : []);

        return $single ? $value : [$value];
    }
}

if (! function_exists('add_term_meta')) {
    function add_term_meta(int $termId, string $key, mixed $value, bool $unique = false): bool
    {
        $existing = $GLOBALS['pushpull_test_term_meta'][$termId][$key] ?? null;

        if ($existing === null) {
            $GLOBALS['pushpull_test_term_meta'][$termId][$key] = [$value];
            return true;
        }

        if (! is_array($existing) || ! array_is_list($existing)) {
            $existing = [$existing];
        }

        if ($unique && in_array($value, $existing, true)) {
            return false;
        }

        $existing[] = $value;
        $GLOBALS['pushpull_test_term_meta'][$termId][$key] = $existing;

        return true;
    }
}

if (! function_exists('delete_term_meta')) {
    function delete_term_meta(int $termId, string $key): bool
    {
        unset($GLOBALS['pushpull_test_term_meta'][$termId][$key]);

        return true;
    }
}

if (! function_exists('wp_delete_term')) {
    function wp_delete_term(int $termId, string $taxonomy): bool
    {
        unset($GLOBALS['pushpull_test_terms'][$taxonomy][$termId], $GLOBALS['pushpull_test_term_meta'][$termId]);

        foreach (($GLOBALS['pushpull_test_object_terms'] ?? []) as $objectId => $taxonomies) {
            $termIds = $taxonomies[$taxonomy] ?? null;

            if (! is_array($termIds)) {
                continue;
            }

            $GLOBALS['pushpull_test_object_terms'][$objectId][$taxonomy] = array_values(array_filter(
                $termIds,
                static fn (mixed $candidate): bool => (int) $candidate !== $termId
            ));
        }

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

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        $event = $GLOBALS['pushpull_test_cron_events'][$hook] ?? null;

        if (! is_array($event) || ! isset($event['timestamp'])) {
            return false;
        }

        return (int) $event['timestamp'];
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        $GLOBALS['pushpull_test_cron_events'][$hook] = [
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'args' => $args,
        ];

        return true;
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        if (! isset($GLOBALS['pushpull_test_cron_events'][$hook])) {
            return 0;
        }

        unset($GLOBALS['pushpull_test_cron_events'][$hook]);

        return 1;
    }
}

if (! function_exists('get_theme_mod')) {
    function get_theme_mod(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['pushpull_test_theme_mods'][$name] ?? $default;
    }
}

if (! function_exists('set_theme_mod')) {
    function set_theme_mod(string $name, mixed $value): mixed
    {
        $GLOBALS['pushpull_test_theme_mods'][$name] = $value;

        return $value;
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 1;
    }
}

if (! function_exists('wp_get_nav_menus')) {
    function wp_get_nav_menus(array $args = []): array
    {
        return array_values($GLOBALS['pushpull_test_terms']['nav_menu'] ?? []);
    }
}

if (! function_exists('wp_create_nav_menu')) {
    function wp_create_nav_menu(string $menuName): int|array
    {
        $created = wp_insert_term($menuName, 'nav_menu', ['slug' => sanitize_title($menuName)]);

        return is_array($created) ? (int) ($created['term_id'] ?? 0) : $created;
    }
}

if (! function_exists('wp_delete_nav_menu')) {
    function wp_delete_nav_menu(int|string|\WP_Term $menu): bool
    {
        $menuObject = wp_get_nav_menu_object($menu);

        if (! $menuObject instanceof WP_Term) {
            return false;
        }

        unset($GLOBALS['pushpull_test_terms']['nav_menu'][$menuObject->term_id]);

        foreach ($GLOBALS['pushpull_test_generateblocks_posts'] ?? [] as $post) {
            if (($post->post_type ?? '') !== 'nav_menu_item') {
                continue;
            }

            $menuId = (int) ($GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_menu_item_menu_term_id'] ?? 0);

            if ($menuId === (int) $menuObject->term_id) {
                wp_delete_post((int) $post->ID, true);
            }
        }

        return true;
    }
}

if (! function_exists('wp_get_nav_menu_object')) {
    function wp_get_nav_menu_object(int|string|\WP_Term $menu): ?WP_Term
    {
        if ($menu instanceof WP_Term) {
            return $menu;
        }

        if (is_int($menu) || ctype_digit((string) $menu)) {
            $termId = (int) $menu;

            return $GLOBALS['pushpull_test_terms']['nav_menu'][$termId] ?? null;
        }

        $slug = sanitize_title((string) $menu);

        foreach ($GLOBALS['pushpull_test_terms']['nav_menu'] ?? [] as $term) {
            if ($term instanceof WP_Term && $term->slug === $slug) {
                return $term;
            }
        }

        return null;
    }
}

if (! function_exists('wp_get_nav_menu_items')) {
    function wp_get_nav_menu_items(int|string|\WP_Term $menu, array $args = []): array
    {
        $menuObject = wp_get_nav_menu_object($menu);

        if (! $menuObject instanceof WP_Term) {
            return [];
        }

        $items = [];

        foreach ($GLOBALS['pushpull_test_generateblocks_posts'] ?? [] as $post) {
            if (($post->post_type ?? '') !== 'nav_menu_item') {
                continue;
            }

            $meta = $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID] ?? [];
            $assignedTerms = $GLOBALS['pushpull_test_object_terms'][$post->ID]['nav_menu'] ?? [];
            $assignedMenuIds = is_array($assignedTerms)
                ? array_map('intval', $assignedTerms)
                : [];

            if (
                (int) ($meta['_menu_item_menu_term_id'] ?? 0) !== (int) $menuObject->term_id
                && ! in_array((int) $menuObject->term_id, $assignedMenuIds, true)
            ) {
                continue;
            }

            $item = new \stdClass();
            $item->ID = (int) $post->ID;
            $item->menu_order = (int) ($post->menu_order ?? 0);
            $item->menu_item_parent = (int) ($meta['_menu_item_menu_item_parent'] ?? 0);
            $item->type = (string) ($meta['_menu_item_type'] ?? 'custom');
            $item->object = (string) ($meta['_menu_item_object'] ?? '');
            $item->object_id = (int) ($meta['_menu_item_object_id'] ?? 0);
            $item->title = (string) ($post->post_title ?? '');
            $item->url = (string) ($meta['_menu_item_url'] ?? '');
            $item->target = (string) ($meta['_menu_item_target'] ?? '');
            $item->attr_title = (string) ($meta['_menu_item_attr_title'] ?? '');
            $item->description = (string) ($post->post_excerpt ?? '');
            $item->classes = array_values(array_filter(preg_split('/\s+/', (string) ($meta['_menu_item_classes'] ?? '')) ?: []));
            $item->xfn = (string) ($meta['_menu_item_xfn'] ?? '');
            $items[] = $item;
        }

        usort(
            $items,
            static fn (\stdClass $left, \stdClass $right): int => ((int) ($left->menu_order ?? 0)) <=> ((int) ($right->menu_order ?? 0))
        );

        return $items;
    }
}

if (! function_exists('wp_update_nav_menu_item')) {
    function wp_update_nav_menu_item(int $menuId, int $menuItemDbId, array $args = []): int
    {
        $postarr = [
            'post_type' => 'nav_menu_item',
            'post_title' => (string) ($args['menu-item-title'] ?? ''),
            'post_status' => (string) ($args['menu-item-status'] ?? 'publish'),
            'menu_order' => (int) ($args['menu-item-position'] ?? 0),
            'post_excerpt' => (string) ($args['menu-item-description'] ?? ''),
        ];

        if ($menuItemDbId > 0) {
            $postarr['ID'] = $menuItemDbId;
            $postId = (int) wp_update_post($postarr);
        } else {
            $postId = (int) wp_insert_post($postarr);
        }

        update_post_meta($postId, '_menu_item_menu_term_id', $menuId);
        update_post_meta($postId, '_menu_item_type', (string) ($args['menu-item-type'] ?? 'custom'));
        update_post_meta($postId, '_menu_item_object', (string) ($args['menu-item-object'] ?? 'custom'));
        update_post_meta($postId, '_menu_item_object_id', (int) ($args['menu-item-object-id'] ?? 0));
        update_post_meta($postId, '_menu_item_url', (string) ($args['menu-item-url'] ?? ''));
        update_post_meta($postId, '_menu_item_menu_item_parent', (int) ($args['menu-item-parent-id'] ?? 0));
        update_post_meta($postId, '_menu_item_target', (string) ($args['menu-item-target'] ?? ''));
        update_post_meta($postId, '_menu_item_attr_title', (string) ($args['menu-item-attr-title'] ?? ''));
        update_post_meta($postId, '_menu_item_classes', (string) ($args['menu-item-classes'] ?? ''));
        update_post_meta($postId, '_menu_item_xfn', (string) ($args['menu-item-xfn'] ?? ''));
        wp_set_object_terms($postId, [$menuId], 'nav_menu', false);

        return $postId;
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

        public function update(string $table, array $data, array $where, array $format = [], array $whereFormat = []): int|false
        {
            if (! isset($this->tables[$table])) {
                return 0;
            }

            $updated = 0;

            foreach ($this->tables[$table] as $primaryKey => $row) {
                $matches = true;

                foreach ($where as $column => $value) {
                    if ((string) ($row[$column] ?? '') !== (string) $value) {
                        $matches = false;
                        break;
                    }
                }

                if (! $matches) {
                    continue;
                }

                $this->tables[$table][$primaryKey] = array_merge($row, $data);
                $updated++;
            }

            return $updated;
        }

        public function delete(string $table, array $where, array $where_format = []): int|false
        {
            if (! isset($this->tables[$table])) {
                return 0;
            }

            $before = count($this->tables[$table]);
            $this->tables[$table] = array_filter(
                $this->tables[$table],
                function (array $row) use ($where): bool {
                    foreach ($where as $column => $value) {
                        if ((string) ($row[$column] ?? '') !== (string) $value) {
                            return true;
                        }
                    }

                    return false;
                }
            );

            return $before - count($this->tables[$table]);
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

        public function get_var(mixed $query, int $x = 0, int $y = 0): mixed
        {
            $row = $this->get_row($query, ARRAY_A);

            if (! is_array($row)) {
                return null;
            }

            $values = array_values($row);

            return $values[$x] ?? null;
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
            $normalizedQuery = preg_replace('/\s+/', ' ', $query) ?? $query;
            $matchCount = preg_match_all('/([a-z_]+)\s*=\s*%(?:s|d)/i', $normalizedQuery, $matches);

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
            return str_contains($table, 'working_state')
                || str_contains($table, 'content_map')
                || str_contains($table, 'operations')
                || str_contains($table, 'icl_strings')
                || str_contains($table, 'icl_string_translations');
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
