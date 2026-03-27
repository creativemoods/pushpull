<?php

declare(strict_types=1);

namespace PushPull\Domain\Apply;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\ManagedContentItem;
use PushPull\Content\WordPressManagedContentAdapterInterface;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;
use RuntimeException;

final class ManagedSetApplyService
{
    public function __construct(
        private readonly WordPressManagedContentAdapterInterface $adapter,
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

        $snapshot = $this->adapter->readSnapshotFromRepositoryFiles($this->managedSetFiles($state->files));
        $items = [];

        foreach ($snapshot->items as $item) {
            $items[$item->logicalKey] = $item;
        }

        $createdCount = 0;
        $updatedCount = 0;
        $appliedIds = [];
        $desiredLogicalKeys = [];

        foreach ($snapshot->orderedLogicalKeys as $menuOrder => $logicalKey) {
            $item = $items[$logicalKey] ?? null;

            if ($item === null) {
                throw new RuntimeException(sprintf('Manifest references missing item %s.', $logicalKey));
            }

            $desiredLogicalKeys[$logicalKey] = true;
            $existingId = $this->resolveExistingWpObjectId($item);
            $postId = $this->adapter->upsertItem($item, $menuOrder, $existingId);

            if ($existingId === null) {
                $createdCount++;
            } else {
                $updatedCount++;
            }

            $this->adapter->persistItemMeta($postId, $item, $snapshot->files);
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
     * @return array<string, string>
     */
    private function managedSetFiles(array $files): array
    {
        $managedSetFiles = [];

        foreach ($files as $path => $file) {
            if (! $this->adapter->ownsRepositoryPath($path)) {
                continue;
            }

            $managedSetFiles[$path] = $file->content;
        }

        ksort($managedSetFiles);

        return $managedSetFiles;
    }

    private function resolveExistingWpObjectId(ManagedContentItem $item): ?int
    {
        $mapped = $this->contentMapRepository->findByLogicalKey($item->managedSetKey, $item->contentType, $item->logicalKey);

        if ($mapped?->wpObjectId !== null && $this->adapter->postExists($mapped->wpObjectId)) {
            return $mapped->wpObjectId;
        }

        return $this->adapter->findExistingWpObjectIdByLogicalKey($item->logicalKey);
    }

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    private function deleteMissingPosts(array $desiredLogicalKeys): array
    {
        $deletedLogicalKeys = $this->adapter->deleteMissingItems($desiredLogicalKeys);

        foreach ($deletedLogicalKeys as $logicalKey) {
            $this->contentMapRepository->markDeleted($this->adapter->getManagedSetKey(), $this->adapter->getContentType(), $logicalKey);
        }

        return $deletedLogicalKeys;
    }
}
