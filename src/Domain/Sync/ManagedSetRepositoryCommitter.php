<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

use PushPull\Content\ManagedContentSnapshot;
use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Domain\Repository\CommitRequest;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Domain\Repository\TreeEntry;

final class ManagedSetRepositoryCommitter
{
    public function __construct(
        private readonly LocalRepositoryInterface $localRepository,
        private readonly ManifestManagedContentAdapterInterface $adapter
    ) {
    }

    public function commitSnapshot(ManagedContentSnapshot $snapshot, CommitManagedSetRequest $request): CommitManagedSetResult
    {
        $initializedRepository = false;

        if (! $this->localRepository->hasBeenInitialized($request->branch)) {
            $this->localRepository->init($request->branch);
            $initializedRepository = true;
        }

        $headCommit = $this->localRepository->getHeadCommit($request->branch);
        $entriesByPath = [];
        $pathHashes = [];

        if ($headCommit !== null) {
            foreach ($this->readTreeEntries($headCommit->treeHash) as $entry) {
                if ($this->adapter->ownsRepositoryPath($entry->path)) {
                    continue;
                }

                $entriesByPath[$entry->path] = $entry;
            }
        }

        foreach ($snapshot->items as $item) {
            $path = $this->adapter->getRepositoryPath($item);
            $blob = $this->localRepository->storeBlob($this->adapter->serialize($item));
            $entriesByPath[$path] = new TreeEntry($path, 'blob', $blob->hash);
            $pathHashes[$path] = $blob->hash;
        }

        $manifestPath = $this->adapter->getManifestPath();
        $manifestBlob = $this->localRepository->storeBlob($this->adapter->serializeManifest($snapshot->manifest));
        $entriesByPath[$manifestPath] = new TreeEntry($manifestPath, 'blob', $manifestBlob->hash);
        $pathHashes[$manifestPath] = $manifestBlob->hash;

        ksort($entriesByPath);
        $entries = array_values($entriesByPath);
        $tree = $this->localRepository->storeTree($entries);

        if ($headCommit !== null && $headCommit->treeHash === $tree->hash) {
            return new CommitManagedSetResult(
                $snapshot->manifest->managedSetKey,
                false,
                $headCommit,
                $tree,
                $pathHashes,
                $initializedRepository
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
                'managedSetKey' => $snapshot->manifest->managedSetKey,
                'fileCount' => count($pathHashes),
            ]
        ));

        $this->localRepository->updateRef('refs/heads/' . $request->branch, $commit->hash);
        $this->localRepository->updateRef('HEAD', $commit->hash);

        return new CommitManagedSetResult(
            $snapshot->manifest->managedSetKey,
            true,
            $commit,
            $tree,
            $pathHashes,
            $initializedRepository
        );
    }

    /**
     * @return array<int, TreeEntry>
     */
    private function readTreeEntries(string $treeHash, string $prefix = ''): array
    {
        $tree = $this->localRepository->getTree($treeHash);

        if ($tree === null) {
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
