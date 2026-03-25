<?php

declare(strict_types=1);

namespace PushPull\Domain\Push;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\UpdateRemoteRefRequest;
use PushPull\Settings\PushPullSettings;
use RuntimeException;

final class ManagedSetPushService
{
    /** @var array<string, string> */
    private array $blobMap = [];
    /** @var array<string, string> */
    private array $treeMap = [];
    /** @var array<string, string> */
    private array $commitMap = [];
    /** @var string[] */
    private array $pushedBlobs = [];
    /** @var string[] */
    private array $pushedTrees = [];
    /** @var string[] */
    private array $pushedCommits = [];

    public function __construct(
        private readonly LocalRepositoryInterface $localRepository,
        private readonly GitProviderFactoryInterface $providerFactory
    ) {
    }

    public function push(string $managedSetKey, PushPullSettings $settings): PushManagedSetResult
    {
        $this->blobMap = [];
        $this->treeMap = [];
        $this->commitMap = [];
        $this->pushedBlobs = [];
        $this->pushedTrees = [];
        $this->pushedCommits = [];

        $localRef = $this->localRepository->getRef('refs/heads/' . $settings->branch);
        $trackingRef = $this->localRepository->getRef('refs/remotes/origin/' . $settings->branch);

        if ($localRef === null || $localRef->commitHash === '') {
            throw new RuntimeException(sprintf('Local branch %s does not have a commit to push.', $settings->branch));
        }

        if ($trackingRef === null || $trackingRef->commitHash === '') {
            throw new RuntimeException(sprintf('Remote tracking branch %s has not been fetched yet.', $settings->branch));
        }

        $relationship = $this->determineRelationship($localRef->commitHash, $trackingRef->commitHash);

        if ($relationship === 'in_sync') {
            return new PushManagedSetResult(
                $managedSetKey,
                $settings->branch,
                'already_up_to_date',
                $localRef->commitHash,
                $trackingRef->commitHash,
                [],
                [],
                []
            );
        }

        if ($relationship !== 'ahead') {
            throw new RuntimeException(sprintf(
                'Local branch %s cannot be pushed because it is %s relative to the fetched remote state.',
                $settings->branch,
                str_replace('_', ' ', $relationship)
            ));
        }

        $remoteConfig = GitRemoteConfig::fromSettings($settings);
        $provider = $this->providerFactory->make($remoteConfig->providerKey);
        $remoteRefName = 'refs/heads/' . $settings->branch;
        $currentRemoteRef = $provider->getRef($remoteConfig, $remoteRefName);

        if ($currentRemoteRef === null || $currentRemoteRef->commitHash === '') {
            throw new RuntimeException(sprintf('Remote branch %s does not exist or cannot be updated safely.', $settings->branch));
        }

        if ($currentRemoteRef->commitHash !== $trackingRef->commitHash) {
            throw new RuntimeException(sprintf(
                'Remote branch %s has changed since the last fetch. Fetch again before pushing.',
                $settings->branch
            ));
        }

        $remoteHeadHash = $this->pushCommit($localRef->commitHash, $trackingRef->commitHash, $provider, $remoteConfig);
        $update = $provider->updateRef($remoteConfig, new UpdateRemoteRefRequest(
            $remoteRefName,
            $remoteHeadHash,
            $trackingRef->commitHash
        ));

        if (! $update->success) {
            throw new RuntimeException(sprintf('Remote branch %s could not be updated.', $settings->branch));
        }

        $this->localRepository->updateRef('refs/heads/' . $settings->branch, $remoteHeadHash);
        $this->localRepository->updateRef('refs/remotes/origin/' . $settings->branch, $remoteHeadHash);
        $this->localRepository->updateRef('HEAD', $remoteHeadHash);

        return new PushManagedSetResult(
            $managedSetKey,
            $settings->branch,
            'pushed',
            $localRef->commitHash,
            $remoteHeadHash,
            array_values(array_unique($this->pushedCommits)),
            array_values(array_unique($this->pushedTrees)),
            array_values(array_unique($this->pushedBlobs))
        );
    }

