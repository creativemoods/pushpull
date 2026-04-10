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
use RuntimeException;
use WP_Comment;
use WP_Post;

final class WordPressCommentsAdapter implements WordPressManagedContentAdapterInterface, ManagedSetDependencyAwareInterface
{
    private const MANAGED_SET_KEY = 'wordpress_comments';
    private const CONTENT_TYPE = 'wordpress_comment';
    private const MANIFEST_TYPE = 'wordpress_comments_manifest';
    private const PATH_PREFIX = 'wordpress/comments';

    public function __construct(
        private readonly WordPressPagesAdapter $pagesAdapter = new WordPressPagesAdapter(),
        private readonly WordPressPostsAdapter $postsAdapter = new WordPressPostsAdapter()
    ) {
    }

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'WordPress comments';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    /**
     * @return string[]
     */
    public function getManagedSetDependencies(): array
    {
        return ['wordpress_pages', 'wordpress_posts'];
    }

    public function isAvailable(): bool
    {
        return function_exists('get_comments')
            && function_exists('wp_insert_comment')
            && function_exists('wp_update_comment')
            && function_exists('wp_delete_comment')
            && function_exists('get_comment_meta')
            && function_exists('add_comment_meta')
            && function_exists('delete_comment_meta');
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
        $records = $this->runtimeRecords();
        $items = [];

        foreach ($records as $record) {
            $items[] = $this->buildItemFromRuntimeRecord($record);
        }

        $manifest = $this->buildManifest($records);
        $this->validateManifest($manifest, $items);

        return new WordPressCommentsSnapshot($items, $manifest, [], $manifest->orderedLogicalKeys);
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

        return new WordPressCommentsSnapshot(array_values($items), $manifest, $files, $manifest->orderedLogicalKeys);
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
        return sha1($this->serialize($item));
    }

    public function hashManifest(ManagedCollectionManifest $manifest): string
    {
        return sha1($this->serializeManifest($manifest));
    }

