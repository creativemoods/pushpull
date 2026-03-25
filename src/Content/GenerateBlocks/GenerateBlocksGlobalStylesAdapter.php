<?php

declare(strict_types=1);

namespace PushPull\Content\GenerateBlocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentAdapterInterface;
use PushPull\Content\ManagedContentItem;
use PushPull\Support\Json\CanonicalJson;
use WP_Post;

final class GenerateBlocksGlobalStylesAdapter implements ManagedContentAdapterInterface
{
    private const MANAGED_SET_KEY = 'generateblocks_global_styles';
    private const CONTENT_TYPE = 'generateblocks_global_style';
    private const MANIFEST_TYPE = 'generateblocks_global_styles_manifest';
    private const POST_TYPE = 'gblocks_styles';

    private readonly GenerateBlocksLogicalKeyGenerator $logicalKeyGenerator;
    private readonly GenerateBlocksRepositoryLayout $repositoryLayout;
    private readonly GenerateBlocksCanonicalHasher $canonicalHasher;

    public function __construct()
    {
        $this->logicalKeyGenerator = new GenerateBlocksLogicalKeyGenerator();
        $this->repositoryLayout = new GenerateBlocksRepositoryLayout();
        $this->canonicalHasher = new GenerateBlocksCanonicalHasher();
    }

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'GenerateBlocks global styles';
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

    public function exportSnapshot(): GenerateBlocksGlobalStylesSnapshot
    {
        if (! $this->isAvailable()) {
            return new GenerateBlocksGlobalStylesSnapshot([], $this->buildManifest([]));
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
    public function snapshotFromRuntimeRecords(array $records): GenerateBlocksGlobalStylesSnapshot
    {
        $items = [];
        $logicalKeys = [];

        foreach ($records as $record) {
            $item = $this->buildItemFromRuntimeRecord($record);
            $logicalKeys[] = $item->logicalKey;
            $items[] = $item;
        }

        $this->logicalKeyGenerator->assertUnique($logicalKeys);
        $manifest = $this->buildManifest($records);
        $this->validateManifest($manifest, $items);

        return new GenerateBlocksGlobalStylesSnapshot($items, $manifest);
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
        $selector = trim((string) ($wpRecord['gb_style_selector'] ?? $wpRecord['selector'] ?? ''));

        if ($selector !== '') {
            return $this->logicalKeyGenerator->fromSelector($selector);
        }

        $fallbackSelector = trim((string) ($wpRecord['post_title'] ?? $wpRecord['post_name'] ?? ''));

        return $this->logicalKeyGenerator->fromSelector($fallbackSelector);
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return $this->repositoryLayout->itemPath($item->logicalKey);
    }

    public function getManifestPath(): string
    {
        return $this->repositoryLayout->manifestPath();
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

    public function buildItemFromRuntimeRecord(array $record): ManagedContentItem
    {
        $selector = trim((string) ($record['gb_style_selector'] ?? $record['post_title'] ?? ''));
        $logicalKey = $this->computeLogicalKey($record);
        $payload = $this->normalizeStyleData($record['gb_style_data'] ?? '');
        $slug = trim((string) ($record['post_name'] ?? '')) !== ''
            ? (string) $record['post_name']
            : $logicalKey;
        $displayName = trim((string) ($record['post_title'] ?? '')) !== ''
            ? (string) $record['post_title']
            : $selector;
        $postStatus = trim((string) ($record['post_status'] ?? 'publish')) !== ''
            ? (string) $record['post_status']
            : 'publish';

        $metadata = [
            'restoration' => [
                'postType' => self::POST_TYPE,
            ],
        ];
        $derived = [];

        if (trim((string) ($record['gb_style_css'] ?? '')) !== '') {
            $derived['generatedCss'] = (string) $record['gb_style_css'];
        }

        $item = new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            $logicalKey,
            $displayName,
            $selector,
            $slug,
            $payload,
            $postStatus,
            $metadata,
            $derived,
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
            throw new ManagedContentExportException('Invalid GenerateBlocks manifest type.');
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

        if ($item->selector === '') {
            throw new ManagedContentExportException(
                sprintf('Managed content item "%s" is missing its selector.', $item->logicalKey)
            );
        }

        $expectedLogicalKey = $this->logicalKeyGenerator->fromSelector($item->selector);

        if ($expectedLogicalKey !== $item->logicalKey) {
            throw new ManagedContentExportException(
                sprintf(
                    'Managed content item logical key "%s" does not match selector-derived key "%s".',
                    $item->logicalKey,
                    $expectedLogicalKey
                )
            );
        }
    }

    private function buildRuntimeRecord(WP_Post $post): array
    {
        return [
            'wp_object_id' => (int) $post->ID,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_status' => (string) $post->post_status,
            'menu_order' => (int) $post->menu_order,
            'gb_style_selector' => (string) get_post_meta($post->ID, 'gb_style_selector', true),
            'gb_style_data' => get_post_meta($post->ID, 'gb_style_data', true),
            'gb_style_css' => (string) get_post_meta($post->ID, 'gb_style_css', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeStyleData(mixed $styleData): array
    {
        if (is_array($styleData)) {
            return $styleData;
        }

        if (! is_string($styleData)) {
            throw new ManagedContentExportException('GenerateBlocks gb_style_data must be an array or serialized string.');
        }

        $decoded = function_exists('maybe_unserialize')
            ? maybe_unserialize($styleData)
            : @unserialize($styleData, ['allowed_classes' => false]);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded) && $decoded !== '' && $decoded !== $styleData) {
            $jsonDecoded = json_decode($decoded, true);

            if (is_array($jsonDecoded)) {
                return $jsonDecoded;
            }
        }

        if ($styleData === '') {
            return [];
        }

        $jsonDecoded = json_decode($styleData, true);

        if (is_array($jsonDecoded)) {
            return $jsonDecoded;
        }

        throw new ManagedContentExportException('GenerateBlocks gb_style_data could not be normalized into canonical JSON.');
    }
}
