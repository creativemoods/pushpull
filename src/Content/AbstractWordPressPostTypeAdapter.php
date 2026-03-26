<?php

declare(strict_types=1);

namespace PushPull\Content;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Support\Json\CanonicalJson;
use PushPull\Support\Urls\EnvironmentUrlCanonicalizer;
use WP_Post;
use WP_Term;

abstract class AbstractWordPressPostTypeAdapter implements WordPressManagedContentAdapterInterface
{
    abstract protected function managedSetKey(): string;

    abstract protected function managedSetLabel(): string;

    abstract protected function contentType(): string;

    abstract protected function manifestType(): string;

    abstract protected function postType(): string;

    abstract protected function repositoryPathPrefix(): string;

    abstract protected function commitMessage(): string;

    /**
     * @param array<int, array<string, mixed>> $records
     */
    abstract protected function buildSnapshot(array $records, ManagedCollectionManifest $manifest): ManagedContentSnapshot;

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(array $record): array
    {
        return [
            'postContent' => (string) ($record['post_content'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMetadata(array $record): array
    {
        return [
            'restoration' => [
                'postType' => $this->postType(),
            ],
            'postMeta' => $this->normalizeMetaEntries($record['post_meta'] ?? []),
            'terms' => $this->normalizeTermAssignments($record['terms'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDerived(array $record): array
    {
        return [];
    }

    protected function includePostInExport(WP_Post $post): bool
    {
        return true;
    }

    /**
     * @return string[]
     */
    protected function postStatuses(): array
    {
        return ['publish', 'draft', 'private', 'pending', 'future'];
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
        return post_type_exists($this->postType());
    }

    public function exportAll(): array
    {
        return $this->exportSnapshot()->items;
    }

    public function exportSnapshot(): ManagedContentSnapshot
    {
        if (! $this->isAvailable()) {
            return $this->buildSnapshot([], $this->buildManifest([]));
        }

        $records = [];

        foreach ($this->allPosts() as $post) {
            $records[] = $this->buildRuntimeRecord($post);
        }

        return $this->snapshotFromRuntimeRecords($records);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function snapshotFromRuntimeRecords(array $records): ManagedContentSnapshot
    {
        $items = [];
        $logicalKeys = [];

        foreach ($records as $record) {
            $item = $this->buildItemFromRuntimeRecord($record);
            $items[] = $item;
            $logicalKeys[] = $item->logicalKey;
        }

        $this->assertUniqueLogicalKeys($logicalKeys);
        $manifest = $this->buildManifest($records);
        $this->validateManifest($manifest, $items);

        return $this->buildSnapshot($records, $manifest);
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        if (! $this->isAvailable()) {
            return null;
        }

        foreach ($this->allPosts() as $post) {
            $item = $this->buildItemFromRuntimeRecord($this->buildRuntimeRecord($post));

            if ($item->logicalKey === $logicalKey) {
                return $item;
            }
        }

        return null;
    }

    public function computeLogicalKey(array $wpRecord): string
    {
        $identifier = trim((string) ($wpRecord['post_name'] ?? ''));

        if ($identifier === '') {
            $identifier = trim((string) ($wpRecord['post_title'] ?? ''));
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

    public function buildItemFromRuntimeRecord(array $record): ManagedContentItem
    {
        $logicalKey = $this->computeLogicalKey($record);
        $slug = trim((string) ($record['post_name'] ?? '')) !== ''
            ? (string) $record['post_name']
            : $logicalKey;
        $displayName = trim((string) ($record['post_title'] ?? '')) !== ''
            ? (string) $record['post_title']
            : $logicalKey;
        $postStatus = trim((string) ($record['post_status'] ?? 'publish')) !== ''
            ? (string) $record['post_status']
            : 'publish';
        $payload = EnvironmentUrlCanonicalizer::normalizeValue($this->buildPayload($record));
        $metadata = EnvironmentUrlCanonicalizer::normalizeValue($this->buildMetadata($record));
        $derived = EnvironmentUrlCanonicalizer::normalizeValue($this->buildDerived($record));

        $item = new ManagedContentItem(
            $this->managedSetKey(),
            $this->contentType(),
            $logicalKey,
            $displayName,
            $logicalKey,
            $slug,
            is_array($payload) ? $payload : ['raw' => $payload],
            $postStatus,
            is_array($metadata) ? $metadata : [],
            is_array($derived) ? $derived : [],
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
        $orderedLogicalKeys = [];

        foreach ($records as $record) {
            $orderedLogicalKeys[] = $this->computeLogicalKey($record);
        }

        $this->assertUniqueLogicalKeys($orderedLogicalKeys);
        sort($orderedLogicalKeys);

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
                throw new ManagedContentExportException(
                    sprintf('Manifest references unknown logical key: %s', $logicalKey)
                );
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

        if (! isset($item->payload['postContent']) || ! is_string($item->payload['postContent'])) {
            throw new ManagedContentExportException(
                sprintf('Managed content item "%s" is missing its post content.', $item->logicalKey)
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
        foreach ($this->allPosts() as $post) {
            $candidateLogicalKey = $this->computeLogicalKey([
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
            ]);

            if ($candidateLogicalKey === $logicalKey) {
                return (int) $post->ID;
            }
        }

        return null;
    }

    public function postExists(int $postId): bool
    {
        foreach ($this->allPosts() as $post) {
            if ($post->ID === $postId) {
                return true;
            }
        }

        return false;
    }

    public function upsertItem(ManagedContentItem $item, int $menuOrder, ?int $existingId): int
    {
        $postData = [
            'post_type' => $this->postType(),
            'post_title' => $item->displayName,
            'post_name' => $item->slug,
            'post_status' => $item->postStatus,
            'post_content' => (string) ($item->payload['postContent'] ?? ''),
            'menu_order' => $menuOrder,
        ];

        $postData = wp_slash($postData);

        if ($existingId !== null) {
            $postData['ID'] = $existingId;

            return (int) wp_update_post($postData);
        }

        return (int) wp_insert_post($postData);
    }

    public function persistItemMeta(int $postId, ManagedContentItem $item): void
    {
        foreach ($this->currentPostMetaKeys($postId) as $metaKey) {
            delete_post_meta($postId, $metaKey);
        }

        foreach ($this->normalizeMetaEntries($item->metadata['postMeta'] ?? []) as $entry) {
            add_post_meta($postId, $entry['key'], wp_slash($entry['value']));
        }

        $this->persistTerms($postId, $item);
    }

    public function deleteMissingItems(array $desiredLogicalKeys): array
    {
        $deletedLogicalKeys = [];

        foreach ($this->allPosts() as $post) {
            $logicalKey = $this->computeLogicalKey([
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
            ]);

            if (isset($desiredLogicalKeys[$logicalKey])) {
                continue;
            }

            wp_delete_post($post->ID, true);
            $deletedLogicalKeys[] = $logicalKey;
        }

        sort($deletedLogicalKeys);

        return $deletedLogicalKeys;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRuntimeRecord(WP_Post $post): array
    {
        return [
            'wp_object_id' => (int) $post->ID,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_status' => (string) $post->post_status,
            'post_date' => (string) ($post->post_date ?? ''),
            'post_modified' => (string) ($post->post_modified ?? ''),
            'post_content' => (string) ($post->post_content ?? ''),
            'post_meta' => $this->postMetaEntries((int) $post->ID),
            'terms' => $this->assignedTerms((int) $post->ID),
        ];
    }

    /**
     * @return array<int, array{key: string, value: mixed}>
     */
    protected function postMetaEntries(int $postId): array
    {
        $allMeta = get_post_meta($postId);
        $entries = [];

        if (! is_array($allMeta)) {
            return [];
        }

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

        usort(
            $entries,
            static fn (array $left, array $right): int => [$left['key'], CanonicalJson::encode($left['value'])] <=> [$right['key'], CanonicalJson::encode($right['value'])]
        );

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function assignedTerms(int $postId): array
    {
        if (! function_exists('wp_get_object_terms') || ! function_exists('get_object_taxonomies')) {
            return [];
        }

        $taxonomies = get_object_taxonomies($this->postType(), 'names');
        $terms = wp_get_object_terms($postId, is_array($taxonomies) ? $taxonomies : [], ['fields' => 'all']);
        $normalized = [];

        foreach ($terms as $term) {
            if (! $term instanceof WP_Term || $term->slug === '' || $term->taxonomy === '') {
                continue;
            }

            $termMeta = get_term_meta($term->term_id);
            $metaEntries = [];

            if (is_array($termMeta)) {
                foreach ($termMeta as $metaKey => $values) {
                    if (! is_array($values)) {
                        continue;
                    }

                    foreach ($values as $value) {
                        $metaEntries[] = [
                            'key' => (string) $metaKey,
                            'value' => maybe_unserialize($value),
                        ];
                    }
                }
            }

            usort(
                $metaEntries,
                static fn (array $left, array $right): int => [$left['key'], CanonicalJson::encode($left['value'])] <=> [$right['key'], CanonicalJson::encode($right['value'])]
            );

            $normalized[] = [
                'taxonomy' => (string) $term->taxonomy,
                'slug' => sanitize_title((string) $term->slug),
                'name' => (string) $term->name,
                'description' => (string) ($term->description ?? ''),
                'parentSlug' => $this->parentSlug($term),
                'termMeta' => $metaEntries,
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => [$left['taxonomy'], $left['slug']] <=> [$right['taxonomy'], $right['slug']]
        );

        return $normalized;
    }

    protected function parentSlug(WP_Term $term): string
    {
        $parentId = (int) ($term->parent ?? 0);

        if ($parentId <= 0) {
            return '';
        }

        $parent = get_term($parentId, $term->taxonomy);

        if (! $parent instanceof WP_Term) {
            return '';
        }

        return sanitize_title((string) $parent->slug);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
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
     * @return array<int, array{taxonomy: string, slug: string, name: string, description: string, parentSlug: string, termMeta: array<int, array{key: string, value: mixed}>}>
     */
    protected function normalizeTermAssignments(mixed $terms): array
    {
        if (! is_array($terms)) {
            return [];
        }

        $normalized = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }

            $taxonomy = (string) ($term['taxonomy'] ?? '');
            $slug = sanitize_title((string) ($term['slug'] ?? $term['term_slug'] ?? ''));
            $name = trim((string) ($term['name'] ?? $term['term_name'] ?? ''));

            if ($taxonomy === '' || $slug === '' || $name === '') {
                continue;
            }

            $normalized[] = [
                'taxonomy' => $taxonomy,
                'slug' => $slug,
                'name' => $name,
                'description' => (string) ($term['description'] ?? $term['taxonomy_description'] ?? ''),
                'parentSlug' => sanitize_title((string) ($term['parentSlug'] ?? '')),
                'termMeta' => $this->normalizeMetaEntries($term['termMeta'] ?? $term['term_meta'] ?? []),
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => [$left['taxonomy'], $left['slug']] <=> [$right['taxonomy'], $right['slug']]
        );

        return $normalized;
    }

    /**
     * @return string[]
     */
    protected function currentPostMetaKeys(int $postId): array
    {
        $allMeta = get_post_meta($postId);

        if (! is_array($allMeta)) {
            return [];
        }

        $keys = array_map('strval', array_keys($allMeta));
        sort($keys);

        return $keys;
    }

    protected function persistTerms(int $postId, ManagedContentItem $item): void
    {
        $termsByTaxonomy = [];

        foreach ($this->normalizeTermAssignments($item->metadata['terms'] ?? []) as $term) {
            $termsByTaxonomy[$term['taxonomy']][] = $term;
        }

        foreach ($termsByTaxonomy as $taxonomy => $terms) {
            $termIds = [];

            foreach ($terms as $term) {
                $resolved = $this->ensureTerm($taxonomy, $term);

                if ($resolved > 0) {
                    $termIds[] = $resolved;
                }
            }

            wp_set_object_terms($postId, $termIds, $taxonomy, false);
        }
    }

    /**
     * @param array{taxonomy: string, slug: string, name: string, description: string, parentSlug: string, termMeta: array<int, array{key: string, value: mixed}>} $term
     */
    protected function ensureTerm(string $taxonomy, array $term): int
    {
        if (! function_exists('taxonomy_exists') || ! taxonomy_exists($taxonomy)) {
            return 0;
        }

        $parentId = 0;
        if ($term['parentSlug'] !== '') {
            $existingParent = term_exists($term['parentSlug'], $taxonomy);
            if (is_array($existingParent) && isset($existingParent['term_id'])) {
                $parentId = (int) $existingParent['term_id'];
            } elseif (is_int($existingParent)) {
                $parentId = $existingParent;
            }
        }

        $existing = term_exists($term['slug'], $taxonomy);
        $termId = 0;

        if (is_array($existing) && isset($existing['term_id'])) {
            $termId = (int) $existing['term_id'];
            wp_update_term($termId, $taxonomy, [
                'name' => $term['name'],
                'slug' => $term['slug'],
                'description' => $term['description'],
                'parent' => $parentId,
            ]);
        } elseif (is_int($existing) && $existing > 0) {
            $termId = $existing;
            wp_update_term($termId, $taxonomy, [
                'name' => $term['name'],
                'slug' => $term['slug'],
                'description' => $term['description'],
                'parent' => $parentId,
            ]);
        } else {
            $created = wp_insert_term($term['name'], $taxonomy, [
                'slug' => $term['slug'],
                'description' => $term['description'],
                'parent' => $parentId,
            ]);

            if (is_array($created) && isset($created['term_id'])) {
                $termId = (int) $created['term_id'];
            }
        }

        if ($termId <= 0) {
            return 0;
        }

        foreach (array_keys(get_term_meta($termId)) as $metaKey) {
            delete_term_meta($termId, (string) $metaKey);
        }

        foreach ($term['termMeta'] as $entry) {
            add_term_meta($termId, $entry['key'], $entry['value']);
        }

        return $termId;
    }

    /**
     * @return WP_Post[]
     */
    protected function allPosts(): array
    {
        return array_values(array_filter(
            get_posts([
                'post_type' => $this->postType(),
                'post_status' => $this->postStatuses(),
                'posts_per_page' => -1,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]),
            fn (mixed $post): bool => $post instanceof WP_Post && $this->includePostInExport($post)
        ));
    }

    /**
     * @param string[] $logicalKeys
     */
    protected function assertUniqueLogicalKeys(array $logicalKeys): void
    {
        if (count(array_unique($logicalKeys)) !== count($logicalKeys)) {
            throw new ManagedContentExportException(sprintf('%s logical keys must be unique.', $this->getManagedSetLabel()));
        }
    }
}
