<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

use PushPull\Content\AbstractWordPressPostTypeAdapter;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentSnapshot;

final class GeneratePressElementsAdapter extends AbstractWordPressPostTypeAdapter
{
    private const MANAGED_SET_KEY = 'generatepress_elements';
    private const CONTENT_TYPE = 'generatepress_element';
    private const MANIFEST_TYPE = 'generatepress_elements_manifest';
    private const POST_TYPE = 'gp_elements';
    private const PATH_PREFIX = 'generatepress/elements';

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

    protected function shouldExportPostMetaKey(string $metaKey): bool
    {
        return isset(self::OWNED_META_KEYS[$metaKey]);
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
}
