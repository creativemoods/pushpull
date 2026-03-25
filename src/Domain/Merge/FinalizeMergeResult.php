<?php

declare(strict_types=1);

namespace PushPull\Domain\Merge;

use PushPull\Domain\Repository\Commit;

final class FinalizeMergeResult
{
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly Commit $commit
    ) {
    }
}
