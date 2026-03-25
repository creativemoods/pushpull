<?php

declare(strict_types=1);

namespace PushPull\Domain\Repository;

final class Ref
{
    public function __construct(
        public readonly string $name,
        public readonly string $commitHash,
        public readonly string $updatedAt
    ) {
    }
}
