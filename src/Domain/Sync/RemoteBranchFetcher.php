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

        $walker = new FetchObjectGraphWalker($this->provider, $this->localRepository, $this->remoteConfig);
        $state = $walker->initialState($remoteRef->commitHash);

        while (! $walker->isComplete($state)) {
            $state = $walker->continue($state, PHP_INT_MAX);
        }

        $trackingRefName = 'refs/remotes/origin/' . $this->remoteConfig->branch;
        $this->localRepository->updateRef($trackingRefName, $remoteRef->commitHash);

        return new FetchManagedSetResult(
            $managedSetKey,
            $trackingRefName,
            $remoteRef->commitHash,
            $this->sortedHashKeys($state['visitedCommitHashes']),
            $this->sortedHashKeys($state['visitedTreeHashes']),
            $this->sortedHashKeys($state['visitedBlobHashes']),
            $this->sortedHashKeys($state['newCommitHashes']),
            $this->sortedHashKeys($state['newTreeHashes']),
            $this->sortedHashKeys($state['newBlobHashes'])
        );
    }

    /**
     * @param array<string, bool> $hashMap
     * @return list<string>
     */
    private function sortedHashKeys(array $hashMap): array
    {
        $hashes = array_keys($hashMap);
        sort($hashes);

        return $hashes;
    }
}
