<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class UpdateRefResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $refName,
        public readonly string $commitHash
    ) {
    }
}
