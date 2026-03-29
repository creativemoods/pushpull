<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\ConfigManagedContentAdapterInterface;
use PushPull\Content\ConfigManagedSetInterface;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedContentSnapshot;
use PushPull\Content\ManagedSetDependencyAwareInterface;
use PushPull\Support\Json\CanonicalJson;
use RuntimeException;
use WP_Post;

final class WordPressCoreConfigurationAdapter implements ConfigManagedContentAdapterInterface, ManagedSetDependencyAwareInterface, ConfigManagedSetInterface
{
    private const MANAGED_SET_KEY = 'wordpress_core_configuration';
    private const CONTENT_TYPE_READING = 'wordpress_reading_settings';
    private const CONTENT_TYPE_PERMALINK = 'wordpress_permalink_settings';
    private const MANIFEST_TYPE = 'wordpress_core_configuration_manifest';
    private const PATH_PREFIX = 'wordpress/configuration';
    private const LOGICAL_KEY_READING = 'reading-settings';
    private const LOGICAL_KEY_PERMALINK = 'permalink-settings';

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'WordPress core configuration';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE_READING;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isConfigManagedSet(): bool
    {
        return true;
    }

    /**
     * @return string[]
     */
    public function getManagedSetDependencies(): array
    {
        return ['wordpress_pages'];
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
        $items = [
            $this->buildPermalinkSettingsItem(),
            $this->buildReadingSettingsItem(),
        ];
        usort($items, static fn (ManagedContentItem $left, ManagedContentItem $right): int => $left->logicalKey <=> $right->logicalKey);
        $files = [];
        $orderedLogicalKeys = [];

        foreach ($items as $item) {
            $orderedLogicalKeys[] = $item->logicalKey;
            $files[$this->getRepositoryPath($item)] = $this->serialize($item);
        }

        $manifest = new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, $orderedLogicalKeys);
        $files[$this->getManifestPath()] = $this->serializeManifest($manifest);
        ksort($files);

        return new WordPressCoreConfigurationSnapshot($items, $manifest, $files, $orderedLogicalKeys);
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
            if ($path === $this->getManifestPath() || ! $this->ownsRepositoryPath($path) || ! str_ends_with($path, '.json')) {
                continue;
            }

