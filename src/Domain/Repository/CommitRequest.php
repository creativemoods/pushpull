<?php

declare(strict_types=1);

namespace PushPull\Domain\Repository;

final class CommitRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $treeHash,
        public readonly ?string $parentHash,
        public readonly ?string $secondParentHash,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly string $message,
        public readonly array $metadata = []
    ) {
    }
}
