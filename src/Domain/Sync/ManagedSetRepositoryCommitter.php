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

        $entries = [];
        $pathHashes = [];

        foreach ($snapshot->items as $item) {
            $path = $this->adapter->getRepositoryPath($item);
            $blob = $this->localRepository->storeBlob($this->adapter->serialize($item));
            $entries[] = new TreeEntry($path, 'blob', $blob->hash);
            $pathHashes[$path] = $blob->hash;
        }

        $manifestPath = $this->adapter->getManifestPath();
        $manifestBlob = $this->localRepository->storeBlob($this->adapter->serializeManifest($snapshot->manifest));
        $entries[] = new TreeEntry($manifestPath, 'blob', $manifestBlob->hash);
        $pathHashes[$manifestPath] = $manifestBlob->hash;

        $tree = $this->localRepository->storeTree($entries);
        $headCommit = $this->localRepository->getHeadCommit($request->branch);

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
}
