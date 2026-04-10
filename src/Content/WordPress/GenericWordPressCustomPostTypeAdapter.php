<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

use PushPull\Content\AbstractWordPressPostTypeAdapter;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentSnapshot;

final class GenericWordPressCustomPostTypeAdapter extends AbstractWordPressPostTypeAdapter
{
    public function __construct(
        private readonly string $customPostType,
        private readonly string $label
    ) {
    }

    public static function managedSetKeyForPostType(string $postType): string
    {
        return 'custom_post_type_' . sanitize_key($postType);
    }

    protected function managedSetKey(): string
    {
        return self::managedSetKeyForPostType($this->customPostType);
    }

    protected function managedSetLabel(): string
    {
        return $this->label;
    }

    protected function contentType(): string
    {
        return 'custom_post_type_' . sanitize_key($this->customPostType);
    }

    protected function manifestType(): string
    {
        return 'custom_post_type_manifest_' . sanitize_key($this->customPostType);
    }

    protected function postType(): string
    {
        return $this->customPostType;
    }

    protected function repositoryPathPrefix(): string
    {
        return 'custom/post-types/' . sanitize_key($this->customPostType);
    }

    protected function commitMessage(): string
    {
        return sprintf('Commit live custom post type %s', $this->customPostType);
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
        $terms = $this->normalizeTermAssignments($record['terms'] ?? []);

        if ($postMeta !== []) {
            $metadata['postMeta'] = $postMeta;
        }

        if ($terms !== []) {
            $metadata['terms'] = $terms;
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

        return new ManagedContentSnapshot($items, $manifest);
    }
}
