<?php

declare(strict_types=1);

namespace PushPull\Domain\Merge;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Diff\CanonicalManagedState;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\Commit;
use PushPull\Domain\Repository\CommitRequest;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Domain\Repository\TreeEntry;
use PushPull\Persistence\WorkingState\WorkingStateRepository;

final class ManagedSetMergeService
{
    public function __construct(
        private readonly LocalRepositoryInterface $localRepository,
        private readonly RepositoryStateReader $stateReader,
        private readonly JsonThreeWayMerger $jsonMerger,
        private readonly WorkingStateRepository $workingStateRepository
    ) {
    }

    public function merge(string $managedSetKey, string $branch): MergeManagedSetResult
    {
        $oursRef = $this->localRepository->getRef('refs/heads/' . $branch);
        $theirsRef = $this->localRepository->getRef('refs/remotes/origin/' . $branch);

        if ($theirsRef === null || $theirsRef->commitHash === '') {
            throw new \RuntimeException(sprintf('Remote tracking branch %s has not been fetched yet.', $branch));
        }

        if ($oursRef === null || $oursRef->commitHash === '') {
            $this->localRepository->updateRef('refs/heads/' . $branch, $theirsRef->commitHash);
            $this->localRepository->updateRef('HEAD', $theirsRef->commitHash);
            $this->workingStateRepository->clearMergeState($managedSetKey, $branch, $theirsRef->commitHash);

            return new MergeManagedSetResult(
                $managedSetKey,
                $branch,
                null,
                null,
                $theirsRef->commitHash,
                'fast_forward',
                $this->localRepository->getCommit($theirsRef->commitHash),
                [],
                []
            );
        }

        if ($oursRef->commitHash === $theirsRef->commitHash) {
            $this->workingStateRepository->clearMergeState($managedSetKey, $branch, $oursRef->commitHash);

            return new MergeManagedSetResult(
                $managedSetKey,
                $branch,
                $oursRef->commitHash,
                $oursRef->commitHash,
                $theirsRef->commitHash,
                'already_up_to_date',
                $this->localRepository->getCommit($oursRef->commitHash),
                [],
                []
            );
        }

        $baseCommitHash = $this->findMergeBase($oursRef->commitHash, $theirsRef->commitHash);

        if ($baseCommitHash === $oursRef->commitHash) {
            $this->localRepository->updateRef('refs/heads/' . $branch, $theirsRef->commitHash);
            $this->localRepository->updateRef('HEAD', $theirsRef->commitHash);
            $this->workingStateRepository->clearMergeState($managedSetKey, $branch, $theirsRef->commitHash);

            return new MergeManagedSetResult(
                $managedSetKey,
                $branch,
                $baseCommitHash,
                $oursRef->commitHash,
                $theirsRef->commitHash,
                'fast_forward',
                $this->localRepository->getCommit($theirsRef->commitHash),
                [],
                []
            );
        }

        $baseState = $baseCommitHash !== null
            ? $this->stateReader->readCommit('base', $baseCommitHash)
            : new CanonicalManagedState('base', null, null, null, []);
        $oursState = $this->stateReader->readCommit('ours', $oursRef->commitHash);
        $theirsState = $this->stateReader->readCommit('theirs', $theirsRef->commitHash);
        $merge = $this->mergeStates($baseState, $oursState, $theirsState);

        if ($merge['conflicts'] !== []) {
            $this->workingStateRepository->saveConflictState(new MergeConflictState(
                $managedSetKey,
                $branch,
                $oursRef->commitHash,
                $baseCommitHash,
                $theirsRef->commitHash,
                $merge['files'],
                $merge['conflicts']
            ));

            return new MergeManagedSetResult(
                $managedSetKey,
                $branch,
                $baseCommitHash,
                $oursRef->commitHash,
                $theirsRef->commitHash,
                'conflict',
                null,
                $merge['files'],
                $merge['conflicts']
            );
        }

        $mergedCommit = $this->commitMergedFiles($branch, $oursRef->commitHash, $theirsRef->commitHash, $merge['files']);
        $this->workingStateRepository->clearMergeState($managedSetKey, $branch, $mergedCommit->hash);

        return new MergeManagedSetResult(
            $managedSetKey,
            $branch,
            $baseCommitHash,
            $oursRef->commitHash,
            $theirsRef->commitHash,
            'merged',
            $mergedCommit,
            $merge['files'],
            []
        );
    }

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

    /**
     * @return array{files: array<string, string>, conflicts: MergeConflict[]}
     */
    private function mergeStates(CanonicalManagedState $base, CanonicalManagedState $ours, CanonicalManagedState $theirs): array
    {
        $paths = array_unique(array_merge(array_keys($base->files), array_keys($ours->files), array_keys($theirs->files)));
        sort($paths);
        $files = [];
        $conflicts = [];

        foreach ($paths as $path) {
            $baseContent = $base->files[$path]->content ?? null;
            $oursContent = $ours->files[$path]->content ?? null;
            $theirsContent = $theirs->files[$path]->content ?? null;

            if ($oursContent === $theirsContent) {
                if ($oursContent !== null) {
                    $files[$path] = $oursContent;
                }

                continue;
            }

            $merged = $this->jsonMerger->merge($baseContent, $oursContent, $theirsContent);

            if ($merged['conflictPaths'] !== []) {
                $conflicts[] = new MergeConflict($path, $baseContent, $oursContent, $theirsContent, $merged['conflictPaths']);
                continue;
            }

            if ($merged['content'] !== null) {
                $files[$path] = $merged['content'];
            }
        }

        ksort($files);

        return ['files' => $files, 'conflicts' => $conflicts];
    }

    private function findMergeBase(string $oursCommitHash, string $theirsCommitHash): ?string
    {
        $oursDepths = $this->commitDepths($oursCommitHash);
        $theirsDepths = $this->commitDepths($theirsCommitHash);
        $bestHash = null;
        $bestScore = null;

        foreach ($oursDepths as $hash => $oursDepth) {
            if (! isset($theirsDepths[$hash])) {
                continue;
            }

            $score = $oursDepth + $theirsDepths[$hash];

            if ($bestScore === null || $score < $bestScore) {
                $bestHash = $hash;
                $bestScore = $score;
            }
        }

        return $bestHash;
    }

    /**
     * @return array<string, int>
     */
    private function commitDepths(string $startingHash): array
    {
        $depths = [];
        $queue = [[$startingHash, 0]];

        while ($queue !== []) {
            [$hash, $depth] = array_shift($queue);

            if (! is_string($hash) || $hash === '' || isset($depths[$hash])) {
                continue;
            }

            $depths[$hash] = $depth;
            $commit = $this->localRepository->getCommit($hash);

            if ($commit === null) {
                continue;
            }

            foreach ([$commit->parentHash, $commit->secondParentHash] as $parentHash) {
                if (is_string($parentHash) && $parentHash !== '') {
                    $queue[] = [$parentHash, $depth + 1];
                }
            }
        }

        return $depths;
    }
}
