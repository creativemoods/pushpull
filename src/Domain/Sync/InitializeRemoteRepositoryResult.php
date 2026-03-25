<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

final class InitializeRemoteRepositoryResult
{
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly string $remoteRefName,
        public readonly string $remoteCommitHash,
        public readonly FetchManagedSetResult $fetchResult
    ) {
    }
}
