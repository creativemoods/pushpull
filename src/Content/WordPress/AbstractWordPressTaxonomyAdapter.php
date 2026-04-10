<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedContentSnapshot;
use PushPull\Content\ManagedSetDependencyAwareInterface;
use PushPull\Content\WordPressManagedContentAdapterInterface;
use PushPull\Support\Json\CanonicalJson;
use PushPull\Support\Urls\EnvironmentUrlCanonicalizer;
use WP_Term;

abstract class AbstractWordPressTaxonomyAdapter implements WordPressManagedContentAdapterInterface, ManagedSetDependencyAwareInterface
{
    abstract protected function managedSetKey(): string;

    abstract protected function managedSetLabel(): string;

    abstract protected function contentType(): string;

    abstract protected function manifestType(): string;

    abstract protected function repositoryPathPrefix(): string;

    abstract protected function commitMessage(): string;

    abstract protected function taxonomy(): string;

    /**
     * @return string[]
     */
    public function getManagedSetDependencies(): array
    {
        return [];
    }

    public function getManagedSetKey(): string
    {
        return $this->managedSetKey();
    }

    public function getManagedSetLabel(): string
    {
        return $this->managedSetLabel();
    }

    public function getContentType(): string
    {
        return $this->contentType();
    }

    public function isAvailable(): bool
    {
        return function_exists('taxonomy_exists')
            && taxonomy_exists($this->taxonomy())
            && function_exists('get_terms')
            && function_exists('term_exists')
            && function_exists('wp_insert_term')
            && function_exists('wp_update_term')
            && function_exists('wp_delete_term')
            && function_exists('get_term_meta')
            && function_exists('add_term_meta')
            && function_exists('delete_term_meta');
    }

    /**
     * @return ManagedContentItem[]
     */
    public function exportAll(): array
    {
        return $this->exportSnapshot()->items;
    }

    public function exportSnapshot(): ManagedContentSnapshot
    {
        if (! $this->isAvailable()) {
            return new ManagedContentSnapshot([], $this->buildManifest([]), [], []);
        }

        $records = [];

        foreach ($this->allTerms() as $term) {
            $records[] = $this->buildRuntimeRecord($term);
        }

        return $this->snapshotFromRuntimeRecords($records);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function snapshotFromRuntimeRecords(array $records): ManagedContentSnapshot
    {
        $items = [];

        foreach ($records as $record) {
            $items[] = $this->buildItemFromRuntimeRecord($record);
        }

        usort($items, static fn (ManagedContentItem $left, ManagedContentItem $right): int => $left->logicalKey <=> $right->logicalKey);
        $manifest = $this->buildManifest($records);
        $this->validateManifest($manifest, $items);

        return new ManagedContentSnapshot($items, $manifest, [], $manifest->orderedLogicalKeys);
    }

    /**
     * @param array<string, string> $files
     */
    public function readSnapshotFromRepositoryFiles(array $files): ManagedContentSnapshot
    {
        $manifestContent = $files[$this->getManifestPath()] ?? null;

        if ($manifestContent === null) {
            throw new ManagedContentExportException('Managed set manifest is missing from the local branch.');
        }

        $manifest = $this->parseManifest($manifestContent);
        $items = [];

        foreach ($files as $path => $content) {
            if ($path === $this->getManifestPath() || ! $this->isManagedItemPath($path)) {
                continue;
            }

            $item = $this->deserialize($path, $content);
            $items[$item->logicalKey] = $item;
        }

        ksort($items);
        $this->validateManifest($manifest, array_values($items));

        return new ManagedContentSnapshot(array_values($items), $manifest, $files, $manifest->orderedLogicalKeys);
    }

    public function getManifestPath(): string
    {
        return $this->repositoryPathPrefix() . '/manifest.json';
    }

    public function ownsRepositoryPath(string $path): bool
    {
        return $path === $this->getManifestPath() || $this->isManagedItemPath($path);
    }

    public function serialize(ManagedContentItem $item): string
    {
        $this->validateItem($item);

        return CanonicalJson::encode($item->toArray());
    }

    public function serializeManifest(ManagedCollectionManifest $manifest): string
    {
        return CanonicalJson::encode($manifest->toArray());
    }

    public function hashItem(ManagedContentItem $item): string
    {
        return sha1($this->serialize($item));
    }

    public function hashManifest(ManagedCollectionManifest $manifest): string
    {
        return sha1($this->serializeManifest($manifest));
    }

    public function buildCommitMessage(): string
    {
        return $this->commitMessage();
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        foreach ($this->allTerms() as $term) {
            $item = $this->buildItemFromRuntimeRecord($this->buildRuntimeRecord($term));

            if ($item->logicalKey === $logicalKey) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $wpRecord
     */
    public function computeLogicalKey(array $wpRecord): string
    {
        $identifier = trim((string) ($wpRecord['slug'] ?? ''));

        if ($identifier === '') {
            $identifier = trim((string) ($wpRecord['name'] ?? ''));
        }

        $logicalKey = sanitize_title($identifier);

        if ($logicalKey === '') {
            throw new ManagedContentExportException(sprintf('%s logical key cannot be empty.', $this->getManagedSetLabel()));
        }

        return $logicalKey;
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return sprintf('%s/%s.json', $this->repositoryPathPrefix(), $item->logicalKey);
    }

    public function deserialize(string $path, string $content): ManagedContentItem
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new ManagedContentExportException(sprintf('Invalid managed content JSON at %s.', $path));
        }

        $payload = $decoded['payload'] ?? [];
        $metadata = $decoded['metadata'] ?? null;
        $derived = $decoded['derived'] ?? null;

        return new ManagedContentItem(
            $this->managedSetKey(),
            (string) ($decoded['type'] ?? $this->contentType()),
            (string) ($decoded['logicalKey'] ?? ''),
            (string) ($decoded['displayName'] ?? ''),
            (string) ($decoded['selector'] ?? ''),
            (string) ($decoded['slug'] ?? ''),
            is_array($payload)
                ? EnvironmentUrlCanonicalizer::denormalizeValue($payload)
                : ['raw' => EnvironmentUrlCanonicalizer::denormalizeValue($payload)],
            (string) ($decoded['postStatus'] ?? 'publish'),
            is_array($metadata) ? EnvironmentUrlCanonicalizer::denormalizeValue($metadata) : [],
            is_array($derived) ? EnvironmentUrlCanonicalizer::denormalizeValue($derived) : [],
            null,
            (int) ($decoded['schemaVersion'] ?? 1),
            (int) ($decoded['adapterVersion'] ?? 1)
        );
    }

    public function parseManifest(string $content): ManagedCollectionManifest
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! is_array($decoded['orderedLogicalKeys'] ?? null)) {
            throw new ManagedContentExportException('Managed set manifest is invalid.');
        }

