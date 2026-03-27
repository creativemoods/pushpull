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

        $snapshotFiles = $snapshot->repositoryFilesAuthoritative ? $snapshot->files : $this->buildSnapshotFiles($snapshot);

        foreach ($snapshotFiles as $path => $content) {
            $blob = $this->localRepository->storeBlob($content);
            $entriesByPath[$path] = new TreeEntry($path, 'blob', $blob->hash);
            $pathHashes[$path] = $blob->hash;
        }

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
     * @return array<string, string>
     */
    private function buildSnapshotFiles(ManagedContentSnapshot $snapshot): array
    {
        $files = [];

        foreach ($snapshot->items as $item) {
            $files[$this->adapter->getRepositoryPath($item)] = $this->adapter->serialize($item);
        }

        $files[$this->adapter->getManifestPath()] = $this->adapter->serializeManifest($snapshot->manifest);
        ksort($files);

        return $files;
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
