<?php

declare(strict_types=1);

namespace PushPull\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use PushPull\Content\AbstractWordPressPostTypeAdapter;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Settings\SettingsRepository;
use WP_Post;

final class PostTypeIdentifierField
{
    private const COLUMN_KEY = 'pushpull_identifier';
    private const NONCE_ACTION = 'pushpull_post_type_identifier';
    private const NONCE_NAME = 'pushpull_post_type_identifier_nonce';
    private const FIELD_NAME = 'pushpull_post_type_identifier';

    public function __construct(
        private readonly ManagedSetRegistry $managedSetRegistry,
        private readonly SettingsRepository $settingsRepository
    ) {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('save_post', [$this, 'saveField']);
        add_action('quick_edit_custom_box', [$this, 'renderQuickEditField'], 10, 2);
        add_action('admin_footer-edit.php', [$this, 'renderQuickEditScript']);

        foreach ($this->editablePostTypes() as $postType) {
            add_filter(sprintf('manage_%s_posts_columns', $postType), [$this, 'registerListColumn']);
            add_action(sprintf('manage_%s_posts_custom_column', $postType), [$this, 'renderListColumn'], 10, 2);
        }
    }

    public function registerMetaBox(string $postType): void
    {
        if (! $this->isIdentifierEnabledForPostType($postType)) {
            return;
        }

        add_meta_box(
            'pushpull-post-type-identifier',
            __('PushPull identifier', 'pushpull'),
            [$this, 'renderMetaBox'],
            $postType,
            'side',
            'default'
        );
    }

    public function renderMetaBox(WP_Post $post): void
    {
        $value = (string) get_post_meta($post->ID, AbstractWordPressPostTypeAdapter::IDENTIFIER_META_KEY, true);
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        printf(
            '<p><label for="%1$s" class="screen-reader-text">%2$s</label><input type="text" class="widefat" id="%1$s" name="%1$s" value="%3$s" /></p>',
            esc_attr(self::FIELD_NAME),
            esc_html__('PushPull identifier', 'pushpull'),
            esc_attr($value)
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Optional stable identity for PushPull. Use this when translated items share the same visible title or slug.', 'pushpull')
        );
    }

    public function saveField(int $postId): void
    {
        if (! isset($_POST[self::NONCE_NAME]) || ! is_string($_POST[self::NONCE_NAME])) {
            return;
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }

        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        $post = get_post($postId);

        if (! $post instanceof WP_Post || ! $this->isIdentifierEnabledForPostType($post->post_type)) {
            return;
        }

        $rawValue = isset($_POST[self::FIELD_NAME]) && is_string($_POST[self::FIELD_NAME])
            ? sanitize_text_field(wp_unslash($_POST[self::FIELD_NAME]))
            : '';
        $value = sanitize_title($rawValue);

        if ($value === '') {
            delete_post_meta($postId, AbstractWordPressPostTypeAdapter::IDENTIFIER_META_KEY);

            return;
        }

        update_post_meta($postId, AbstractWordPressPostTypeAdapter::IDENTIFIER_META_KEY, $value);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function registerListColumn(array $columns): array
    {
        $columns[self::COLUMN_KEY] = __('PushPull identifier', 'pushpull');

        return $columns;
    }

    public function renderListColumn(string $column, int $postId): void
    {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        $value = (string) get_post_meta($postId, AbstractWordPressPostTypeAdapter::IDENTIFIER_META_KEY, true);

        printf(
            '<span class="pushpull-identifier-column-value" data-pushpull-identifier="%1$s">%2$s</span>',
            esc_attr($value),
            $value !== '' ? esc_html($value) : '&mdash;'
        );
    }

    public function renderQuickEditField(string $columnName, string $postType): void
    {
        if ($columnName !== self::COLUMN_KEY || ! $this->isIdentifierEnabledForPostType($postType)) {
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label>
                    <span class="title"><?php echo esc_html__('PushPull identifier', 'pushpull'); ?></span>
                    <span class="input-text-wrap">
                        <input type="text" name="<?php echo esc_attr(self::FIELD_NAME); ?>" value="" />
                    </span>
                </label>
                <p class="description"><?php echo esc_html__('Optional stable identity for PushPull.', 'pushpull'); ?></p>
            </div>
        </fieldset>
        <?php
    }

    public function renderQuickEditScript(): void
    {
        $postType = $this->currentListPostType();

        if (! is_string($postType) || $postType === '' || ! $this->isIdentifierEnabledForPostType($postType)) {
            return;
        }

        $columnSelector = esc_js(self::COLUMN_KEY);
        $fieldName = esc_js(self::FIELD_NAME);
        ?>
        <script>
        (function() {
            if (typeof inlineEditPost === 'undefined') {
                return;
            }

            var originalEdit = inlineEditPost.edit;

            inlineEditPost.edit = function(postId) {
                originalEdit.apply(this, arguments);

                var id = 0;

                if (typeof postId === 'object') {
                    id = parseInt(this.getId(postId), 10);
                } else {
                    id = parseInt(postId, 10);
                }

                if (!id) {
                    return;
                }

                var editRow = document.getElementById('edit-' + id);
                var postRow = document.getElementById('post-' + id);

                if (!editRow || !postRow) {
                    return;
                }

                var valueNode = postRow.querySelector('.column-<?php echo esc_js($columnSelector); ?> .pushpull-identifier-column-value');
                var input = editRow.querySelector('input[name="<?php echo esc_js($fieldName); ?>"]');

                if (!input) {
                    return;
                }

                input.value = valueNode ? (valueNode.getAttribute('data-pushpull-identifier') || '') : '';
            };
        }());
        </script>
        <?php
    }

    private function isIdentifierEnabledForPostType(string $postType): bool
    {
        $managedSetKey = $this->managedSetKeyForPostType($postType);

        if (! is_string($managedSetKey) || $managedSetKey === '') {
            return false;
        }

        return $this->settingsRepository->get()->usesManagedSetIdentifier($managedSetKey);
    }

    private function managedSetKeyForPostType(string $postType): ?string
    {
        foreach ($this->managedSetRegistry->all() as $managedSetKey => $adapter) {
            if (! $adapter instanceof AbstractWordPressPostTypeAdapter) {
                continue;
            }

            if ($adapter->getWordPressPostType() === $postType) {
                return $managedSetKey;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function editablePostTypes(): array
    {
        $postTypes = [];

        foreach ($this->managedSetRegistry->all() as $adapter) {
            if (! $adapter instanceof AbstractWordPressPostTypeAdapter) {
                continue;
            }

            $postType = $adapter->getWordPressPostType();

            if ($this->isIdentifierEnabledForPostType($postType)) {
                $postTypes[] = $postType;
            }
        }

        return array_values(array_unique($postTypes));
    }

    private function currentListPostType(): ?string
    {
        if (! function_exists('get_current_screen')) {
            return null;
        }

        $screen = get_current_screen();

        if ($screen === null || ! isset($screen->base, $screen->post_type)) {
            return null;
        }

        if ($screen->base !== 'edit') {
            return null;
        }

        return is_string($screen->post_type) ? $screen->post_type : null;
    }
}
