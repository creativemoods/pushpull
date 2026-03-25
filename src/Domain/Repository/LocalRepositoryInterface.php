<?php

declare(strict_types=1);

namespace PushPull\Domain\Repository;

use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;

interface LocalRepositoryInterface
{
    public function init(string $defaultBranch): void;

    public function hasBeenInitialized(string $branch): bool;

    public function storeBlob(string $content): Blob;

    public function importRemoteBlob(RemoteBlob $remoteBlob): Blob;

    public function getBlob(string $hash): ?Blob;

    /**
     * @param TreeEntry[] $entries
     */
    public function storeTree(array $entries): Tree;

    public function importRemoteTree(RemoteTree $remoteTree): Tree;

    public function getTree(string $hash): ?Tree;

    public function commit(CommitRequest $request): Commit;

    public function importRemoteCommit(RemoteCommit $remoteCommit): Commit;

    public function getCommit(string $hash): ?Commit;

    public function updateRef(string $refName, string $commitHash): Ref;

    public function getRef(string $refName): ?Ref;

    public function getHeadCommit(string $branch): ?Commit;
}
