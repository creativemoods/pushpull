<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

use PushPull\Content\AbstractWordPressPostTypeAdapter;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentSnapshot;
use PushPull\Content\ManagedContentItem;
use RuntimeException;
use WP_Post;

final class GeneratePressElementsAdapter extends AbstractWordPressPostTypeAdapter
{
    private const MANAGED_SET_KEY = 'generatepress_elements';
    private const CONTENT_TYPE = 'generatepress_element';
    private const MANIFEST_TYPE = 'generatepress_elements_manifest';
    private const POST_TYPE = 'gp_elements';
    private const PATH_PREFIX = 'generatepress/elements';
    private const CONDITION_REFERENCE_TYPES = [
        'post:page' => [
            'postType' => 'page',
            'managedSetKey' => 'wordpress_pages',
            'contentType' => 'wordpress_page',
        ],
        'post:post' => [
            'postType' => 'post',
            'managedSetKey' => 'wordpress_posts',
            'contentType' => 'wordpress_post',
        ],
    ];

    /** @var array<string, true> */
    private const OWNED_META_KEYS = [
        '_generate_element_content' => true,
        '_generate_element_type' => true,
        '_generate_hook' => true,
        '_generate_element_display_conditions' => true,
        '_generate_element_exclude_conditions' => true,
        '_generateblocks_dynamic_css_version' => true,
        '_generate_block_element_editor_width' => true,
        '_generate_block_element_editor_width_unit' => true,
        '_generate_block_type' => true,
        '_generate_custom_hook' => true,
        '_generate_disable_archive_navigation' => true,
        '_generate_disable_featured_image' => true,
        '_generate_disable_post_navigation' => true,
        '_generate_disable_primary_post_meta' => true,
        '_generate_disable_secondary_post_meta' => true,
        '_generate_disable_title' => true,
        '_generate_hook_priority' => true,
        '_generate_post_loop_item_display' => true,
        '_generate_post_loop_item_display_post_meta' => true,
        '_generate_post_loop_item_display_tax' => true,
        '_generate_post_loop_item_display_term' => true,
        '_generate_post_loop_item_tagname' => true,
        '_generate_post_meta_location' => true,
        '_generate_use_archive_navigation_container' => true,
        '_generate_use_theme_post_container' => true,
        '_generateblocks_reusable_blocks' => true,
        '_generate_sidebar_layout' => true,
        '_generate_navigation_colors' => true,
        '_generate_navigation_text_color' => true,
        '_generate_navigation_text_color_current' => true,
        '_generate_navigation_text_color_hover' => true,
        '_generate_site_header_merge' => true,
        '_top_nav_excluded' => true,
    ];

    protected function managedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    protected function managedSetLabel(): string
    {
        return 'GeneratePress elements';
    }

    protected function contentType(): string
    {
        return self::CONTENT_TYPE;
    }

    protected function manifestType(): string
    {
        return self::MANIFEST_TYPE;
    }

    protected function postType(): string
    {
        return self::POST_TYPE;
    }

    protected function repositoryPathPrefix(): string
    {
        return self::PATH_PREFIX;
    }

    protected function commitMessage(): string
    {
        return 'Commit live GeneratePress elements';
    }

    /**
     * @return string[]
     */
    protected function managedSetDependencies(): array
    {
        return ['wordpress_pages', 'wordpress_posts'];
    }

    protected function shouldExportPostMetaKey(string $metaKey): bool
    {
        return isset(self::OWNED_META_KEYS[$metaKey]);
    }

