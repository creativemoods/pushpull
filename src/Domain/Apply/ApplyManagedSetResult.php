<?php

declare(strict_types=1);

namespace PushPull\Domain\Apply;

final class ApplyManagedSetResult
{
    /**
     * @param int[] $appliedWpObjectIds
     * @param string[] $deletedLogicalKeys
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly string $sourceCommitHash,
        public readonly int $createdCount,
        public readonly int $updatedCount,
        public readonly array $appliedWpObjectIds,
        public readonly array $deletedLogicalKeys
    ) {
    }
}
