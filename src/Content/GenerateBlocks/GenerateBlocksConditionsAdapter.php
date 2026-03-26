<?php

declare(strict_types=1);

namespace PushPull\Content\GenerateBlocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\WordPressManagedContentAdapterInterface;
use PushPull\Support\Json\CanonicalJson;
use WP_Post;

final class GenerateBlocksConditionsAdapter implements WordPressManagedContentAdapterInterface
{
    private const MANAGED_SET_KEY = 'generateblocks_conditions';
    private const CONTENT_TYPE = 'generateblocks_condition';
    private const MANIFEST_TYPE = 'generateblocks_conditions_manifest';
    private const POST_TYPE = 'gblocks_condition';
    private const META_KEY = '_gb_conditions';
    private const CATEGORY_TAXONOMY = 'gblocks_condition_cat';
    private const PATH_PREFIX = 'generateblocks/conditions';

    private readonly GenerateBlocksConditionKeyGenerator $logicalKeyGenerator;
    private readonly GenerateBlocksCanonicalHasher $canonicalHasher;

    public function __construct()
    {
        $this->logicalKeyGenerator = new GenerateBlocksConditionKeyGenerator();
        $this->canonicalHasher = new GenerateBlocksCanonicalHasher();
    }

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'GenerateBlocks conditions';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function isAvailable(): bool
    {
        return post_type_exists(self::POST_TYPE);
    }

    public function exportAll(): array
    {
        return $this->exportSnapshot()->items;
    }

    public function exportSnapshot(): GenerateBlocksConditionsSnapshot
    {
        if (! $this->isAvailable()) {
            return new GenerateBlocksConditionsSnapshot([], $this->buildManifest([]));
        }

        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $records = [];

        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $records[] = $this->buildRuntimeRecord($post);
        }

        return $this->snapshotFromRuntimeRecords($records);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function snapshotFromRuntimeRecords(array $records): GenerateBlocksConditionsSnapshot
    {
        $items = [];
        $logicalKeys = [];

        foreach ($records as $record) {
            $item = $this->buildItemFromRuntimeRecord($record);
            $items[] = $item;
            $logicalKeys[] = $item->logicalKey;
        }

        $this->logicalKeyGenerator->assertUnique($logicalKeys);
        $manifest = $this->buildManifest($records);
        $this->validateManifest($manifest, $items);

        return new GenerateBlocksConditionsSnapshot($items, $manifest);
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

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

        return $this->logicalKeyGenerator->fromIdentifier($identifier);
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return sprintf('%s/%s.json', self::PATH_PREFIX, $item->logicalKey);
    }

    public function getManifestPath(): string
    {
        return self::PATH_PREFIX . '/manifest.json';
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
        return $this->canonicalHasher->hash($this->serialize($item));
    }

    public function hashManifest(ManagedCollectionManifest $manifest): string
    {
        return $this->canonicalHasher->hash($this->serializeManifest($manifest));
    }

    public function buildCommitMessage(): string
    {
        return 'Commit live GenerateBlocks conditions';
    }

