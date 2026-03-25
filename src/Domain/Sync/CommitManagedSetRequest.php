<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

final class CommitManagedSetRequest
{
    public function __construct(
        public readonly string $branch,
        public readonly string $message,
        public readonly string $authorName,
        public readonly string $authorEmail
    ) {
    }
}
