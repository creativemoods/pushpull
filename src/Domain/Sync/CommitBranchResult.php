<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

use PushPull\Domain\Repository\Commit;
use PushPull\Domain\Repository\Tree;

final class CommitBranchResult
{
    /**
     * @param string[] $managedSetKeys
     */
    public function __construct(
        public readonly bool $createdNewCommit,
        public readonly ?Commit $commit,
        public readonly ?Tree $tree,
        public readonly int $changedPathCount,
        public readonly bool $initializedRepository,
        public readonly array $managedSetKeys
    ) {
    }
}
