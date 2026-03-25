<?php

declare(strict_types=1);

namespace PushPull\Domain\Push;

final class PushManagedSetResult
{
    /**
     * @param string[] $pushedCommitHashes
     * @param string[] $pushedTreeHashes
     * @param string[] $pushedBlobHashes
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly string $status,
        public readonly ?string $localCommitHash,
        public readonly ?string $remoteCommitHash,
        public readonly array $pushedCommitHashes,
        public readonly array $pushedTreeHashes,
        public readonly array $pushedBlobHashes
    ) {
    }
}
