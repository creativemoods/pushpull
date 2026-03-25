<?php

declare(strict_types=1);

namespace PushPull\Domain\Repository;

final class Blob
{
    public function __construct(
        public readonly string $hash,
        public readonly string $content,
        public readonly int $size,
        public readonly string $createdAt
    ) {
    }
}
