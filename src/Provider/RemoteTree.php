<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class RemoteTree
{
    /**
     * @param array<int, array<string, string>> $entries
     */
    public function __construct(
        public readonly string $hash,
        public readonly array $entries
    ) {
    }
}
