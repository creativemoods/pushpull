<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class UpdateRemoteRefRequest
{
    public function __construct(
        public readonly string $refName,
        public readonly string $newCommitHash,
        public readonly ?string $expectedOldCommitHash = null
    ) {
    }
}
