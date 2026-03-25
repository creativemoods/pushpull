<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

use PushPull\Domain\Repository\Commit;
use PushPull\Domain\Repository\Tree;

final class CommitManagedSetResult
{
    /**
     * @param array<string, string> $pathHashes
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly bool $createdNewCommit,
        public readonly ?Commit $commit,
        public readonly ?Tree $tree,
        public readonly array $pathHashes,
        public readonly bool $initializedRepository
    ) {
    }
}