    private function pushCommit(
        string $localCommitHash,
        string $stopAtRemoteHash,
        GitProviderInterface $provider,
        GitRemoteConfig $remoteConfig
    ): string {
        if ($localCommitHash === $stopAtRemoteHash) {
            return $stopAtRemoteHash;
        }

        if (isset($this->commitMap[$localCommitHash])) {
            return $this->commitMap[$localCommitHash];
        }

        $localCommit = $this->localRepository->getCommit($localCommitHash);

        if ($localCommit === null) {
            throw new RuntimeException(sprintf('Local commit %s could not be found for push.', $localCommitHash));
        }

        $remoteParentHashes = [];

        foreach ([$localCommit->parentHash, $localCommit->secondParentHash] as $parentHash) {
            if ($parentHash === null || $parentHash === '') {
                continue;
            }

            $remoteParentHashes[] = $this->pushCommit($parentHash, $stopAtRemoteHash, $provider, $remoteConfig);
        }

        $remoteTreeHash = $this->pushTree($localCommit->treeHash, $provider, $remoteConfig);
        $remoteCommitHash = $provider->createCommit($remoteConfig, new CreateRemoteCommitRequest(
            $remoteTreeHash,
            $remoteParentHashes,
            $localCommit->message,
            $localCommit->authorName !== '' ? $localCommit->authorName : 'PushPull',
            $localCommit->authorEmail
        ));

        $this->commitMap[$localCommitHash] = $remoteCommitHash;
        $this->pushedCommits[] = $remoteCommitHash;
        $this->localRepository->importRemoteCommit(new RemoteCommit(
            $remoteCommitHash,
            $remoteTreeHash,
            $remoteParentHashes,
            $localCommit->message
        ));

        return $remoteCommitHash;
    }

    private function pushTree(string $localTreeHash, GitProviderInterface $provider, GitRemoteConfig $remoteConfig): string
    {
        if (isset($this->treeMap[$localTreeHash])) {
            return $this->treeMap[$localTreeHash];
        }

        $tree = $this->localRepository->getTree($localTreeHash);

        if ($tree === null) {
            throw new RuntimeException(sprintf('Local tree %s could not be found for push.', $localTreeHash));
        }

        $entries = [];

        foreach ($tree->entries as $entry) {
            $remoteHash = $entry->type === 'blob'
                ? $this->pushBlob($entry->hash, $provider, $remoteConfig)
                : $this->pushTree($entry->hash, $provider, $remoteConfig);

            $entries[] = [
                'path' => $entry->path,
                'type' => $entry->type,
                'hash' => $remoteHash,
            ];
        }

        $remoteTreeHash = $provider->createTree($remoteConfig, $entries);
        $this->treeMap[$localTreeHash] = $remoteTreeHash;
        $this->pushedTrees[] = $remoteTreeHash;
        $this->localRepository->importRemoteTree(new RemoteTree($remoteTreeHash, $entries));

        return $remoteTreeHash;
    }

    private function pushBlob(string $localBlobHash, GitProviderInterface $provider, GitRemoteConfig $remoteConfig): string
    {
        if (isset($this->blobMap[$localBlobHash])) {
            return $this->blobMap[$localBlobHash];
        }

        $blob = $this->localRepository->getBlob($localBlobHash);

        if ($blob === null) {
            throw new RuntimeException(sprintf('Local blob %s could not be found for push.', $localBlobHash));
        }

        $remoteBlobHash = $provider->createBlob($remoteConfig, $blob->content);
        $this->blobMap[$localBlobHash] = $remoteBlobHash;
        $this->pushedBlobs[] = $remoteBlobHash;
        $this->localRepository->importRemoteBlob(new RemoteBlob($remoteBlobHash, $blob->content));

        return $remoteBlobHash;
    }

    private function determineRelationship(string $localCommitHash, string $remoteCommitHash): string
    {
        if ($localCommitHash === $remoteCommitHash) {
            return 'in_sync';
        }

        $localAncestors = $this->ancestorSet($localCommitHash);
        $remoteAncestors = $this->ancestorSet($remoteCommitHash);

        if (isset($localAncestors[$remoteCommitHash])) {
            return 'ahead';
        }

        if (isset($remoteAncestors[$localCommitHash])) {
            return 'behind';
        }

        foreach ($localAncestors as $hash => $_) {
            if (isset($remoteAncestors[$hash])) {
                return 'diverged';
            }
        }

        return 'unrelated';
    }

    /**
     * @return array<string, true>
     */
    private function ancestorSet(string $startingHash): array
    {
        $seen = [];
        $queue = [$startingHash];

        while ($queue !== []) {
            $hash = array_shift($queue);

            if (! is_string($hash) || $hash === '' || isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $commit = $this->localRepository->getCommit($hash);

            if ($commit === null) {
                continue;
            }

            foreach ([$commit->parentHash, $commit->secondParentHash] as $parentHash) {
                if (is_string($parentHash) && $parentHash !== '') {
                    $queue[] = $parentHash;
                }
            }
        }

        return $seen;
    }
}
