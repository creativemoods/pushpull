<?php

declare(strict_types=1);

namespace PushPull\Domain\Merge;

use PushPull\Domain\Repository\Commit;

final class MergeManagedSetResult
{
    /**
     * @param array<string, string> $mergedFiles
     * @param MergeConflict[] $conflicts
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly ?string $baseCommitHash,
        public readonly ?string $oursCommitHash,
        public readonly ?string $theirsCommitHash,
        public readonly string $status,
        public readonly ?Commit $commit,
        public readonly array $mergedFiles,
        public readonly array $conflicts
    ) {
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
