<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class RemoteBlob
{
    public function __construct(
        public readonly string $hash,
        public readonly string $content
    ) {
    }
}
