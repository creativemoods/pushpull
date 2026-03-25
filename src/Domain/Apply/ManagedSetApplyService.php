<?php

declare(strict_types=1);

namespace PushPull\Domain\Apply;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;
use RuntimeException;
use WP_Post;

final class ManagedSetApplyService
{
    public function __construct(
        private readonly GenerateBlocksGlobalStylesAdapter $adapter,
        private readonly RepositoryStateReader $repositoryStateReader,
        private readonly ContentMapRepository $contentMapRepository,
        private readonly WorkingStateRepository $workingStateRepository
    ) {
    }

    public function apply(PushPullSettings $settings): ApplyManagedSetResult
    {
        $workingState = $this->workingStateRepository->get($this->adapter->getManagedSetKey(), $settings->branch);

        if ($workingState !== null && $workingState->hasConflicts()) {
            throw new RuntimeException('Cannot apply repository content while merge conflicts are pending.');
        }

        $state = $this->repositoryStateReader->read('local', 'refs/heads/' . $settings->branch);

        if ($state->commitHash === null) {
            throw new RuntimeException(sprintf('Local branch %s does not have a commit to apply.', $settings->branch));
        }

        $manifest = $this->readManifest($state->files[$this->adapter->getManifestPath()]->content ?? null);
        $items = $this->readItems($state->files);
        $this->adapter->validateManifest($manifest, array_values($items));

        $createdCount = 0;
        $updatedCount = 0;
        $appliedIds = [];
        $desiredLogicalKeys = [];

        foreach ($manifest->orderedLogicalKeys as $menuOrder => $logicalKey) {
            $item = $items[$logicalKey] ?? null;

            if ($item === null) {
                throw new RuntimeException(sprintf('Manifest references missing item %s.', $logicalKey));
            }

            $desiredLogicalKeys[$logicalKey] = true;
            $existingId = $this->resolveExistingWpObjectId($item);
            $postId = $this->upsertPost($item, $menuOrder, $existingId);

            if ($existingId === null) {
                $createdCount++;
            } else {
                $updatedCount++;
            }

            $this->updatePostMeta($postId, $item);
            $this->contentMapRepository->upsert(
                $item->managedSetKey,
                $item->contentType,
                $item->logicalKey,
                $postId,
                $this->adapter->hashItem($item)
            );
            $appliedIds[] = $postId;
        }

        $deletedLogicalKeys = $this->deleteMissingPosts($desiredLogicalKeys);

        return new ApplyManagedSetResult(
            $this->adapter->getManagedSetKey(),
            $settings->branch,
            $state->commitHash,
            $createdCount,
            $updatedCount,
            $appliedIds,
            $deletedLogicalKeys
        );
    }

    /**
     * @param array<string, \PushPull\Domain\Diff\CanonicalManagedFile> $files
     * @return array<string, ManagedContentItem>
     */
    private function readItems(array $files): array
    {
        $items = [];

        foreach ($files as $path => $file) {
            if ($path === $this->adapter->getManifestPath()) {
                continue;
            }

            $item = $this->adapter->deserialize($path, $file->content);
            $items[$item->logicalKey] = $item;
        }

        ksort($items);

        return $items;
    }

    private function readManifest(?string $content): ManagedCollectionManifest
    {
        if ($content === null) {
            throw new RuntimeException('Managed set manifest is missing from the local branch.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! is_array($decoded['orderedLogicalKeys'] ?? null)) {
            throw new RuntimeException('Managed set manifest is invalid.');
        }

        return new ManagedCollectionManifest(
            $this->adapter->getManagedSetKey(),
            (string) ($decoded['type'] ?? 'generateblocks_global_styles_manifest'),
            $decoded['orderedLogicalKeys'],
            (int) ($decoded['schemaVersion'] ?? 1)
        );
    }

    private function resolveExistingWpObjectId(ManagedContentItem $item): ?int
    {
        $mapped = $this->contentMapRepository->findByLogicalKey($item->managedSetKey, $item->contentType, $item->logicalKey);

        if ($mapped?->wpObjectId !== null && $this->postExists($mapped->wpObjectId)) {
            return $mapped->wpObjectId;
        }

        foreach (
            get_posts([
            'post_type' => 'gblocks_styles',
            'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            ]) as $post
        ) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $candidateLogicalKey = $this->adapter->computeLogicalKey([
                'gb_style_selector' => (string) get_post_meta($post->ID, 'gb_style_selector', true),
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
            ]);

            if ($candidateLogicalKey === $item->logicalKey) {
                return (int) $post->ID;
            }
        }

        return null;
    }

    private function postExists(int $postId): bool
    {
        foreach (
            get_posts([
            'post_type' => 'gblocks_styles',
            'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            ]) as $post
        ) {
            if ($post instanceof WP_Post && $post->ID === $postId) {
                return true;
            }
        }

        return false;
    }

    private function upsertPost(ManagedContentItem $item, int $menuOrder, ?int $existingId): int
    {
        $postData = [
            'post_type' => 'gblocks_styles',
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

    private function updatePostMeta(int $postId, ManagedContentItem $item): void
    {
        update_post_meta($postId, 'gb_style_selector', $item->selector);
        update_post_meta($postId, 'gb_style_data', $item->payload);

        if (isset($item->derived['generatedCss']) && is_string($item->derived['generatedCss']) && $item->derived['generatedCss'] !== '') {
            update_post_meta($postId, 'gb_style_css', $item->derived['generatedCss']);
        } else {
            delete_post_meta($postId, 'gb_style_css');
        }
    }

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    private function deleteMissingPosts(array $desiredLogicalKeys): array
    {
        $deletedLogicalKeys = [];

        foreach (
            get_posts([
            'post_type' => 'gblocks_styles',
            'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            ]) as $post
        ) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $logicalKey = $this->adapter->computeLogicalKey([
                'gb_style_selector' => (string) get_post_meta($post->ID, 'gb_style_selector', true),
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
            ]);

            if (isset($desiredLogicalKeys[$logicalKey])) {
                continue;
            }

            wp_delete_post($post->ID, true);
            $this->contentMapRepository->markDeleted($this->adapter->getManagedSetKey(), $this->adapter->getContentType(), $logicalKey);
            $deletedLogicalKeys[] = $logicalKey;
        }

        sort($deletedLogicalKeys);

        return $deletedLogicalKeys;
    }
}