    /**
     * @return array<int, array{key: string, value: mixed}>
     */
    protected function normalizePostMetaEntries(mixed $entries): array
    {
        $normalized = parent::normalizePostMetaEntries($entries);

        foreach ($normalized as &$entry) {
            if (! $this->isConditionMetaKey($entry['key'])) {
                continue;
            }

            $entry['value'] = $this->normalizeConditionReferencesForExport($entry['value']);
        }

        unset($entry);

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    protected function buildSnapshot(array $records, ManagedCollectionManifest $manifest): ManagedContentSnapshot
    {
        $items = [];

        foreach ($records as $record) {
            $items[] = $this->buildItemFromRuntimeRecord($record);
        }

        return new GeneratePressElementsSnapshot($items, $manifest);
    }

    public function persistItemMeta(int $postId, ManagedContentItem $item, array $snapshotFiles = []): void
    {
        $postMeta = parent::normalizePostMetaEntries($item->metadata['postMeta'] ?? []);

        foreach ($postMeta as &$entry) {
            if (! $this->isConditionMetaKey($entry['key'])) {
                continue;
            }

            $entry['value'] = $this->denormalizeConditionReferencesForApply($entry['value']);
        }

        unset($entry);

        foreach ($this->currentPostMetaKeys($postId) as $metaKey) {
            delete_post_meta($postId, $metaKey);
        }

        foreach ($postMeta as $entry) {
            add_post_meta($postId, $entry['key'], wp_slash($entry['value']));
        }

        $this->persistTerms($postId, $item);
    }

    private function isConditionMetaKey(string $metaKey): bool
    {
        return in_array($metaKey, ['_generate_element_display_conditions', '_generate_element_exclude_conditions'], true);
    }

    private function normalizeConditionReferencesForExport(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $index => $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $value[$index] = $this->normalizeConditionReferenceForExport($condition);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $condition
     * @return array<string, mixed>
     */
    private function normalizeConditionReferenceForExport(array $condition): array
    {
        $rule = (string) ($condition['rule'] ?? '');
        $referenceConfig = self::CONDITION_REFERENCE_TYPES[$rule] ?? null;
        $objectId = (string) ($condition['object'] ?? '');

        if ($referenceConfig === null || $objectId === '' || ! ctype_digit($objectId)) {
            return $condition;
        }

        $targetPost = $this->findPostById((int) $objectId, $referenceConfig['postType']);

        if (! $targetPost instanceof WP_Post) {
            return $condition;
        }

        $logicalKey = $this->computeLogicalKey([
            'post_title' => (string) $targetPost->post_title,
            'post_name' => (string) $targetPost->post_name,
        ]);

        unset($condition['object']);
        $condition['objectRef'] = [
            'managedSetKey' => $referenceConfig['managedSetKey'],
            'contentType' => $referenceConfig['contentType'],
            'logicalKey' => $logicalKey,
            'postType' => $referenceConfig['postType'],
        ];

        return $condition;
    }

    private function denormalizeConditionReferencesForApply(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $index => $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $value[$index] = $this->denormalizeConditionReferenceForApply($condition);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $condition
     * @return array<string, mixed>
     */
    private function denormalizeConditionReferenceForApply(array $condition): array
    {
        $objectRef = $condition['objectRef'] ?? null;

        if (! is_array($objectRef)) {
            return $condition;
        }

        $postType = (string) ($objectRef['postType'] ?? '');
        $logicalKey = (string) ($objectRef['logicalKey'] ?? '');

        if ($postType === '' || $logicalKey === '') {
            return $condition;
        }

        $targetPost = $this->findPostByLogicalKey($postType, $logicalKey);

        if (! $targetPost instanceof WP_Post) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            throw new RuntimeException(sprintf(
                'GeneratePress element condition references missing %s "%s".',
                $postType,
                $logicalKey
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        unset($condition['objectRef']);
        $condition['object'] = (string) $targetPost->ID;

        return $condition;
    }

    private function findPostById(int $postId, string $postType): ?WP_Post
    {
        foreach ($this->postsByType($postType) as $post) {
            if ($post->ID === $postId) {
                return $post;
            }
        }

        return null;
    }

    private function findPostByLogicalKey(string $postType, string $logicalKey): ?WP_Post
    {
        foreach ($this->postsByType($postType) as $post) {
            $candidateLogicalKey = $this->computeLogicalKey([
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
            ]);

            if ($candidateLogicalKey === $logicalKey) {
                return $post;
            }
        }

        return null;
    }

    /**
     * @return WP_Post[]
     */
    private function postsByType(string $postType): array
    {
        $posts = get_posts([
            'post_type' => $postType,
            'numberposts' => -1,
            'post_status' => 'any',
        ]);

        return array_values(array_filter($posts, static fn (mixed $post): bool => $post instanceof WP_Post));
    }
}
