<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

final class FetchManagedSetResult
{
    /**
     * @param string[] $traversedCommitHashes
     * @param string[] $traversedTreeHashes
     * @param string[] $traversedBlobHashes
     * @param string[] $newCommitHashes
     * @param string[] $newTreeHashes
     * @param string[] $newBlobHashes
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $remoteRefName,
        public readonly string $remoteCommitHash,
        public readonly array $traversedCommitHashes,
        public readonly array $traversedTreeHashes,
        public readonly array $traversedBlobHashes,
        public readonly array $newCommitHashes,
        public readonly array $newTreeHashes,
        public readonly array $newBlobHashes
    ) {
    }
}
