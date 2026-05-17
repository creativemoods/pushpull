<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\Exception\ManagedContentExportException;

final class WordPressCategoriesAdapter extends AbstractWordPressTaxonomyAdapter
{
    protected function managedSetKey(): string
    {
        return 'wordpress_categories';
    }

    protected function managedSetLabel(): string
    {
        return 'WordPress categories';
    }

    protected function contentType(): string
    {
        return 'wordpress_category';
    }

    protected function manifestType(): string
    {
        return 'wordpress_categories_manifest';
    }

    protected function repositoryPathPrefix(): string
    {
        return 'wordpress/categories';
    }

    protected function commitMessage(): string
    {
        return 'Commit live WordPress categories';
    }

    protected function taxonomy(): string
    {
        return 'category';
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function buildManifest(array $records): ManagedCollectionManifest
    {
        $orderedLogicalKeys = $this->sortLogicalKeysForApply($records);
        $this->assertUniqueLogicalKeys($orderedLogicalKeys);

        $extra = [];
        $defaultLogicalKey = $this->defaultLogicalKeyFromRecords($records);

        if ($defaultLogicalKey !== null) {
            $extra['defaultLogicalKey'] = $defaultLogicalKey;
        }

        return new ManagedCollectionManifest(
            $this->managedSetKey(),
            $this->manifestType(),
            $orderedLogicalKeys,
            1,
            $extra
        );
    }

    /**
     * @param ManagedContentItem[] $items
     */
    public function validateManifest(ManagedCollectionManifest $manifest, array $items): void
    {
        parent::validateManifest($manifest, $items);

        $defaultLogicalKey = $manifest->extra['defaultLogicalKey'] ?? null;

        if (! is_string($defaultLogicalKey) || $defaultLogicalKey === '') {
            return;
        }

        foreach ($items as $item) {
            if ($item->logicalKey === $defaultLogicalKey) {
                return;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
        throw new ManagedContentExportException(sprintf('Manifest references unknown default category logical key: %s', $defaultLogicalKey));
    }

    /**
     * @param array<string, ManagedContentItem> $items
     * @param array<string, string> $snapshotFiles
     */
    public function applyManifest(ManagedCollectionManifest $manifest, array $items = [], array $snapshotFiles = []): void
    {
        $defaultLogicalKey = $manifest->extra['defaultLogicalKey'] ?? null;

        if (! is_string($defaultLogicalKey) || $defaultLogicalKey === '') {
            return;
        }

        $defaultCategoryId = $this->findExistingWpObjectIdByLogicalKey($defaultLogicalKey);

        if ($defaultCategoryId === null || $defaultCategoryId <= 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            throw new ManagedContentExportException(sprintf('Unable to resolve default category logical key "%s" during apply.', $defaultLogicalKey));
        }

        update_option('default_category', $defaultCategoryId);
    }

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    public function deleteMissingItems(array $desiredLogicalKeys): array
    {
        $currentTerms = $this->allTerms();
        $defaultCategoryId = (int) get_option('default_category', 0);

        if ($defaultCategoryId > 0) {
            foreach ($currentTerms as $term) {
                if ((int) $term->term_id !== $defaultCategoryId) {
                    continue;
                }

                $logicalKey = $this->computeLogicalKey([
                    'slug' => (string) $term->slug,
                    'name' => (string) $term->name,
                ]);

                if (isset($desiredLogicalKeys[$logicalKey])) {
                    break;
                }

                $replacementId = $this->resolveDefaultCategoryReplacementId($currentTerms, $desiredLogicalKeys, $defaultCategoryId);

                if ($replacementId > 0) {
                    update_option('default_category', $replacementId);
                }

                break;
            }
        }

        return parent::deleteMissingItems($desiredLogicalKeys);
    }

    /**
     * @param \WP_Term[] $terms
     * @param array<string, true> $desiredLogicalKeys
     */
    private function resolveDefaultCategoryReplacementId(array $terms, array $desiredLogicalKeys, int $defaultCategoryId): int
    {
        foreach ($terms as $term) {
            if ((int) $term->term_id === $defaultCategoryId) {
                continue;
            }

            $logicalKey = $this->computeLogicalKey([
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
            ]);

            if (isset($desiredLogicalKeys[$logicalKey])) {
                return (int) $term->term_id;
            }
        }

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function defaultLogicalKeyFromRecords(array $records): ?string
    {
        $defaultCategoryId = (int) get_option('default_category', 0);

        if ($defaultCategoryId <= 0) {
            return null;
        }

        foreach ($records as $record) {
            if ((int) ($record['wp_object_id'] ?? 0) !== $defaultCategoryId) {
                continue;
            }

            return $this->computeLogicalKey($record);
        }

        return null;
    }
}
