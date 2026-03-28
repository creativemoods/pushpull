<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\WordPressManagedContentAdapterInterface;
use PushPull\Support\Json\CanonicalJson;
use WP_Post;

final class WordPressAttachmentsAdapter implements WordPressManagedContentAdapterInterface
{
    public const SYNC_META_KEY = 'pushpull_sync_attachment';

    private const MANAGED_SET_KEY = 'wordpress_attachments';
    private const CONTENT_TYPE = 'wordpress_attachment';
    private const MANIFEST_TYPE = 'wordpress_attachments_virtual_manifest';
    private const POST_TYPE = 'attachment';
    private const PATH_PREFIX = 'wordpress/attachments';

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'WordPress attachments';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function isAvailable(): bool
    {
        return post_type_exists(self::POST_TYPE) && function_exists('wp_upload_dir') && function_exists('wp_mkdir_p');
    }

    public function exportAll(): array
    {
        return $this->exportSnapshot()->items;
    }

    public function exportSnapshot(): WordPressAttachmentsSnapshot
    {
        $items = [];
        $files = [];
        $orderedLogicalKeys = [];

        if ($this->isAvailable()) {
            foreach ($this->managedAttachments() as $post) {
                $record = $this->buildRuntimeRecord($post);
                $item = $this->buildItemFromRuntimeRecord($record);
                $items[] = $item;
                $orderedLogicalKeys[] = $item->logicalKey;
                $files[$this->getRepositoryPath($item)] = $this->serialize($item);
                $files[$this->binaryRepositoryPath($item)] = (string) ($record['binary_content'] ?? '');
            }
        }

        sort($orderedLogicalKeys);
        usort($items, static fn (ManagedContentItem $a, ManagedContentItem $b): int => $a->logicalKey <=> $b->logicalKey);
        ksort($files);

        return new WordPressAttachmentsSnapshot(
            $items,
            new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, $orderedLogicalKeys),
            $files,
            $orderedLogicalKeys
        );
    }

    /**
     * @param array<string, string> $files
     */
    public function readSnapshotFromRepositoryFiles(array $files): WordPressAttachmentsSnapshot
    {
        $items = [];
        $orderedLogicalKeys = [];

        foreach ($files as $path => $content) {
            if (! $this->isManagedItemPath($path)) {
                continue;
            }

            $item = $this->deserialize($path, $content);
            $binaryPath = $this->binaryRepositoryPath($item);

            if (! array_key_exists($binaryPath, $files)) {
                throw new ManagedContentExportException(sprintf('Attachment binary is missing at %s.', $binaryPath));
            }

            $items[] = $item;
            $orderedLogicalKeys[] = $item->logicalKey;
        }

        sort($orderedLogicalKeys);
        usort($items, static fn (ManagedContentItem $a, ManagedContentItem $b): int => $a->logicalKey <=> $b->logicalKey);

        return new WordPressAttachmentsSnapshot(
            $items,
            new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, $orderedLogicalKeys),
            $files,
            $orderedLogicalKeys
        );
    }

    public function getManifestPath(): string
    {
        return self::PATH_PREFIX . '/.virtual-manifest.json';
    }

    public function ownsRepositoryPath(string $path): bool
    {
        return str_starts_with($path, self::PATH_PREFIX . '/');
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

    public function validateManifest(ManagedCollectionManifest $manifest, array $items): void
    {
    }

    public function parseManifest(string $content): ManagedCollectionManifest
    {
        return new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, []);
    }

    public function buildCommitMessage(): string
    {
        return 'Commit live WordPress attachments';
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        foreach ($this->exportSnapshot()->items as $item) {
            if ($item->logicalKey === $logicalKey) {
                return $item;
            }
        }

        return null;
    }

    public function computeLogicalKey(array $wpRecord): string
    {
        $uploadsPath = trim((string) ($wpRecord['uploads_path'] ?? $wpRecord['_wp_attached_file'] ?? ''));

        if ($uploadsPath === '') {
            throw new ManagedContentExportException('WordPress attachment uploads path cannot be empty.');
        }

        $directory = trim(dirname($uploadsPath), '.');
        $filename = pathinfo($uploadsPath, PATHINFO_FILENAME);
        $extension = pathinfo($uploadsPath, PATHINFO_EXTENSION);
        $basename = sanitize_title($filename . ($extension !== '' ? '-' . $extension : ''));

        return trim(($directory !== '' ? $directory . '/' : '') . $basename, '/');
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return self::PATH_PREFIX . '/' . $item->logicalKey . '/attachment.json';
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
            (string) ($decoded['postStatus'] ?? 'inherit'),
            is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
            is_array($decoded['derived'] ?? null) ? $decoded['derived'] : [],
            null,
            (int) ($decoded['schemaVersion'] ?? 1),
            (int) ($decoded['adapterVersion'] ?? 1)
        );
    }

    public function isManagedItemPath(string $path): bool
    {
        return str_starts_with($path, self::PATH_PREFIX . '/') && str_ends_with($path, '/attachment.json');
    }

    public function findExistingWpObjectIdByLogicalKey(string $logicalKey): ?int
    {
        foreach ($this->allAttachments() as $post) {
            $record = $this->buildRuntimeRecord($post);

            if ($this->computeLogicalKey($record) === $logicalKey) {
                return (int) $post->ID;
            }
        }

        return null;
    }

    public function postExists(int $postId): bool
    {
        foreach ($this->allAttachments() as $post) {
            if ($post->ID === $postId) {
                return true;
            }
        }

        return false;
    }

    public function upsertItem(ManagedContentItem $item, int $menuOrder, ?int $existingId): int
    {
        $postData = wp_slash([
            'post_type' => self::POST_TYPE,
            'post_title' => (string) ($item->payload['title'] ?? $item->displayName),
            'post_name' => $item->slug,
            'post_status' => $item->postStatus,
            'post_content' => (string) ($item->payload['description'] ?? ''),
            'post_excerpt' => (string) ($item->payload['caption'] ?? ''),
            'post_mime_type' => (string) ($item->payload['mimeType'] ?? ''),
        ]);

        if ($existingId !== null) {
            $postData['ID'] = $existingId;

            return (int) wp_update_post($postData);
        }

        return (int) wp_insert_post($postData);
    }

    public function persistItemMeta(int $postId, ManagedContentItem $item, array $snapshotFiles = []): void
    {
        $binaryPath = $this->binaryRepositoryPath($item);
        $binaryContent = $snapshotFiles[$binaryPath] ?? null;

        if (! is_string($binaryContent)) {
            throw new ManagedContentExportException(sprintf('Attachment binary is missing at %s.', $binaryPath));
        }

        $upload = wp_upload_dir();
        $relativePath = (string) ($item->payload['uploadsPath'] ?? '');
        $targetPath = rtrim((string) ($upload['basedir'] ?? ''), '/\\') . '/' . ltrim($relativePath, '/');
        $targetDirectory = dirname($targetPath);

        if (! wp_mkdir_p($targetDirectory)) {
            throw new ManagedContentExportException(sprintf('Could not create uploads directory for %s.', $relativePath));
        }

        if (file_put_contents($targetPath, $binaryContent) === false) {
            throw new ManagedContentExportException(sprintf('Could not write attachment binary to %s.', $relativePath));
        }

        update_post_meta($postId, '_wp_attached_file', $relativePath);
        update_post_meta($postId, self::SYNC_META_KEY, '1');

        $attachmentMetadata = $item->metadata['attachmentMetadata'] ?? null;
        $generatedAttachmentMetadata = $this->generateAttachmentMetadata($postId, $targetPath, $relativePath);

        if ($generatedAttachmentMetadata !== null) {
            if (function_exists('wp_update_attachment_metadata')) {
                wp_update_attachment_metadata($postId, $generatedAttachmentMetadata);
            } else {
                update_post_meta($postId, '_wp_attachment_metadata', $generatedAttachmentMetadata);
            }
        } elseif ($attachmentMetadata !== null) {
            update_post_meta($postId, '_wp_attachment_metadata', $attachmentMetadata);
        } else {
            delete_post_meta($postId, '_wp_attachment_metadata');
        }

        $altText = (string) ($item->payload['altText'] ?? '');

        if ($altText !== '') {
            update_post_meta($postId, '_wp_attachment_image_alt', $altText);
        } else {
            delete_post_meta($postId, '_wp_attachment_image_alt');
        }
    }

    /**
     * Regenerate attachment metadata on the target site when WordPress image helpers are available.
     * This keeps thumbnails and sub-sizes aligned with the files that actually exist locally.
     *
     * @return array<string, mixed>|null
     */
    private function generateAttachmentMetadata(int $postId, string $targetPath, string $relativePath): ?array
    {
        if (! function_exists('wp_generate_attachment_metadata')) {
            $imageFunctions = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/image.php' : '';

            if ($imageFunctions !== '' && is_readable($imageFunctions)) {
                require_once $imageFunctions;
            }
        }

        if (! function_exists('wp_generate_attachment_metadata')) {
            return null;
        }

        $generatedMetadata = wp_generate_attachment_metadata($postId, $targetPath);

        if (! is_array($generatedMetadata)) {
            return null;
        }

        if (($generatedMetadata['file'] ?? '') === '') {
            $generatedMetadata['file'] = $relativePath;
        }

        return $generatedMetadata;
    }

    public function deleteMissingItems(array $desiredLogicalKeys): array
    {
        $deletedLogicalKeys = [];

        foreach ($this->managedAttachments() as $post) {
            $record = $this->buildRuntimeRecord($post);
            $logicalKey = $this->computeLogicalKey($record);

            if (isset($desiredLogicalKeys[$logicalKey])) {
                continue;
            }

            wp_delete_post($post->ID, true);
            $deletedLogicalKeys[] = $logicalKey;
        }

        sort($deletedLogicalKeys);

        return $deletedLogicalKeys;
    }

    private function validateItem(ManagedContentItem $item): void
    {
        if ($item->logicalKey === '') {
            throw new ManagedContentExportException('Managed attachment logical key cannot be empty.');
        }

        if ((string) ($item->payload['uploadsPath'] ?? '') === '') {
            throw new ManagedContentExportException(sprintf('Managed attachment "%s" is missing uploadsPath.', $item->logicalKey));
        }

        if ((string) ($item->payload['binaryFile'] ?? '') === '') {
            throw new ManagedContentExportException(sprintf('Managed attachment "%s" is missing binaryFile.', $item->logicalKey));
        }
    }

    /**
     * @return array<int, WP_Post>
     */
    private function allAttachments(): array
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => ['inherit', 'publish', 'private'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return array_values(array_filter($posts, static fn (mixed $post): bool => $post instanceof WP_Post));
    }

    /**
     * @return array<int, WP_Post>
     */
    private function managedAttachments(): array
    {
        return array_values(array_filter(
            $this->allAttachments(),
            fn (WP_Post $post): bool => $this->isAttachmentMarkedForSync((int) $post->ID)
        ));
    }

    private function isAttachmentMarkedForSync(int $postId): bool
    {
        $value = get_post_meta($postId, self::SYNC_META_KEY, true);

        return in_array((string) $value, ['1', 'yes', 'on', 'true'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRuntimeRecord(WP_Post $post): array
    {
        $uploadsPath = (string) get_post_meta((int) $post->ID, '_wp_attached_file', true);
        $upload = wp_upload_dir();
        $absolutePath = rtrim((string) ($upload['basedir'] ?? ''), '/\\') . '/' . ltrim($uploadsPath, '/');
        $binaryContent = is_file($absolutePath) ? file_get_contents($absolutePath) : '';

        return [
            'wp_object_id' => (int) $post->ID,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_status' => (string) $post->post_status,
            'post_content' => (string) ($post->post_content ?? ''),
            'post_excerpt' => (string) ($post->post_excerpt ?? ''),
            'post_mime_type' => (string) ($post->post_mime_type ?? ''),
            'uploads_path' => $uploadsPath,
            'attachment_metadata' => maybe_unserialize(get_post_meta((int) $post->ID, '_wp_attachment_metadata', true)),
            'alt_text' => (string) get_post_meta((int) $post->ID, '_wp_attachment_image_alt', true),
            'binary_content' => is_string($binaryContent) ? $binaryContent : '',
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function buildItemFromRuntimeRecord(array $record): ManagedContentItem
    {
        $logicalKey = $this->computeLogicalKey($record);
        $uploadsPath = (string) ($record['uploads_path'] ?? '');
        $filename = basename($uploadsPath);
        $title = trim((string) ($record['post_title'] ?? '')) !== '' ? (string) $record['post_title'] : pathinfo($filename, PATHINFO_FILENAME);
        $slug = trim((string) ($record['post_name'] ?? '')) !== '' ? (string) ($record['post_name']) : sanitize_title(pathinfo($filename, PATHINFO_FILENAME));
        $metadata = [
            'restoration' => [
                'postType' => self::POST_TYPE,
            ],
        ];

        if (($record['attachment_metadata'] ?? null) !== null && $record['attachment_metadata'] !== '') {
            $metadata['attachmentMetadata'] = $record['attachment_metadata'];
        }

        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            $logicalKey,
            $title,
            $logicalKey,
            $slug,
            [
                'uploadsPath' => $uploadsPath,
                'filename' => $filename,
                'mimeType' => (string) ($record['post_mime_type'] ?? ''),
                'title' => $title,
                'caption' => (string) ($record['post_excerpt'] ?? ''),
                'description' => (string) ($record['post_content'] ?? ''),
                'altText' => (string) ($record['alt_text'] ?? ''),
                'binaryFile' => $filename,
                'binarySha1' => sha1((string) ($record['binary_content'] ?? '')),
            ],
            (string) ($record['post_status'] ?? 'inherit'),
            $metadata
        );
    }

    private function binaryRepositoryPath(ManagedContentItem $item): string
    {
        return self::PATH_PREFIX . '/' . $item->logicalKey . '/' . (string) ($item->payload['binaryFile'] ?? '');
    }
}