    public function deserialize(string $path, string $content): ManagedContentItem
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new ManagedContentExportException(sprintf('Invalid managed content JSON at %s.', $path));
        }

        $payload = $decoded['payload'] ?? [];

        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            (string) ($decoded['type'] ?? self::CONTENT_TYPE),
            (string) ($decoded['logicalKey'] ?? ''),
            (string) ($decoded['displayName'] ?? ''),
            (string) ($decoded['selector'] ?? ''),
            (string) ($decoded['slug'] ?? ''),
            is_array($payload) ? $payload : ['raw' => $payload],
            (string) ($decoded['postStatus'] ?? 'publish'),
            is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
            is_array($decoded['derived'] ?? null) ? $decoded['derived'] : [],
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
            self::MANAGED_SET_KEY,
            (string) ($decoded['type'] ?? self::MANIFEST_TYPE),
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

        $item = new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            $logicalKey,
            $displayName,
            $logicalKey,
            $slug,
            $this->normalizeConditions($record[self::META_KEY] ?? []),
            $postStatus,
            [
                'restoration' => [
                    'postType' => self::POST_TYPE,
                    'metaKey' => self::META_KEY,
                    'categoryTaxonomy' => self::CATEGORY_TAXONOMY,
                ],
                'categories' => $this->normalizeCategories($record['categories'] ?? []),
            ],
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
        $entries = [];

        foreach ($records as $record) {
            $entries[] = [
                'logicalKey' => $this->computeLogicalKey($record),
                'menuOrder' => (int) ($record['menu_order'] ?? 0),
            ];
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => [$left['menuOrder'], $left['logicalKey']] <=> [$right['menuOrder'], $right['logicalKey']]
        );

        $orderedLogicalKeys = array_map(static fn (array $entry): string => (string) $entry['logicalKey'], $entries);
        $this->logicalKeyGenerator->assertUnique($orderedLogicalKeys);

        return new ManagedCollectionManifest(
            self::MANAGED_SET_KEY,
            self::MANIFEST_TYPE,
            $orderedLogicalKeys
        );
    }

    /**
     * @param ManagedContentItem[] $items
     */
    public function validateManifest(ManagedCollectionManifest $manifest, array $items): void
    {
        if ($manifest->manifestType !== self::MANIFEST_TYPE) {
            throw new ManagedContentExportException('Invalid GenerateBlocks conditions manifest type.');
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

        $expectedLogicalKey = $this->logicalKeyGenerator->fromIdentifier($item->slug);

        if ($expectedLogicalKey !== $item->logicalKey) {
            throw new ManagedContentExportException(
                sprintf(
                    'Managed content item logical key "%s" does not match slug-derived key "%s".',
                    $item->logicalKey,
                    $expectedLogicalKey
                )
            );
        }
    }

    public function isManagedItemPath(string $path): bool
    {
        return str_starts_with($path, self::PATH_PREFIX . '/')
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
            'post_type' => self::POST_TYPE,
            'post_title' => $item->displayName,
            'post_name' => $item->slug,
            'post_status' => $item->postStatus,
            'menu_order' => $menuOrder,
        ];

        if ($existingId !== null) {
            $postData['ID'] = $existingId;

            return (int) wp_update_post($postData);
        }

        return (int) wp_insert_post($postData);
    }

    public function persistItemMeta(int $postId, ManagedContentItem $item): void
    {
        update_post_meta($postId, self::META_KEY, $item->payload);
        $this->persistCategories($postId, $item);
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

    private function buildRuntimeRecord(WP_Post $post): array
    {
        return [
            'wp_object_id' => (int) $post->ID,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_status' => (string) $post->post_status,
            'menu_order' => (int) $post->menu_order,
            self::META_KEY => get_post_meta($post->ID, self::META_KEY, true),
            'categories' => $this->assignedCategories((int) $post->ID),
        ];
    }

    /**
     * @return array<int, array{slug: string, name: string}>
     */
    private function assignedCategories(int $postId): array
    {
        if (! function_exists('taxonomy_exists') || ! taxonomy_exists(self::CATEGORY_TAXONOMY)) {
            return [];
        }

        $terms = wp_get_object_terms($postId, self::CATEGORY_TAXONOMY, ['fields' => 'all']);
        $categories = [];

        foreach ($terms as $term) {
            if (! is_object($term) || ! isset($term->slug, $term->name)) {
                continue;
            }

            $slug = sanitize_title((string) $term->slug);
            $name = trim((string) $term->name);

            if ($slug === '' || $name === '') {
                continue;
            }

            $categories[] = [
                'slug' => $slug,
                'name' => $name,
            ];
        }

        usort(
            $categories,
            static fn (array $left, array $right): int => $left['slug'] <=> $right['slug']
        );

        return $categories;
    }

    /**
     * @return array<int, array{slug: string, name: string}>
     */
    private function normalizeCategories(mixed $categories): array
    {
        if (! is_array($categories)) {
            return [];
        }

        $normalized = [];

        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }

            $slug = sanitize_title((string) ($category['slug'] ?? ''));
            $name = trim((string) ($category['name'] ?? ''));

            if ($slug === '' || $name === '') {
                continue;
            }

            $normalized[$slug] = [
                'slug' => $slug,
                'name' => $name,
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    private function persistCategories(int $postId, ManagedContentItem $item): void
    {
        if (! function_exists('taxonomy_exists') || ! taxonomy_exists(self::CATEGORY_TAXONOMY)) {
            return;
        }

        $categories = $this->normalizeCategories($item->metadata['categories'] ?? []);
        $termIds = [];

        foreach ($categories as $category) {
            $existing = term_exists($category['slug'], self::CATEGORY_TAXONOMY);

            if (is_array($existing) && isset($existing['term_id'])) {
                $termIds[] = (int) $existing['term_id'];
                continue;
            }

            if (is_int($existing) && $existing > 0) {
                $termIds[] = $existing;
                continue;
            }

            $created = wp_insert_term($category['name'], self::CATEGORY_TAXONOMY, ['slug' => $category['slug']]);

            if (is_array($created) && isset($created['term_id'])) {
                $termIds[] = (int) $created['term_id'];
            }
        }

        wp_set_object_terms($postId, $termIds, self::CATEGORY_TAXONOMY, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeConditions(mixed $conditions): array
    {
        if (is_array($conditions)) {
            return $conditions;
        }

        if (! is_string($conditions)) {
            throw new ManagedContentExportException('GenerateBlocks _gb_conditions must be an array or serialized string.');
        }

        $decoded = function_exists('maybe_unserialize')
            ? maybe_unserialize($conditions)
            : @unserialize($conditions, ['allowed_classes' => false]);

        if (is_array($decoded)) {
            return $decoded;
        }

        if ($conditions === '') {
            return [];
        }

        $jsonDecoded = json_decode($conditions, true);

        if (is_array($jsonDecoded)) {
            return $jsonDecoded;
        }

        throw new ManagedContentExportException('GenerateBlocks _gb_conditions could not be normalized into canonical JSON.');
    }

    /**
     * @return WP_Post[]
     */
    private function allPosts(): array
    {
        return array_values(array_filter(
            get_posts([
                'post_type' => self::POST_TYPE,
                'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
                'posts_per_page' => -1,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]),
            static fn (mixed $post): bool => $post instanceof WP_Post
        ));
    }
}
