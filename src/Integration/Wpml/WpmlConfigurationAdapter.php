<?php

declare(strict_types=1);

namespace PushPull\Integration\Wpml;

use PushPull\Content\ConfigManagedContentAdapterInterface;
use PushPull\Content\ConfigManagedSetInterface;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedContentSnapshot;
use PushPull\Support\Json\CanonicalJson;
use RuntimeException;

final class WpmlConfigurationAdapter implements ConfigManagedContentAdapterInterface, ConfigManagedSetInterface
{
    private const MANAGED_SET_KEY = 'wpml_configuration';
    private const CONTENT_TYPE = 'wpml_configuration_settings';
    private const MANIFEST_TYPE = 'wpml_configuration_manifest';
    private const PATH_PREFIX = 'wpml/configuration';
    private const LOGICAL_KEY = 'wpml-settings';

    public function __construct(private readonly WpmlConfigurationApplier $applier)
    {
    }

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'WPML configuration';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function isAvailable(): bool
    {
        return $this->applier->isAvailable();
    }

    public function isConfigManagedSet(): bool
    {
        return true;
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
        $item = $this->buildSettingsItem();
        $files = [
            $this->getRepositoryPath($item) => $this->serialize($item),
        ];
        $manifest = new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, [self::LOGICAL_KEY]);
        $files[$this->getManifestPath()] = $this->serializeManifest($manifest);
        ksort($files);

        return new ManagedContentSnapshot([$item], $manifest, $files, [self::LOGICAL_KEY]);
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
        $itemContent = $files[self::PATH_PREFIX . '/' . self::LOGICAL_KEY . '.json'] ?? null;

        if ($itemContent === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            throw new ManagedContentExportException(sprintf('Manifest references unknown logical key: %s', self::LOGICAL_KEY));
        }

        $item = $this->deserialize(self::PATH_PREFIX . '/' . self::LOGICAL_KEY . '.json', $itemContent);
        $this->validateManifest($manifest, [$item]);

        return new ManagedContentSnapshot([$item], $manifest, $files, [self::LOGICAL_KEY]);
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        return $logicalKey === self::LOGICAL_KEY ? $this->buildSettingsItem() : null;
    }

    /**
     * @param array<string, mixed> $wpRecord
     */
    public function computeLogicalKey(array $wpRecord): string
    {
        $logicalKey = (string) ($wpRecord['logicalKey'] ?? '');

        if ($logicalKey === '') {
            throw new ManagedContentExportException('WPML configuration logical key is missing.');
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
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            throw new ManagedContentExportException(sprintf('Invalid managed content JSON at %s.', $path));
        }

        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            (string) ($decoded['type'] ?? self::CONTENT_TYPE),
            (string) ($decoded['logicalKey'] ?? ''),
            (string) ($decoded['displayName'] ?? ''),
            (string) ($decoded['selector'] ?? ''),
            (string) ($decoded['slug'] ?? ''),
            is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [],
            (string) ($decoded['postStatus'] ?? 'publish'),
            is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
            is_array($decoded['derived'] ?? null) ? $decoded['derived'] : [],
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
            || $path === self::PATH_PREFIX . '/' . self::LOGICAL_KEY . '.json';
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
            throw new ManagedContentExportException('Invalid WPML configuration manifest type.');
        }

        if ($manifest->orderedLogicalKeys !== [self::LOGICAL_KEY] || count($items) !== 1 || $items[0]->logicalKey !== self::LOGICAL_KEY) {
            throw new ManagedContentExportException('WPML configuration manifest does not match the managed items.');
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
        return 'Commit live WPML configuration';
    }

    public function applyItem(ManagedContentItem $item): void
    {
        $this->validateItem($item);
        $this->applier->applyConfiguration($item->payload);
    }

    private function buildSettingsItem(): ManagedContentItem
    {
        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            self::LOGICAL_KEY,
            'WPML settings',
            self::LOGICAL_KEY,
            self::LOGICAL_KEY,
            $this->applier->exportConfiguration(),
            'publish',
            [
                'restoration' => [
                    'optionNames' => [WpmlConfigurationApplier::SETTINGS_OPTION],
                ],
            ]
        );
    }

    private function validateItem(ManagedContentItem $item): void
    {
        if ($item->managedSetKey !== self::MANAGED_SET_KEY) {
            throw new ManagedContentExportException('Managed content item belongs to an unexpected managed set.');
        }

        if ($item->contentType !== self::CONTENT_TYPE) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            throw new ManagedContentExportException(sprintf('Unsupported WPML configuration content type "%s".', $item->contentType));
        }

        if ($item->logicalKey !== self::LOGICAL_KEY) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            throw new RuntimeException(sprintf('Unsupported WPML configuration logical key "%s".', $item->logicalKey));
        }
    }
}
