<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

use PushPull\Content\ManagedContentSnapshot;
use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Domain\Repository\CommitRequest;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Domain\Repository\Tree;
use PushPull\Domain\Repository\TreeEntry;

final class BranchCommitService
{
    public function __construct(private readonly LocalRepositoryInterface $localRepository)
    {
    }

    /**
     * @param array<string, ManifestManagedContentAdapterInterface> $adapters
     */
    public function commitManagedSets(array $adapters, CommitManagedSetRequest $request): CommitBranchResult
    {
        $initializedRepository = false;

        if (! $this->localRepository->hasBeenInitialized($request->branch)) {
            $this->localRepository->init($request->branch);
            $initializedRepository = true;
        }

        $headCommit = $this->localRepository->getHeadCommit($request->branch);
        $entriesByPath = [];
        $previousOwnedHashes = [];
        $nextOwnedHashes = [];

        if ($headCommit !== null) {
            foreach ($this->readTreeEntries($headCommit->treeHash) as $entry) {
                if ($this->pathOwnedByAnyAdapter($adapters, $entry->path)) {
                    $previousOwnedHashes[$entry->path] = $entry->hash;
                    continue;
                }

                $entriesByPath[$entry->path] = $entry;
            }
        }

        foreach ($adapters as $adapter) {
            foreach ($this->snapshotFiles($adapter, $adapter->exportSnapshot()) as $path => $content) {
                $blob = $this->localRepository->storeBlob($content);
                $entriesByPath[$path] = new TreeEntry($path, 'blob', $blob->hash);
                $nextOwnedHashes[$path] = $blob->hash;
            }
        }

        ksort($entriesByPath);
        $tree = $this->localRepository->storeTree(array_values($entriesByPath));

        if ($headCommit !== null && $headCommit->treeHash === $tree->hash) {
            return new CommitBranchResult(
                false,
                $headCommit,
                $tree,
                0,
                $initializedRepository,
                array_keys($adapters)
            );
        }

        $commit = $this->localRepository->commit(new CommitRequest(
            $tree->hash,
            $headCommit?->hash,
            null,
            $request->authorName,
            $request->authorEmail,
            $request->message,
            [
                'managedSetKeys' => array_keys($adapters),
                'fileCount' => count($nextOwnedHashes),
                'changedPathCount' => $this->changedPathCount($previousOwnedHashes, $nextOwnedHashes),
            ]
        ));

        $this->localRepository->updateRef('refs/heads/' . $request->branch, $commit->hash);
        $this->localRepository->updateRef('HEAD', $commit->hash);

        return new CommitBranchResult(
            true,
            $commit,
            $tree,
            $this->changedPathCount($previousOwnedHashes, $nextOwnedHashes),
            $initializedRepository,
            array_keys($adapters)
        );
    }

    /**
     * @param array<string, string> $previousOwnedHashes
     * @param array<string, string> $nextOwnedHashes
     */
    private function changedPathCount(array $previousOwnedHashes, array $nextOwnedHashes): int
    {
        $count = 0;
        $paths = array_unique(array_merge(array_keys($previousOwnedHashes), array_keys($nextOwnedHashes)));

        foreach ($paths as $path) {
            if (($previousOwnedHashes[$path] ?? null) !== ($nextOwnedHashes[$path] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, ManifestManagedContentAdapterInterface> $adapters
     */
    private function pathOwnedByAnyAdapter(array $adapters, string $path): bool
    {
        foreach ($adapters as $adapter) {
            if ($adapter->ownsRepositoryPath($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function snapshotFiles(ManifestManagedContentAdapterInterface $adapter, ManagedContentSnapshot $snapshot): array
    {
        if ($snapshot->repositoryFilesAuthoritative) {
            return $snapshot->files;
        }

        $files = [];

        foreach ($snapshot->items as $item) {
            $files[$adapter->getRepositoryPath($item)] = $adapter->serialize($item);
        }

        $files[$adapter->getManifestPath()] = $adapter->serializeManifest($snapshot->manifest);
        ksort($files);

        return $files;
    }

    /**
     * @return array<int, TreeEntry>
     */
    private function readTreeEntries(string $treeHash, string $prefix = ''): array
    {
        $tree = $this->localRepository->getTree($treeHash);

        if (! $tree instanceof Tree) {
            return [];
        }

        $entries = [];

        foreach ($tree->entries as $entry) {
            $path = $prefix !== '' ? $prefix . '/' . $entry->path : $entry->path;

            if ($entry->type === 'tree') {
                array_push($entries, ...$this->readTreeEntries($entry->hash, $path));
                continue;
            }

            if ($entry->type !== 'blob') {
                continue;
            }

            $entries[] = new TreeEntry($path, $entry->type, $entry->hash);
        }

        return $entries;
    }
}
