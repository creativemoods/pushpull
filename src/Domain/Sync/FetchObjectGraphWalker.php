<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use RuntimeException;

final class FetchObjectGraphWalker
{
    public function __construct(
        private readonly GitProviderInterface $provider,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly GitRemoteConfig $remoteConfig
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function initialState(string $remoteCommitHash): array
    {
        return [
            'pendingCommitHashes' => [$remoteCommitHash => true],
            'pendingTreeHashes' => [],
            'pendingBlobHashes' => [],
            'visitedCommitHashes' => [],
            'visitedTreeHashes' => [],
            'visitedBlobHashes' => [],
            'newCommitHashes' => [],
            'newTreeHashes' => [],
            'newBlobHashes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function continue(array $state, int $budget): array
    {
        while ($budget > 0) {
            $commitHash = $this->popPendingHash($state['pendingCommitHashes']);

            if ($commitHash !== null) {
                if (isset($state['visitedCommitHashes'][$commitHash])) {
                    $budget--;
                    continue;
                }

                if ($this->localRepository->getCommit($commitHash) !== null) {
                    $budget--;
                    continue;
                }

                $remoteCommit = $this->provider->getCommit($this->remoteConfig, $commitHash);

                if ($remoteCommit === null) {
                    throw new RuntimeException(sprintf('Remote commit "%s" could not be loaded.', $commitHash));
                }

                foreach ($remoteCommit->parents as $parentHash) {
                    if (! isset($state['visitedCommitHashes'][$parentHash])) {
                        $state['pendingCommitHashes'][$parentHash] = true;
                    }
                }

                $state['pendingTreeHashes'][$remoteCommit->treeHash] = true;
                $state['newCommitHashes'][$commitHash] = true;
                $this->localRepository->importRemoteCommit($remoteCommit);
                $state['visitedCommitHashes'][$commitHash] = true;
                $budget--;
                continue;
            }

            $treeHash = $this->popPendingHash($state['pendingTreeHashes']);

            if ($treeHash !== null) {
                if (isset($state['visitedTreeHashes'][$treeHash])) {
                    $budget--;
                    continue;
                }

                if ($this->localRepository->getTree($treeHash) !== null) {
                    $budget--;
                    continue;
                }

                $remoteTree = $this->provider->getTree($this->remoteConfig, $treeHash);

                if ($remoteTree === null) {
                    throw new RuntimeException(sprintf('Remote tree "%s" could not be loaded.', $treeHash));
                }

                foreach ($remoteTree->entries as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $entryHash = (string) ($entry['hash'] ?? '');
                    $entryType = (string) ($entry['type'] ?? 'blob');

                    if ($entryHash === '') {
                        continue;
                    }

                    if ($entryType === 'tree') {
                        $state['pendingTreeHashes'][$entryHash] = true;
                        continue;
                    }

                    $state['pendingBlobHashes'][$entryHash] = true;
                }

                $state['newTreeHashes'][$treeHash] = true;
                $this->localRepository->importRemoteTree($remoteTree);
                $state['visitedTreeHashes'][$treeHash] = true;
                $budget--;
                continue;
            }

            $blobHash = $this->popPendingHash($state['pendingBlobHashes']);

            if ($blobHash !== null) {
                if (isset($state['visitedBlobHashes'][$blobHash])) {
                    $budget--;
                    continue;
                }

                if ($this->localRepository->getBlob($blobHash) !== null) {
                    $budget--;
                    continue;
                }

                $remoteBlob = $this->provider->getBlob($this->remoteConfig, $blobHash);

                if ($remoteBlob === null) {
                    throw new RuntimeException(sprintf('Remote blob "%s" could not be loaded.', $blobHash));
                }

                $state['newBlobHashes'][$blobHash] = true;
                $this->localRepository->importRemoteBlob($remoteBlob);
                $state['visitedBlobHashes'][$blobHash] = true;
                $budget--;
                continue;
            }

            break;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function isComplete(array $state): bool
    {
        return $state['pendingCommitHashes'] === []
            && $state['pendingTreeHashes'] === []
            && $state['pendingBlobHashes'] === [];
    }

    /**
     * @param array<string, bool> $pending
     */
    private function popPendingHash(array &$pending): ?string
    {
        $next = array_key_first($pending);

        if (! is_string($next) || $next === '') {
            return null;
        }

        unset($pending[$next]);

        return $next;
    }
}
