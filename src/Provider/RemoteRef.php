<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class RemoteRef
{
    public function __construct(
        public readonly string $name,
        public readonly string $commitHash
    ) {
    }
}
