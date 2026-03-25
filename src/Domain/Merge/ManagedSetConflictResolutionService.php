<?php

declare(strict_types=1);

namespace PushPull\Domain\Merge;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Repository\Commit;
use PushPull\Domain\Repository\CommitRequest;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Domain\Repository\TreeEntry;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use RuntimeException;

final class ManagedSetConflictResolutionService
{
    public function __construct(
        private readonly LocalRepositoryInterface $localRepository,
        private readonly WorkingStateRepository $workingStateRepository
    ) {
    }

    public function resolveUsingOurs(string $managedSetKey, string $branch, string $path): ResolveConflictResult
    {
        $state = $this->requireConflictState($managedSetKey, $branch);
        $conflict = $this->findConflict($state, $path);

        return $this->persistResolution($state, $conflict, $conflict->oursContent);
    }

    public function resolveUsingTheirs(string $managedSetKey, string $branch, string $path): ResolveConflictResult
    {
        $state = $this->requireConflictState($managedSetKey, $branch);
        $conflict = $this->findConflict($state, $path);

        return $this->persistResolution($state, $conflict, $conflict->theirsContent);
    }

    public function resolveUsingManual(string $managedSetKey, string $branch, string $path, string $manualContent): ResolveConflictResult
    {
        $state = $this->requireConflictState($managedSetKey, $branch);
        $conflict = $this->findConflict($state, $path);
        $decoded = json_decode($manualContent, true);

        if ($decoded === null && trim($manualContent) !== 'null') {
            throw new RuntimeException(sprintf('Manual resolution for %s must be valid JSON.', $path));
        }

        return $this->persistResolution($state, $conflict, rtrim($manualContent) . "\n");
    }

    public function finalize(string $managedSetKey, string $branch): FinalizeMergeResult
    {
        $state = $this->workingStateRepository->get($managedSetKey, $branch);

        if ($state === null || $state->mergeTargetHash === null || $state->headCommitHash === null) {
            throw new RuntimeException(sprintf('There is no merge in progress for branch %s.', $branch));
        }

        if ($state->hasConflicts()) {
            throw new RuntimeException(sprintf('Branch %s still has unresolved conflicts.', $branch));
        }

        $commit = $this->commitMergedFiles(
            $branch,
            $state->headCommitHash,
            $state->mergeTargetHash,
            $state->workingTree
        );

        $this->workingStateRepository->clearMergeState($managedSetKey, $branch, $commit->hash);

        return new FinalizeMergeResult($managedSetKey, $branch, $commit);
    }

    private function persistResolution(MergeConflictState $state, MergeConflict $conflict, ?string $resolvedContent): ResolveConflictResult
    {
        $workingTree = $state->workingTree;

        if ($resolvedContent === null) {
            unset($workingTree[$conflict->path]);
        } else {
            $workingTree[$conflict->path] = $resolvedContent;
        }

        $remainingConflicts = array_values(array_filter(
            $state->conflicts,
            static fn (MergeConflict $candidate): bool => $candidate->path !== $conflict->path
        ));

        $this->workingStateRepository->saveConflictState(new MergeConflictState(
            $state->managedSetKey,
            $state->branch,
            $state->headCommitHash,
            $state->mergeBaseHash,
            $state->mergeTargetHash,
            $workingTree,
            $remainingConflicts
        ));

        return new ResolveConflictResult(
            $state->managedSetKey,
            $state->branch,
            $conflict->path,
            count($remainingConflicts)
        );
    }

    private function requireConflictState(string $managedSetKey, string $branch): MergeConflictState
    {
        $state = $this->workingStateRepository->get($managedSetKey, $branch);

        if ($state === null || ! $state->hasConflicts()) {
            throw new RuntimeException(sprintf('There are no unresolved conflicts for branch %s.', $branch));
        }

        return new MergeConflictState(
            $state->managedSetKey,
            $state->branchName,
            $state->headCommitHash,
            $state->mergeBaseHash,
            $state->mergeTargetHash,
            $state->workingTree,
            $state->conflicts
        );
    }

    private function findConflict(MergeConflictState $state, string $path): MergeConflict
    {
        foreach ($state->conflicts as $conflict) {
            if ($conflict->path === $path) {
                return $conflict;
            }
        }

        throw new RuntimeException(sprintf('Conflict for path %s was not found.', $path));
    }

    /**
     * @param array<string, string> $files
     */
    private function commitMergedFiles(string $branch, string $oursCommitHash, string $theirsCommitHash, array $files): Commit
    {
        $entries = [];

        foreach ($files as $path => $content) {
            $blob = $this->localRepository->storeBlob($content);
            $entries[] = new TreeEntry($path, 'blob', $blob->hash);
        }

        $tree = $this->localRepository->storeTree($entries);
        $commit = $this->localRepository->commit(new CommitRequest(
            $tree->hash,
            $oursCommitHash,
            $theirsCommitHash,
            'PushPull',
            '',
            sprintf('Merge refs/remotes/origin/%s into %s', $branch, $branch),
            ['operation' => 'merge']
        ));

        $this->localRepository->updateRef('refs/heads/' . $branch, $commit->hash);
        $this->localRepository->updateRef('HEAD', $commit->hash);

        return $commit;
    }
}
