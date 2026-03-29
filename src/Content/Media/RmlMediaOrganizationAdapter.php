<?php

declare(strict_types=1);

namespace PushPull\Content\Media;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedSetDependencyAwareInterface;
use PushPull\Content\OverlayManagedContentAdapterInterface;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Json\CanonicalJson;
use RuntimeException;

final class RmlMediaOrganizationAdapter implements OverlayManagedContentAdapterInterface, ManagedSetDependencyAwareInterface
{
    private const MANAGED_SET_KEY = 'media_organization';
    private const CONTENT_TYPE = 'media_folder_assignment';
    private const MANIFEST_TYPE = 'media_organization_manifest';
    private const PATH_PREFIX = 'media/organization';
    private const ROOT_FOLDER_TYPE = 0;

    private readonly WordPressAttachmentsAdapter $attachmentsAdapter;

    public function __construct(private readonly SettingsRepository $settingsRepository)
    {
        $this->attachmentsAdapter = new WordPressAttachmentsAdapter();
    }

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'Media organization';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function isOverlayManagedSet(): bool
    {
        return true;
    }

    /**
     * @return string[]
     */
    public function getManagedSetDependencies(): array
    {
        return ['wordpress_attachments'];
    }

    public function isAvailable(): bool
    {
        return function_exists('wp_attachment_folder')
            && function_exists('wp_rml_get_by_id')
            && function_exists('wp_rml_get_by_absolute_path')
            && function_exists('wp_rml_create_or_return_existing_id')
            && function_exists('wp_rml_move')
            && function_exists('_wp_rml_root');
    }

    /**
     * @return ManagedContentItem[]
     */
    public function exportAll(): array
    {
        return $this->exportSnapshot()->items;
    }

    public function exportSnapshot(): MediaOrganizationSnapshot
    {
        $items = [];
        $files = [];
        $orderedLogicalKeys = [];

        foreach ($this->assignmentsInScope() as $assignment) {
            $item = $this->buildItemFromAssignment($assignment);
            $items[] = $item;
            $orderedLogicalKeys[] = $item->logicalKey;
            $files[$this->getRepositoryPath($item)] = $this->serialize($item);
        }

        sort($orderedLogicalKeys);
        usort($items, static fn (ManagedContentItem $left, ManagedContentItem $right): int => $left->logicalKey <=> $right->logicalKey);
        $manifest = new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, $orderedLogicalKeys);
        $files[$this->getManifestPath()] = $this->serializeManifest($manifest);
        ksort($files);

