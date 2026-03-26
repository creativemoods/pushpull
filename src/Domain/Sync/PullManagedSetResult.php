<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

use PushPull\Domain\Merge\MergeManagedSetResult;

final class PullManagedSetResult
{
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly FetchManagedSetResult $fetchResult,
        public readonly MergeManagedSetResult $mergeResult
    ) {
    }
}