            $item = $this->deserialize($path, $content);
            $items[$item->logicalKey] = $item;
        }

        ksort($items);
        $this->validateManifest($manifest, array_values($items));

        return new WordPressCoreConfigurationSnapshot(array_values($items), $manifest, $files, $manifest->orderedLogicalKeys);
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        return match ($logicalKey) {
            self::LOGICAL_KEY_READING => $this->buildReadingSettingsItem(),
            self::LOGICAL_KEY_PERMALINK => $this->buildPermalinkSettingsItem(),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $wpRecord
     */
    public function computeLogicalKey(array $wpRecord): string
    {
        $logicalKey = (string) ($wpRecord['logicalKey'] ?? '');

        if ($logicalKey === '') {
            throw new ManagedContentExportException('WordPress core configuration logical key is missing.');
        }

        return $logicalKey;
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return sprintf('%s/%s.json', self::PATH_PREFIX, $item->logicalKey);
    }

    public function serialize(ManagedContentItem $item): string
    {
        $this->validateItem($item);

        return CanonicalJson::encode($item->toArray());
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
            self::MANAGED_SET_KEY,
            (string) ($decoded['type'] ?? self::CONTENT_TYPE_READING),
            (string) ($decoded['logicalKey'] ?? ''),
            (string) ($decoded['displayName'] ?? ''),
            (string) ($decoded['selector'] ?? ''),
            (string) ($decoded['slug'] ?? ''),
            is_array($payload) ? $payload : [],
            (string) ($decoded['postStatus'] ?? 'publish'),
            is_array($metadata) ? $metadata : [],
            is_array($derived) ? $derived : [],
            null,
            (int) ($decoded['schemaVersion'] ?? 1),
            (int) ($decoded['adapterVersion'] ?? 1)
        );
    }

    public function getManifestPath(): string
    {
        return self::PATH_PREFIX . '/manifest.json';
    }

    public function ownsRepositoryPath(string $path): bool
    {
        return $path === $this->getManifestPath()
            || (str_starts_with($path, self::PATH_PREFIX . '/') && str_ends_with($path, '.json'));
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

    /**
     * @param ManagedContentItem[] $items
     */
    public function validateManifest(ManagedCollectionManifest $manifest, array $items): void
    {
        if ($manifest->manifestType !== self::MANIFEST_TYPE) {
            throw new ManagedContentExportException('Invalid WordPress core configuration manifest type.');
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

    public function buildCommitMessage(): string
    {
        return 'Commit live WordPress core configuration';
    }

    public function applyItem(ManagedContentItem $item): void
    {
        $this->validateItem($item);

        if ($item->contentType === self::CONTENT_TYPE_READING) {
            $payload = $item->payload;
            $showOnFront = (string) ($payload['showOnFront'] ?? 'posts');

            if (! in_array($showOnFront, ['posts', 'page'], true)) {
                throw new RuntimeException(sprintf('Unsupported show_on_front value "%s".', $showOnFront));
            }

            update_option('show_on_front', $showOnFront, false);
            update_option('page_on_front', $this->resolvePageOptionValue($payload['frontPageRef'] ?? null), false);
            update_option('page_for_posts', $this->resolvePageOptionValue($payload['postsPageRef'] ?? null), false);

            return;
        }

        if ($item->contentType === self::CONTENT_TYPE_PERMALINK) {
            update_option('permalink_structure', (string) ($item->payload['permalinkStructure'] ?? ''), false);

            return;
        }

        throw new RuntimeException(sprintf('Unsupported WordPress core configuration content type "%s".', $item->contentType));
    }

    private function buildReadingSettingsItem(): ManagedContentItem
    {
        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE_READING,
            self::LOGICAL_KEY_READING,
            'Reading settings',
            self::LOGICAL_KEY_READING,
            self::LOGICAL_KEY_READING,
            [
                'showOnFront' => (string) get_option('show_on_front', 'posts'),
                'frontPageRef' => $this->referenceForPageOption('page_on_front'),
                'postsPageRef' => $this->referenceForPageOption('page_for_posts'),
            ],
            'publish',
            [
                'restoration' => [
                    'optionNames' => ['show_on_front', 'page_on_front', 'page_for_posts'],
                ],
            ]
        );
    }

    private function buildPermalinkSettingsItem(): ManagedContentItem
    {
        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE_PERMALINK,
            self::LOGICAL_KEY_PERMALINK,
            'Permalink settings',
            self::LOGICAL_KEY_PERMALINK,
            self::LOGICAL_KEY_PERMALINK,
            [
                'permalinkStructure' => (string) get_option('permalink_structure', ''),
            ],
            'publish',
            [
                'restoration' => [
                    'optionNames' => ['permalink_structure'],
                ],
            ]
        );
    }

    /**
     * @return array{managedSetKey: string, contentType: string, logicalKey: string, postType: string}|null
     */
    private function referenceForPageOption(string $optionName): ?array
    {
        $pageId = (int) get_option($optionName, 0);

        if ($pageId <= 0) {
            return null;
        }

        $page = $this->findPageById($pageId);

        if (! $page instanceof WP_Post || $page->post_type !== 'page' || $page->post_name === '') {
            throw new ManagedContentExportException(sprintf(
                'WordPress option %s references missing page %d.',
                $optionName,
                $pageId
            ));
        }

        return [
            'managedSetKey' => 'wordpress_pages',
            'contentType' => 'wordpress_page',
            'logicalKey' => $page->post_name,
            'postType' => 'page',
        ];
    }

    private function resolvePageOptionValue(mixed $reference): int
    {
        if (! is_array($reference)) {
            return 0;
        }

        $logicalKey = (string) ($reference['logicalKey'] ?? '');

        if ($logicalKey === '') {
            return 0;
        }

        $page = $this->findPageByLogicalKey($logicalKey);

        if (! $page instanceof WP_Post) {
            throw new RuntimeException(sprintf(
                'WordPress core configuration references missing page "%s".',
                $logicalKey
            ));
        }

        return $page->ID;
    }

    private function findPageById(int $pageId): ?WP_Post
    {
        foreach ($this->allPages() as $page) {
            if ($page->ID === $pageId) {
                return $page;
            }
        }

        return null;
    }

    private function findPageByLogicalKey(string $logicalKey): ?WP_Post
    {
        foreach ($this->allPages() as $page) {
            if ($page->post_name === $logicalKey) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return WP_Post[]
     */
    private function allPages(): array
    {
        return get_posts([
            'post_type' => 'page',
            'numberposts' => -1,
            'post_status' => 'any',
        ]);
    }

    private function validateItem(ManagedContentItem $item): void
    {
        if ($item->managedSetKey !== self::MANAGED_SET_KEY) {
            throw new ManagedContentExportException('Managed content item belongs to an unexpected managed set.');
        }

        if (! in_array($item->contentType, [self::CONTENT_TYPE_READING, self::CONTENT_TYPE_PERMALINK], true)) {
            throw new ManagedContentExportException('Managed content item has an unexpected content type.');
        }

        if (! is_array($item->payload)) {
            throw new ManagedContentExportException('Managed content item payload must be an object.');
        }
    }
}
