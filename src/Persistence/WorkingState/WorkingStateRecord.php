<?php

declare(strict_types=1);

namespace PushPull\Persistence\WorkingState;

use PushPull\Domain\Merge\MergeConflict;

final class WorkingStateRecord
{
    /**
     * @param array<string, string> $workingTree
     * @param MergeConflict[] $conflicts
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branchName,
        public readonly string $currentBranch,
        public readonly ?string $headCommitHash,
        public readonly ?string $mergeBaseHash,
        public readonly ?string $mergeTargetHash,
        public readonly array $workingTree,
        public readonly array $conflicts,
        public readonly string $updatedAt
    ) {
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
