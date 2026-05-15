<?php

declare(strict_types=1);

namespace PushPull\Content\GenerateBlocks;

use PushPull\Content\AbstractWordPressPostTypeAdapter;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentSnapshot;

final class WordPressBlockPatternsAdapter extends AbstractWordPressPostTypeAdapter
{
    private const MANAGED_SET_KEY = 'wordpress_block_patterns';
    private const CONTENT_TYPE = 'wordpress_block_pattern';
    private const MANIFEST_TYPE = 'wordpress_block_patterns_manifest';
    private const POST_TYPE = 'wp_block';
    private const PATH_PREFIX = 'wordpress/block-patterns';
    /** @var string[] */
    private const OWNED_POST_META_KEYS = [
        '_generateblocks_dynamic_css_version',
        'generateblocks_patterns_tree',
        'wp_pattern_sync_status',
    ];
    /** @var array<string, true> */
    private const OWNED_TAXONOMIES = [
        'gblocks_pattern_collections' => true,
        'language' => true,
    ];

    protected function managedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    protected function managedSetLabel(): string
    {
        return 'WordPress block patterns';
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
        return 'Commit live WordPress block patterns';
    }

    protected function shouldExportPostMetaKey(string $metaKey): bool
    {
        return in_array($metaKey, self::OWNED_POST_META_KEYS, true);
    }

    protected function shouldExportTermTaxonomy(string $taxonomy): bool
    {
        return isset(self::OWNED_TAXONOMIES[$taxonomy]);
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

        return new WordPressBlockPatternsSnapshot($items, $manifest);
    }
}
