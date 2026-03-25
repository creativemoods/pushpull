<?php

declare(strict_types=1);

namespace PushPull\Domain\Merge;

final class ResolveConflictResult
{
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly string $path,
        public readonly int $remainingConflictCount
    ) {
    }
}