    public function buildCommitMessage(): string
    {
        return 'Commit live WordPress comments';
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        foreach ($this->runtimeRecords() as $record) {
            if ($this->computeLogicalKey($record) === $logicalKey) {
                return $this->buildItemFromRuntimeRecord($record);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $wpRecord
     */
    public function computeLogicalKey(array $wpRecord): string
    {
        $logicalKey = trim((string) ($wpRecord['_logical_key'] ?? ''));

        if ($logicalKey !== '') {
            return $logicalKey;
        }

        $postLogicalKey = (string) (($wpRecord['postRef']['objectRef']['logicalKey'] ?? '') ?: ($wpRecord['post_logical_key'] ?? ''));
        $dateGmt = preg_replace('/[^0-9]+/', '-', (string) ($wpRecord['comment_date_gmt'] ?? '')) ?? '';
        $authorSeed = (string) ($wpRecord['comment_author_email'] ?? '');

        if ($authorSeed === '') {
            $authorSeed = (string) ($wpRecord['comment_author'] ?? '');
        }

        $base = sanitize_title(trim($postLogicalKey . '-' . $dateGmt . '-' . $authorSeed, '-'));

        if ($base === '') {
            throw new ManagedContentExportException('WordPress comment logical key cannot be empty.');
        }

        $suffix = (int) ($wpRecord['_logical_key_suffix'] ?? 1);

        return $suffix > 1 ? $base . '-' . $suffix : $base;
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return sprintf('%s/%s.json', self::PATH_PREFIX, $item->logicalKey);
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
            (string) ($decoded['type'] ?? self::CONTENT_TYPE),
            (string) ($decoded['logicalKey'] ?? ''),
            (string) ($decoded['displayName'] ?? ''),
            (string) ($decoded['selector'] ?? ''),
            (string) ($decoded['slug'] ?? ''),
            is_array($payload)
                ? EnvironmentUrlCanonicalizer::denormalizeValue($payload)
                : ['raw' => EnvironmentUrlCanonicalizer::denormalizeValue($payload)],
            (string) ($decoded['postStatus'] ?? '1'),
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
            self::MANAGED_SET_KEY,
            (string) ($decoded['type'] ?? self::MANIFEST_TYPE),
            $decoded['orderedLogicalKeys'],
            (int) ($decoded['schemaVersion'] ?? 1)
        );
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
            throw new ManagedContentExportException('Invalid WordPress comments manifest type.');
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
            throw new ManagedContentExportException('Managed comment logical key cannot be empty.');
        }

        if (! is_string($item->payload['commentContent'] ?? null)) {
            throw new ManagedContentExportException(sprintf('Managed comment "%s" is missing its content.', $item->logicalKey));
        }

        if (! is_array($item->payload['postRef']['objectRef'] ?? null)) {
            throw new ManagedContentExportException(sprintf('Managed comment "%s" is missing its post reference.', $item->logicalKey));
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
        foreach ($this->runtimeRecords() as $record) {
            if ($this->computeLogicalKey($record) === $logicalKey) {
                return (int) ($record['wp_object_id'] ?? 0);
            }
        }

        return null;
    }

    public function postExists(int $postId): bool
    {
        foreach ($this->allComments() as $comment) {
            if ((int) ($comment->comment_ID ?? 0) === $postId) {
                return true;
            }
        }

        return false;
    }

    public function upsertItem(ManagedContentItem $item, int $menuOrder, ?int $existingId): int
    {
        $this->validateItem($item);

        $postId = $this->resolveParentPostId($item);
        $parentCommentId = $this->resolveParentCommentId($item);
        $commentData = [
            'comment_post_ID' => $postId,
            'comment_parent' => $parentCommentId,
            'comment_content' => (string) ($item->payload['commentContent'] ?? ''),
            'comment_author' => (string) ($item->payload['commentAuthor'] ?? ''),
            'comment_author_email' => (string) ($item->payload['commentAuthorEmail'] ?? ''),
            'comment_author_url' => (string) ($item->payload['commentAuthorUrl'] ?? ''),
            'comment_date_gmt' => (string) ($item->payload['commentDateGmt'] ?? ''),
            'comment_date' => (string) ($item->payload['commentDate'] ?? (string) ($item->payload['commentDateGmt'] ?? '')),
            'comment_type' => (string) ($item->payload['commentType'] ?? ''),
            'comment_approved' => $item->postStatus,
        ];

        if ($existingId !== null) {
            $commentData['comment_ID'] = $existingId;

            return (int) wp_update_comment(wp_slash($commentData));
        }

        return (int) wp_insert_comment(wp_slash($commentData));
    }

    public function persistItemMeta(int $postId, ManagedContentItem $item, array $snapshotFiles = []): void
    {
        foreach ($this->managedCommentMetaKeys($item) as $metaKey) {
            delete_comment_meta($postId, $metaKey);
        }

        foreach ($this->normalizeMetaEntries($item->metadata['commentMeta'] ?? []) as $entry) {
            add_comment_meta($postId, $entry['key'], wp_slash($entry['value']));
        }
    }

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    public function deleteMissingItems(array $desiredLogicalKeys): array
    {
        $deletedLogicalKeys = [];

        foreach ($this->runtimeRecords() as $record) {
            $logicalKey = $this->computeLogicalKey($record);

            if (isset($desiredLogicalKeys[$logicalKey])) {
                continue;
            }

            wp_delete_comment((int) ($record['wp_object_id'] ?? 0), true);
            $deletedLogicalKeys[] = $logicalKey;
        }

        return $deletedLogicalKeys;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function buildItemFromRuntimeRecord(array $record): ManagedContentItem
    {
        $logicalKey = $this->computeLogicalKey($record);
        $displayName = trim((string) ($record['comment_author'] ?? '')) !== ''
            ? sprintf('Comment by %s', (string) $record['comment_author'])
            : 'Comment ' . $logicalKey;
        $payload = EnvironmentUrlCanonicalizer::normalizeValue([
            'postRef' => $record['postRef'],
            'parentCommentLogicalKey' => (string) ($record['parent_comment_logical_key'] ?? ''),
            'commentAuthor' => (string) ($record['comment_author'] ?? ''),
            'commentAuthorEmail' => (string) ($record['comment_author_email'] ?? ''),
            'commentAuthorUrl' => (string) ($record['comment_author_url'] ?? ''),
            'commentDate' => (string) ($record['comment_date'] ?? ''),
            'commentDateGmt' => (string) ($record['comment_date_gmt'] ?? ''),
            'commentType' => (string) ($record['comment_type'] ?? ''),
            'commentContent' => (string) ($record['comment_content'] ?? ''),
        ]);
        $metadata = EnvironmentUrlCanonicalizer::normalizeValue([
            'restoration' => [
                'objectType' => 'comment',
            ],
            'commentMeta' => $this->normalizeMetaEntries($record['comment_meta'] ?? []),
        ]);

        $item = new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            $logicalKey,
            $displayName,
            $logicalKey,
            $logicalKey,
            is_array($payload) ? $payload : ['raw' => $payload],
            trim((string) ($record['comment_approved'] ?? '1')) !== '' ? (string) $record['comment_approved'] : '1',
            is_array($metadata) ? $metadata : [],
            [],
            isset($record['wp_object_id']) ? (int) $record['wp_object_id'] : null
        );

        $this->validateItem($item);

        return $item;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runtimeRecords(): array
    {
        $postRefMap = $this->postReferenceMap();
        $records = [];

        foreach ($this->allComments() as $comment) {
            $postRef = $postRefMap[(int) ($comment->comment_post_ID ?? 0)] ?? null;

            if (! is_array($postRef)) {
                continue;
            }

            $records[] = [
                'wp_object_id' => (int) ($comment->comment_ID ?? 0),
                'comment_post_ID' => (int) ($comment->comment_post_ID ?? 0),
                'comment_parent' => (int) ($comment->comment_parent ?? 0),
                'comment_author' => (string) ($comment->comment_author ?? ''),
                'comment_author_email' => (string) ($comment->comment_author_email ?? ''),
                'comment_author_url' => (string) ($comment->comment_author_url ?? ''),
                'comment_date' => (string) ($comment->comment_date ?? ''),
                'comment_date_gmt' => (string) ($comment->comment_date_gmt ?? ''),
                'comment_type' => (string) ($comment->comment_type ?? ''),
                'comment_content' => (string) ($comment->comment_content ?? ''),
                'comment_approved' => (string) ($comment->comment_approved ?? '1'),
                'postRef' => $postRef,
                'comment_meta' => $this->commentMetaEntries((int) ($comment->comment_ID ?? 0)),
            ];
        }

        usort(
            $records,
            static fn (array $left, array $right): int => [
                (string) ($left['postRef']['objectRef']['logicalKey'] ?? ''),
                (string) ($left['comment_date_gmt'] ?? ''),
                (int) ($left['wp_object_id'] ?? 0),
            ] <=> [
                (string) ($right['postRef']['objectRef']['logicalKey'] ?? ''),
                (string) ($right['comment_date_gmt'] ?? ''),
                (int) ($right['wp_object_id'] ?? 0),
            ]
        );

        $baseCounts = [];
        $logicalKeysByCommentId = [];

        foreach ($records as $index => $record) {
            $base = $this->computeLogicalKey($record);
            $baseCounts[$base] = ($baseCounts[$base] ?? 0) + 1;
            $records[$index]['_logical_key_suffix'] = $baseCounts[$base];
            $records[$index]['_logical_key'] = $this->computeLogicalKey($records[$index]);
            $logicalKeysByCommentId[(int) ($record['wp_object_id'] ?? 0)] = $records[$index]['_logical_key'];
        }

        foreach ($records as $index => $record) {
            $records[$index]['parent_comment_logical_key'] = (int) ($record['comment_parent'] ?? 0) > 0
                ? (string) ($logicalKeysByCommentId[(int) $record['comment_parent']] ?? '')
                : '';
        }

        return $records;
    }

    /**
     * @return WP_Comment[]
     */
    private function allComments(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $comments = get_comments(['status' => 'all']);

        if (! is_array($comments)) {
            return [];
        }

        return array_values(array_filter(
            $comments,
            static fn (mixed $comment): bool => $comment instanceof WP_Comment
        ));
    }

    /**
     * @return array<int, array{objectRef: array<string, string>}>
     */
    private function postReferenceMap(): array
    {
        $map = [];

        foreach (get_posts(['post_type' => 'page', 'numberposts' => -1]) as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $logicalKey = $this->pagesAdapter->computeLogicalKey([
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
            ]);
            $map[(int) $post->ID] = [
                'objectRef' => [
                    'managedSetKey' => 'wordpress_pages',
                    'contentType' => 'wordpress_page',
                    'logicalKey' => $logicalKey,
                    'postType' => 'page',
                ],
            ];
        }

        foreach (get_posts(['post_type' => 'post', 'numberposts' => -1]) as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $logicalKey = $this->postsAdapter->computeLogicalKey([
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
            ]);
            $map[(int) $post->ID] = [
                'objectRef' => [
                    'managedSetKey' => 'wordpress_posts',
                    'contentType' => 'wordpress_post',
                    'logicalKey' => $logicalKey,
                    'postType' => 'post',
                ],
            ];
        }

        return $map;
    }

    private function resolveParentPostId(ManagedContentItem $item): int
    {
        $objectRef = $item->payload['postRef']['objectRef'] ?? null;

        if (! is_array($objectRef)) {
            throw new RuntimeException(sprintf('Comment "%s" is missing its post reference.', $item->logicalKey));
        }

        $managedSetKey = (string) ($objectRef['managedSetKey'] ?? '');
        $logicalKey = (string) ($objectRef['logicalKey'] ?? '');

        $postId = match ($managedSetKey) {
            'wordpress_pages' => $this->pagesAdapter->findExistingWpObjectIdByLogicalKey($logicalKey),
            'wordpress_posts' => $this->postsAdapter->findExistingWpObjectIdByLogicalKey($logicalKey),
            default => null,
        };

        if (! is_int($postId) || $postId <= 0) {
            throw new RuntimeException(sprintf(
                'Comment "%s" references missing %s "%s".',
                $item->logicalKey,
                (string) ($objectRef['postType'] ?? 'post'),
                $logicalKey
            ));
        }

        return $postId;
    }

    private function resolveParentCommentId(ManagedContentItem $item): int
    {
        $parentLogicalKey = trim((string) ($item->payload['parentCommentLogicalKey'] ?? ''));

        if ($parentLogicalKey === '') {
            return 0;
        }

        $parentId = $this->findExistingWpObjectIdByLogicalKey($parentLogicalKey);

        if (! is_int($parentId) || $parentId <= 0) {
            throw new RuntimeException(sprintf(
                'Comment "%s" references missing parent comment "%s".',
                $item->logicalKey,
                $parentLogicalKey
            ));
        }

        return $parentId;
    }

    /**
     * @return array<int, array{key: string, value: mixed}>
     */
    private function commentMetaEntries(int $commentId): array
    {
        $allMeta = get_comment_meta($commentId);

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

        usort(
            $entries,
            static fn (array $left, array $right): int => [$left['key'], CanonicalJson::encode($left['value'])] <=> [$right['key'], CanonicalJson::encode($right['value'])]
        );

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array{key: string, value: mixed}>
     */
    private function normalizeMetaEntries(mixed $entries): array
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
    private function managedCommentMetaKeys(ManagedContentItem $item): array
    {
        $keys = [];

        foreach ($this->normalizeMetaEntries($item->metadata['commentMeta'] ?? []) as $entry) {
            $keys[] = $entry['key'];
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @param string[] $logicalKeys
     */
    private function assertUniqueLogicalKeys(array $logicalKeys): void
    {
        if (count($logicalKeys) !== count(array_unique($logicalKeys))) {
            throw new ManagedContentExportException('WordPress comments contain duplicate logical keys.');
        }
    }
}
