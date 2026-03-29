<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

use PushPull\Content\AbstractWordPressPostTypeAdapter;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentSnapshot;

final class WordPressPagesAdapter extends AbstractWordPressPostTypeAdapter
{
    /**
     * @var array<string, true>
     */
    private const OWNED_POST_META_KEYS = [
        '_generate_sidebar_layout' => true,
        '_generate_footer_widgets' => true,
        '_generate_content_area' => true,
        '_generate_content_width' => true,
        '_generate_disable_site_header' => true,
        '_generate_disable_top_bar' => true,
        '_generate_disable_primary_navigation' => true,
        '_generate_disable_secondary_navigation' => true,
        '_generate_disable_featured_image' => true,
        '_generate_disable_content_title' => true,
        '_generate_disable_title' => true,
        '_generate_disable_footer' => true,
        '_generate-sidebar-layout-meta' => true,
        '_generate-footer-widget-meta' => true,
        '_generate-full-width-content' => true,
        '_generate-disable-top-bar' => true,
        '_generate-disable-header' => true,
        '_generate-disable-nav' => true,
        '_generate-disable-secondary-nav' => true,
        '_generate-disable-headline' => true,
        '_generate-disable-footer' => true,
        '_generate-disable-post-image' => true,
    ];

    private const MANAGED_SET_KEY = 'wordpress_pages';
    private const CONTENT_TYPE = 'wordpress_page';
    private const MANIFEST_TYPE = 'wordpress_pages_manifest';
    private const POST_TYPE = 'page';
    private const PATH_PREFIX = 'wordpress/pages';

    protected function managedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    protected function managedSetLabel(): string
    {
        return 'WordPress pages';
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
        return 'Commit live WordPress pages';
    }

    protected function shouldExportPostMetaKey(string $metaKey): bool
    {
        return isset(self::OWNED_POST_META_KEYS[$metaKey]);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    protected function buildMetadata(array $record): array
    {
        $metadata = [
            'restoration' => [
                'postType' => $this->postType(),
            ],
        ];

        $postMeta = $this->normalizePostMetaEntries($record['post_meta'] ?? []);

        if ($postMeta !== []) {
            $metadata['postMeta'] = $postMeta;
        }

        return $metadata;
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

        return new WordPressPagesSnapshot($items, $manifest);
    }
}
