<?php

declare(strict_types=1);

namespace PushPull\Domain\Diff;

use PushPull\Content\ManagedContentSnapshot;
use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Domain\Repository\Commit;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Settings\PushPullSettings;

final class ManagedSetDiffService
{
    public function __construct(
        private readonly ManifestManagedContentAdapterInterface $adapter,
        private readonly RepositoryStateReader $repositoryStateReader,
        private readonly LocalRepositoryInterface $localRepository
    ) {
    }

    public function diff(PushPullSettings $settings): ManagedSetDiffResult
    {
        $live = $this->buildLiveState();
        $local = $this->filterStateToManagedSet(
            $this->repositoryStateReader->read('local', 'refs/heads/' . $settings->branch)
        );
        $remote = $this->filterStateToManagedSet(
            $this->repositoryStateReader->read('remote', 'refs/remotes/origin/' . $settings->branch)
        );

        return new ManagedSetDiffResult(
            $this->adapter->getManagedSetKey(),
            $live,
            $local,
            $remote,
            $this->diffStates($live, $local),
            $this->diffStates($local, $remote),
            $this->determineRelationship($local->commitHash, $remote->commitHash)
        );
    }

    private function buildLiveState(): CanonicalManagedState
    {
        $snapshot = $this->adapter->exportSnapshot();
        $files = [];

        foreach ($this->snapshotFiles($snapshot) as $path => $content) {
            $files[$path] = new CanonicalManagedFile($path, $content);
        }
        ksort($files);

        return new CanonicalManagedState(
            'live',
            null,
            null,
            sha1($this->serializeSnapshot($snapshot)),
            $files
        );
    }

    private function diffStates(CanonicalManagedState $left, CanonicalManagedState $right): CanonicalDiffResult
    {
        $paths = array_unique(array_merge(array_keys($left->files), array_keys($right->files)));
        sort($paths);
        $entries = [];

        foreach ($paths as $path) {
            $leftFile = $left->files[$path] ?? null;
            $rightFile = $right->files[$path] ?? null;

            if ($leftFile === null && $rightFile !== null) {
                $entries[] = new CanonicalDiffEntry($path, 'added', null, $rightFile->contentHash());
                continue;
            }

            if ($leftFile !== null && $rightFile === null) {
                $entries[] = new CanonicalDiffEntry($path, 'deleted', $leftFile->contentHash(), null);
                continue;
            }

            if ($leftFile === null || $rightFile === null) {
                continue;
            }

            $leftHash = $leftFile->contentHash();
            $rightHash = $rightFile->contentHash();
            $entries[] = new CanonicalDiffEntry(
                $path,
                $leftHash === $rightHash ? 'unchanged' : 'modified',
                $leftHash,
                $rightHash
            );
        }

        return new CanonicalDiffResult($entries);
    }

    private function determineRelationship(?string $localCommitHash, ?string $remoteCommitHash): RepositoryRelationship
    {
        if ($localCommitHash === null && $remoteCommitHash === null) {
            return new RepositoryRelationship(RepositoryRelationship::NO_COMMITS);
        }

        if ($localCommitHash !== null && $remoteCommitHash === null) {
            return new RepositoryRelationship(RepositoryRelationship::LOCAL_ONLY);
        }

        if ($localCommitHash === null && $remoteCommitHash !== null) {
            return new RepositoryRelationship(RepositoryRelationship::REMOTE_ONLY);
        }

        if ($localCommitHash === $remoteCommitHash) {
            return new RepositoryRelationship(RepositoryRelationship::IN_SYNC);
        }

        if ($localCommitHash !== null && $remoteCommitHash !== null) {
            $localAncestors = $this->ancestorSet($localCommitHash);
            $remoteAncestors = $this->ancestorSet($remoteCommitHash);

            if (isset($localAncestors[$remoteCommitHash])) {
                return new RepositoryRelationship(RepositoryRelationship::AHEAD);
            }

            if (isset($remoteAncestors[$localCommitHash])) {
                return new RepositoryRelationship(RepositoryRelationship::BEHIND);
            }

            if ($this->hasSharedAncestor($localAncestors, $remoteAncestors)) {
                return new RepositoryRelationship(RepositoryRelationship::DIVERGED);
            }
        }

        return new RepositoryRelationship(RepositoryRelationship::UNRELATED);
    }

    /**
     * @return array<string, true>
     */
    private function ancestorSet(string $commitHash): array
    {
        $seen = [];
        $queue = [$commitHash];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (! is_string($current) || $current === '' || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $commit = $this->repositoryStateReaderCommit($current);

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

    /**
     * @param array<string, true> $left
     * @param array<string, true> $right
     */
    private function hasSharedAncestor(array $left, array $right): bool
    {
        foreach ($left as $hash => $_) {
            if (isset($right[$hash])) {
                return true;
            }
        }

        return false;
    }

    private function repositoryStateReaderCommit(string $hash): ?Commit
    {
        return $this->localRepository->getCommit($hash);
    }

    private function serializeSnapshot(ManagedContentSnapshot $snapshot): string
    {
        $parts = [];

        foreach ($this->snapshotFiles($snapshot) as $path => $content) {
            $parts[] = $path . "\n" . $content;
        }
        sort($parts);

        return implode("\n", $parts);
    }

    /**
     * @return array<string, string>
     */
    private function snapshotFiles(ManagedContentSnapshot $snapshot): array
    {
        if ($snapshot->repositoryFilesAuthoritative) {
            return $snapshot->files;
        }

        $files = [];

        foreach ($snapshot->items as $item) {
            $files[$this->adapter->getRepositoryPath($item)] = $this->adapter->serialize($item);
        }

        $files[$this->adapter->getManifestPath()] = $this->adapter->serializeManifest($snapshot->manifest);
        ksort($files);

        return $files;
    }

    private function filterStateToManagedSet(CanonicalManagedState $state): CanonicalManagedState
    {
        $files = [];

        foreach ($state->files as $path => $file) {
            if (! $this->adapter->ownsRepositoryPath($path)) {
                continue;
            }

            $files[$path] = $file;
        }

        ksort($files);

        return new CanonicalManagedState(
            $state->source,
            $state->refName,
            $state->commitHash,
            $state->treeHash,
            $files
        );
    }
}
