<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use RuntimeException;

final class RemoteBranchFetcher
{
    /** @var array<string, bool> */
    private array $visitedCommits = [];
    /** @var array<string, bool> */
    private array $visitedTrees = [];
    /** @var array<string, bool> */
    private array $visitedBlobs = [];
    /** @var array<string, bool> */
    private array $newCommits = [];
    /** @var array<string, bool> */
    private array $newTrees = [];
    /** @var array<string, bool> */
    private array $newBlobs = [];

    public function __construct(
        private readonly GitProviderInterface $provider,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly GitRemoteConfig $remoteConfig
    ) {
    }

    public function fetchManagedSet(string $managedSetKey): FetchManagedSetResult
    {
        $branchRefName = 'refs/heads/' . $this->remoteConfig->branch;
        $remoteRef = $this->provider->getRef($this->remoteConfig, $branchRefName);

        if ($remoteRef === null) {
            throw new RuntimeException(sprintf('Remote branch "%s" was not found.', $this->remoteConfig->branch));
        }

        $this->importCommitGraph($remoteRef->commitHash);
        $trackingRefName = 'refs/remotes/origin/' . $this->remoteConfig->branch;
        $this->localRepository->updateRef($trackingRefName, $remoteRef->commitHash);

        return new FetchManagedSetResult(
            $managedSetKey,
            $trackingRefName,
            $remoteRef->commitHash,
            array_keys($this->visitedCommits),
            array_keys($this->visitedTrees),
            array_keys($this->visitedBlobs),
            array_keys($this->newCommits),
            array_keys($this->newTrees),
            array_keys($this->newBlobs)
        );
    }

    private function importCommitGraph(string $commitHash): void
    {
        if (isset($this->visitedCommits[$commitHash])) {
            return;
        }

        $remoteCommit = $this->provider->getCommit($this->remoteConfig, $commitHash);

        if ($remoteCommit === null) {
            throw new RuntimeException(sprintf('Remote commit "%s" could not be loaded.', $commitHash));
        }

        foreach ($remoteCommit->parents as $parentHash) {
            $this->importCommitGraph($parentHash);
        }

        $this->importTree($remoteCommit->treeHash);
        if ($this->localRepository->getCommit($commitHash) === null) {
            $this->newCommits[$commitHash] = true;
        }
        $this->localRepository->importRemoteCommit($remoteCommit);
        $this->visitedCommits[$commitHash] = true;
    }

    private function importTree(string $treeHash): void
    {
        if (isset($this->visitedTrees[$treeHash])) {
            return;
        }

        $remoteTree = $this->provider->getTree($this->remoteConfig, $treeHash);

        if ($remoteTree === null) {
            throw new RuntimeException(sprintf('Remote tree "%s" could not be loaded.', $treeHash));
        }

        foreach ($remoteTree->entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryType = (string) ($entry['type'] ?? 'blob');
            $entryHash = (string) ($entry['hash'] ?? '');

            if ($entryHash === '') {
                continue;
            }

            if ($entryType === 'tree') {
                $this->importTree($entryHash);
                continue;
            }

            $this->importBlob($entryHash);
        }

        if ($this->localRepository->getTree($treeHash) === null) {
            $this->newTrees[$treeHash] = true;
        }
        $this->localRepository->importRemoteTree($remoteTree);
        $this->visitedTrees[$treeHash] = true;
    }

    private function importBlob(string $blobHash): void
    {
        if (isset($this->visitedBlobs[$blobHash])) {
            return;
        }

        $remoteBlob = $this->provider->getBlob($this->remoteConfig, $blobHash);

        if ($remoteBlob === null) {
            throw new RuntimeException(sprintf('Remote blob "%s" could not be loaded.', $blobHash));
        }

        if ($this->localRepository->getBlob($blobHash) === null) {
            $this->newBlobs[$blobHash] = true;
        }
        $this->localRepository->importRemoteBlob($remoteBlob);
        $this->visitedBlobs[$blobHash] = true;
    }
}
