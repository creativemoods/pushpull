<?php

declare(strict_types=1);

namespace PushPull\Domain\Repository;

final class Commit
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $hash,
        public readonly string $treeHash,
        public readonly ?string $parentHash,
        public readonly ?string $secondParentHash,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly string $message,
        public readonly string $committedAt,
        public readonly array $metadata
    ) {
    }
}
