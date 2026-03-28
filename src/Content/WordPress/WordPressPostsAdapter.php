<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

use PushPull\Content\AbstractWordPressPostTypeAdapter;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentSnapshot;

final class WordPressPostsAdapter extends AbstractWordPressPostTypeAdapter
{
    private const MANAGED_SET_KEY = 'wordpress_posts';
    private const CONTENT_TYPE = 'wordpress_post';
    private const MANIFEST_TYPE = 'wordpress_posts_manifest';
    private const POST_TYPE = 'post';
    private const PATH_PREFIX = 'wordpress/posts';

    protected function managedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    protected function managedSetLabel(): string
    {
        return 'WordPress posts';
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
        return 'Commit live WordPress posts';
    }

    protected function shouldExportPostMetaKey(string $metaKey): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    protected function buildMetadata(array $record): array
    {
        return [
            'restoration' => [
                'postType' => $this->postType(),
            ],
        ];
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

        return new WordPressPostsSnapshot($items, $manifest);
    }
}