        return new MediaOrganizationSnapshot($items, $manifest, $files, $orderedLogicalKeys);
    }

    /**
     * @param array<string, string> $files
     */
    public function readSnapshotFromRepositoryFiles(array $files): MediaOrganizationSnapshot
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

        return new MediaOrganizationSnapshot(array_values($items), $manifest, $files, $manifest->orderedLogicalKeys);
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        foreach ($this->assignmentsInScope() as $assignment) {
            $item = $this->buildItemFromAssignment($assignment);

            if ($item->logicalKey === $logicalKey) {
                return $item;
            }
        }

        return null;
    }

    public function computeLogicalKey(array $wpRecord): string
    {
        $contentDomain = (string) ($wpRecord['contentDomain'] ?? '');
        $contentLogicalKey = (string) ($wpRecord['contentLogicalKey'] ?? '');

        if ($contentDomain === '' || $contentLogicalKey === '') {
            throw new ManagedContentExportException('Media organization logical key requires contentDomain and contentLogicalKey.');
        }

        return $contentDomain . ':' . $contentLogicalKey;
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
        return $path === $this->getManifestPath()
            || (str_starts_with($path, self::PATH_PREFIX . '/') && str_ends_with($path, '.json'));
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
            throw new ManagedContentExportException('Invalid media organization manifest type.');
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
        return 'Commit live media organization';
    }

    public function applyOverlayItem(ManagedContentItem $item): bool
    {
        $this->validateItem($item);

        $attachmentLogicalKey = (string) ($item->payload['contentLogicalKey'] ?? '');
        $attachmentId = $this->attachmentsAdapter->findExistingWpObjectIdByLogicalKey($attachmentLogicalKey);

        if ($attachmentId === null) {
            throw new RuntimeException(sprintf(
                'Media organization references missing attachment "%s".',
                $attachmentLogicalKey
            ));
        }

        $targetFolderId = $this->resolveFolderIdForPath($item->payload['folderPath'] ?? null);
        $result = wp_rml_move($targetFolderId, [$attachmentId], true);

        if ($result !== true) {
            $message = is_array($result) ? implode('; ', array_map('strval', $result)) : 'Unknown RML move error.';
            throw new RuntimeException(sprintf('Could not move attachment "%s" into its media folder: %s', $attachmentLogicalKey, $message));
        }

        return false;
    }

    public function deleteMissingOverlayItems(array $desiredLogicalKeys): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function buildItemFromAssignment(array $assignment): ManagedContentItem
    {
        $contentLogicalKey = (string) $assignment['contentLogicalKey'];
        $logicalKey = $this->computeLogicalKey([
            'contentDomain' => 'wordpress_attachments',
            'contentLogicalKey' => $contentLogicalKey,
        ]);

        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            $logicalKey,
            sprintf('Media folder: %s', $contentLogicalKey),
            $logicalKey,
            $logicalKey,
            [
                'contentDomain' => 'wordpress_attachments',
                'contentType' => 'wordpress_attachment',
                'contentLogicalKey' => $contentLogicalKey,
                'folderPath' => $assignment['folderPath'],
            ],
            'publish',
            [
                'backend' => [
                    'provider' => 'rml',
                ],
            ]
        );
    }

    /**
     * @return array<int, array{contentLogicalKey: string, folderPath: string|null}>
     */
    private function assignmentsInScope(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $settings = $this->settingsRepository->get();

        if (! $settings->isManagedSetEnabled('wordpress_attachments')) {
            return [];
        }

        $assignments = [];

        foreach ($this->managedAttachments() as $attachmentId => $attachmentLogicalKey) {
            $folderId = wp_attachment_folder($attachmentId, _wp_rml_root());
            $assignments[] = [
                'contentLogicalKey' => $attachmentLogicalKey,
                'folderPath' => $this->folderPathForFolderId((int) $folderId),
            ];
        }

        usort($assignments, static fn (array $left, array $right): int => $left['contentLogicalKey'] <=> $right['contentLogicalKey']);

        return $assignments;
    }

    /**
     * @return array<int, string>
     */
    private function managedAttachments(): array
    {
        $attachments = [];

        foreach (
            get_posts([
                'post_type' => 'attachment',
                'numberposts' => -1,
                'post_status' => 'any',
            ]) as $post
        ) {
            $syncEnabled = (string) get_post_meta((int) $post->ID, WordPressAttachmentsAdapter::SYNC_META_KEY, true);

            if ($syncEnabled === '' || $syncEnabled === '0') {
                continue;
            }

            $uploadsPath = trim((string) get_post_meta((int) $post->ID, '_wp_attached_file', true));

            if ($uploadsPath === '') {
                continue;
            }

            $attachments[(int) $post->ID] = $this->attachmentsAdapter->computeLogicalKey([
                '_wp_attached_file' => $uploadsPath,
            ]);
        }

        ksort($attachments);

        return $attachments;
    }

    private function folderPathForFolderId(int $folderId): ?string
    {
        if ($folderId === _wp_rml_root()) {
            return null;
        }

        $folder = wp_rml_get_by_id($folderId, null, true, false);

        if (! is_object($folder) || ! method_exists($folder, 'getAbsolutePath')) {
            return null;
        }

        $path = trim((string) $folder->getAbsolutePath(), '/');

        return $path !== '' ? $path : null;
    }

    private function resolveFolderIdForPath(mixed $folderPath): int
    {
        if (! is_string($folderPath) || trim($folderPath) === '') {
            return _wp_rml_root();
        }

        $normalizedPath = trim($folderPath, '/');
        $existing = wp_rml_get_by_absolute_path('/' . $normalizedPath);

        if (is_object($existing) && method_exists($existing, 'getId')) {
            return (int) $existing->getId();
        }

        $parentId = _wp_rml_root();

        foreach (explode('/', $normalizedPath) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $created = wp_rml_create_or_return_existing_id($segment, $parentId, self::ROOT_FOLDER_TYPE, [], true);

            if (! is_int($created)) {
                $message = is_array($created) ? implode('; ', array_map('strval', $created)) : 'Unknown RML create error.';
                throw new RuntimeException(sprintf('Could not create media folder path "%s": %s', $normalizedPath, $message));
            }

            $parentId = $created;
        }

        return $parentId;
    }

    private function validateItem(ManagedContentItem $item): void
    {
        if ($item->managedSetKey !== self::MANAGED_SET_KEY) {
            throw new ManagedContentExportException('Managed content item belongs to an unexpected managed set.');
        }

        if ($item->contentType !== self::CONTENT_TYPE) {
            throw new ManagedContentExportException('Managed content item has an unexpected content type.');
        }

        if (! isset($item->payload['contentLogicalKey'])) {
            throw new ManagedContentExportException(sprintf('Media organization item "%s" is missing its attachment reference.', $item->logicalKey));
        }
    }
}
