<?php

declare(strict_types=1);

namespace PushPull\Domain\Repository;

final class Tree
{
    /**
     * @param TreeEntry[] $entries
     */
    public function __construct(
        public readonly string $hash,
        public readonly array $entries,
        public readonly string $createdAt
    ) {
    }
}
