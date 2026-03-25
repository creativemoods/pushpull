<?php

declare(strict_types=1);

namespace PushPull\Domain\Push;

final class ResetRemoteBranchResult
{
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly string $previousRemoteCommitHash,
        public readonly string $remoteCommitHash,
        public readonly string $remoteTreeHash
    ) {
    }
}
