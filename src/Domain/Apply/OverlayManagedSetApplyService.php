<?php

declare(strict_types=1);

namespace PushPull\Domain\Apply;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\ManagedContentItem;
use PushPull\Content\OverlayManagedContentAdapterInterface;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;
use RuntimeException;

final class OverlayManagedSetApplyService implements ManagedSetApplyServiceInterface
{
    public function __construct(
        private readonly OverlayManagedContentAdapterInterface $adapter,
        private readonly RepositoryStateReader $repositoryStateReader,
        private readonly WorkingStateRepository $workingStateRepository
    ) {
    }

    public function apply(PushPullSettings $settings): ApplyManagedSetResult
    {
        ['commitHash' => $commitHash, 'orderedLogicalKeys' => $orderedLogicalKeys, 'items' => $items] = $this->loadSnapshot($settings);
        $createdCount = 0;
        $updatedCount = 0;
        $desiredLogicalKeys = [];

        foreach ($orderedLogicalKeys as $menuOrder => $logicalKey) {
            $item = $items[$logicalKey] ?? null;

            if (! $item instanceof ManagedContentItem) {
                throw new RuntimeException(sprintf('Manifest references missing item %s.', $logicalKey));
            }

            $desiredLogicalKeys[$logicalKey] = true;
            $created = $this->adapter->applyOverlayItem($item);

            if ($created) {
                $createdCount++;
            } else {
                $updatedCount++;
            }
        }

        $deletedLogicalKeys = $this->adapter->deleteMissingOverlayItems($desiredLogicalKeys);

        return new ApplyManagedSetResult(
            $this->adapter->getManagedSetKey(),
            $settings->branch,
            $commitHash,
            $createdCount,
            $updatedCount,
            [],
            $deletedLogicalKeys
        );
    }

    public function prepareApply(PushPullSettings $settings): array
    {
        ['commitHash' => $commitHash, 'orderedLogicalKeys' => $orderedLogicalKeys] = $this->loadSnapshot($settings);

        return [
            'commitHash' => $commitHash,
            'orderedLogicalKeys' => $orderedLogicalKeys,
        ];
    }

    public function applyLogicalKey(PushPullSettings $settings, string $logicalKey, int $menuOrder): array
    {
        ['items' => $items] = $this->loadSnapshot($settings);
        $item = $items[$logicalKey] ?? null;

        if (! $item instanceof ManagedContentItem) {
            throw new RuntimeException(sprintf('Manifest references missing item %s.', $logicalKey));
        }

        return [
            'created' => $this->adapter->applyOverlayItem($item),
            'postId' => null,
        ];
    }

    public function deleteMissingLogicalKeys(array $desiredLogicalKeys): array
    {
        return $this->adapter->deleteMissingOverlayItems($desiredLogicalKeys);
    }

    /**
     * @return array{commitHash: string, orderedLogicalKeys: array<int, string>, items: array<string, ManagedContentItem>}
     */
    private function loadSnapshot(PushPullSettings $settings): array
    {
        $workingState = $this->workingStateRepository->get($this->adapter->getManagedSetKey(), $settings->branch);

        if ($workingState !== null && $workingState->hasConflicts()) {
            throw new RuntimeException('Cannot apply repository content while merge conflicts are pending.');
        }

        $state = $this->repositoryStateReader->read('local', 'refs/heads/' . $settings->branch);

        if ($state->commitHash === null) {
            throw new RuntimeException(sprintf('Local branch %s does not have a commit to apply.', $settings->branch));
        }

        $files = [];

        foreach ($state->files as $path => $file) {
            if ($this->adapter->ownsRepositoryPath($path)) {
                $files[$path] = $file->content;
            }
        }

        try {
            $snapshot = $this->adapter->readSnapshotFromRepositoryFiles($files);
        } catch (ManagedContentExportException $exception) {
            if ($exception->getMessage() !== 'Managed set manifest is missing from the local branch.') {
                throw $exception;
            }

            return [
                'commitHash' => $state->commitHash,
                'orderedLogicalKeys' => [],
                'items' => [],
            ];
        }

        $items = [];

        foreach ($snapshot->items as $item) {
            $items[$item->logicalKey] = $item;
        }

        return [
            'commitHash' => $state->commitHash,
            'orderedLogicalKeys' => $snapshot->orderedLogicalKeys,
            'items' => $items,
        ];
    }
}