        return new ManagedCollectionManifest(
            $this->managedSetKey(),
            (string) ($decoded['type'] ?? $this->manifestType()),
            $decoded['orderedLogicalKeys'],
            (int) ($decoded['schemaVersion'] ?? 1)
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    public function buildItemFromRuntimeRecord(array $record): ManagedContentItem
    {
        $logicalKey = $this->computeLogicalKey($record);
        $slug = trim((string) ($record['slug'] ?? '')) !== ''
            ? sanitize_title((string) $record['slug'])
            : $logicalKey;
        $displayName = trim((string) ($record['name'] ?? '')) !== ''
            ? (string) $record['name']
            : $logicalKey;
        $payload = EnvironmentUrlCanonicalizer::normalizeValue([
            'name' => $displayName,
            'description' => (string) ($record['description'] ?? ''),
            'parentSlug' => sanitize_title((string) ($record['parentSlug'] ?? '')),
        ]);
        $metadata = EnvironmentUrlCanonicalizer::normalizeValue([
            'restoration' => [
                'taxonomy' => $this->taxonomy(),
            ],
            'termMeta' => $this->normalizeMetaEntries($record['termMeta'] ?? []),
        ]);

        $item = new ManagedContentItem(
            $this->managedSetKey(),
            $this->contentType(),
            $logicalKey,
            $displayName,
            $logicalKey,
            $slug,
            is_array($payload) ? $payload : ['raw' => $payload],
            'publish',
            is_array($metadata) ? $metadata : [],
            [],
            isset($record['wp_object_id']) ? (int) $record['wp_object_id'] : null
        );

        $this->validateItem($item);

        return $item;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function buildManifest(array $records): ManagedCollectionManifest
    {
        $orderedLogicalKeys = $this->sortLogicalKeysForApply($records);
        $this->assertUniqueLogicalKeys($orderedLogicalKeys);

        return new ManagedCollectionManifest(
            $this->managedSetKey(),
            $this->manifestType(),
            $orderedLogicalKeys
        );
    }

    /**
     * @param ManagedContentItem[] $items
     */
    public function validateManifest(ManagedCollectionManifest $manifest, array $items): void
    {
        if ($manifest->manifestType !== $this->manifestType()) {
            throw new ManagedContentExportException(sprintf('Invalid %s manifest type.', $this->getManagedSetLabel()));
        }

        $knownKeys = [];

        foreach ($items as $item) {
            $knownKeys[$item->logicalKey] = true;
        }

        foreach ($manifest->orderedLogicalKeys as $logicalKey) {
            if (! isset($knownKeys[$logicalKey])) {
                throw new ManagedContentExportException(sprintf('Manifest references unknown logical key: %s', $logicalKey));
            }
        }
    }

    public function validateItem(ManagedContentItem $item): void
    {
        if ($item->logicalKey === '') {
            throw new ManagedContentExportException('Managed content item logical key cannot be empty.');
        }

        if ($item->slug === '') {
            throw new ManagedContentExportException(
                sprintf('Managed content item "%s" is missing its slug.', $item->logicalKey)
            );
        }

        if (! isset($item->payload['name']) || ! is_string($item->payload['name']) || trim($item->payload['name']) === '') {
            throw new ManagedContentExportException(
                sprintf('Managed content item "%s" is missing its term name.', $item->logicalKey)
            );
        }
    }

    public function isManagedItemPath(string $path): bool
    {
        return str_starts_with($path, $this->repositoryPathPrefix() . '/')
            && str_ends_with($path, '.json')
            && $path !== $this->getManifestPath();
    }

    public function findExistingWpObjectIdByLogicalKey(string $logicalKey): ?int
    {
        foreach ($this->allTerms() as $term) {
            if (
                $this->computeLogicalKey([
                    'slug' => (string) $term->slug,
                    'name' => (string) $term->name,
                ]) === $logicalKey
            ) {
                return (int) $term->term_id;
            }
        }

        return null;
    }

    public function postExists(int $postId): bool
    {
        return get_term($postId, $this->taxonomy()) instanceof WP_Term;
    }

    public function upsertItem(ManagedContentItem $item, int $menuOrder, ?int $existingId): int
    {
        $parentId = $this->resolveParentTermId((string) ($item->payload['parentSlug'] ?? ''));
        $args = [
            'slug' => $item->slug,
            'description' => (string) ($item->payload['description'] ?? ''),
            'parent' => $parentId,
        ];

        if ($existingId !== null && $existingId > 0) {
            wp_update_term($existingId, $this->taxonomy(), wp_slash([
                'name' => (string) ($item->payload['name'] ?? $item->displayName),
            ] + $args));

            return $existingId;
        }

        $created = wp_insert_term(
            (string) ($item->payload['name'] ?? $item->displayName),
            $this->taxonomy(),
            wp_slash($args)
        );

        if (! is_array($created) || ! isset($created['term_id'])) {
            throw new ManagedContentExportException(
                sprintf('Failed to create %s item "%s".', $this->getManagedSetLabel(), $item->logicalKey)
            );
        }

        return (int) $created['term_id'];
    }

    /**
     * @param array<string, string> $snapshotFiles
     */
    public function persistItemMeta(int $postId, ManagedContentItem $item, array $snapshotFiles = []): void
    {
        foreach ($this->currentTermMetaKeys($postId) as $metaKey) {
            delete_term_meta($postId, $metaKey);
        }

        foreach ($this->normalizeMetaEntries($item->metadata['termMeta'] ?? []) as $entry) {
            add_term_meta($postId, $entry['key'], $entry['value']);
        }
    }

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    public function deleteMissingItems(array $desiredLogicalKeys): array
    {
        $deleted = [];

        foreach ($this->allTerms() as $term) {
            $logicalKey = $this->computeLogicalKey([
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
            ]);

            if (isset($desiredLogicalKeys[$logicalKey])) {
                continue;
            }

            if (wp_delete_term((int) $term->term_id, $this->taxonomy())) {
                $deleted[] = $logicalKey;
            }
        }

        sort($deleted);

        return $deleted;
    }

    /**
     * @return WP_Term[]
     */
    protected function allTerms(): array
    {
        $terms = get_terms([
            'taxonomy' => $this->taxonomy(),
            'hide_empty' => false,
        ]);

        if (! is_array($terms)) {
            return [];
        }

        $terms = array_values(array_filter($terms, static fn (mixed $term): bool => $term instanceof WP_Term));
        usort($terms, static fn (WP_Term $left, WP_Term $right): int => [$left->slug, $left->term_id] <=> [$right->slug, $right->term_id]);

        return $terms;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRuntimeRecord(WP_Term $term): array
    {
        return [
            'wp_object_id' => (int) $term->term_id,
            'slug' => sanitize_title((string) $term->slug),
            'name' => (string) $term->name,
            'description' => (string) ($term->description ?? ''),
            'parentSlug' => $this->parentSlug($term),
            'termMeta' => $this->termMetaEntries((int) $term->term_id),
        ];
    }

    protected function parentSlug(WP_Term $term): string
    {
        $parentId = (int) ($term->parent ?? 0);

        if ($parentId <= 0) {
            return '';
        }

        $parent = get_term($parentId, $this->taxonomy());

        if (! $parent instanceof WP_Term) {
            return '';
        }

        return sanitize_title((string) $parent->slug);
    }

    /**
     * @return array<int, array{key: string, value: mixed}>
     */
    protected function termMetaEntries(int $termId): array
    {
        $allMeta = get_term_meta($termId);

        if (! is_array($allMeta)) {
            return [];
        }

        $entries = [];

        foreach ($allMeta as $metaKey => $values) {
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $entries[] = [
                    'key' => (string) $metaKey,
                    'value' => maybe_unserialize($value),
                ];
            }
        }

        return $this->normalizeMetaEntries($entries);
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $entries
     * @return array<int, array{key: string, value: mixed}>
     */
    protected function normalizeMetaEntries(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $normalized = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = (string) ($entry['key'] ?? $entry['meta_key'] ?? '');

            if ($key === '') {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'value' => $entry['value'] ?? $entry['meta_value'] ?? null,
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => [$left['key'], CanonicalJson::encode($left['value'])] <=> [$right['key'], CanonicalJson::encode($right['value'])]
        );

        return $normalized;
    }

    /**
     * @return string[]
     */
    protected function currentTermMetaKeys(int $termId): array
    {
        $allMeta = get_term_meta($termId);

        if (! is_array($allMeta)) {
            return [];
        }

        $keys = array_map('strval', array_keys($allMeta));
        sort($keys);

        return $keys;
    }

    protected function resolveParentTermId(string $parentSlug): int
    {
        $parentSlug = sanitize_title($parentSlug);

        if ($parentSlug === '') {
            return 0;
        }

        $existing = term_exists($parentSlug, $this->taxonomy());

        if (is_array($existing) && isset($existing['term_id'])) {
            return (int) $existing['term_id'];
        }

        if (is_int($existing)) {
            return $existing;
        }

        return 0;
    }

    /**
     * @param string[] $logicalKeys
     */
    private function assertUniqueLogicalKeys(array $logicalKeys): void
    {
        if (count($logicalKeys) === count(array_unique($logicalKeys))) {
            return;
        }

        throw new ManagedContentExportException(sprintf('%s export contains duplicate logical keys.', $this->getManagedSetLabel()));
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return string[]
     */
    private function sortLogicalKeysForApply(array $records): array
    {
        $recordsByKey = [];
        $orderedLogicalKeys = [];
        $visited = [];
        $visiting = [];

        foreach ($records as $record) {
            $recordsByKey[$this->computeLogicalKey($record)] = $record;
        }

        ksort($recordsByKey);

        $visit = function (string $logicalKey) use (&$visit, &$orderedLogicalKeys, &$recordsByKey, &$visited, &$visiting): void {
            if (isset($visited[$logicalKey]) || isset($visiting[$logicalKey])) {
                return;
            }

            $visiting[$logicalKey] = true;
            $record = $recordsByKey[$logicalKey] ?? null;
            $parentSlug = sanitize_title((string) ($record['parentSlug'] ?? ''));

            if ($parentSlug !== '' && isset($recordsByKey[$parentSlug])) {
                $visit($parentSlug);
            }

            $visited[$logicalKey] = true;
            unset($visiting[$logicalKey]);
            $orderedLogicalKeys[] = $logicalKey;
        };

        foreach (array_keys($recordsByKey) as $logicalKey) {
            $visit($logicalKey);
        }

        return $orderedLogicalKeys;
    }
}
