<?php

declare(strict_types=1);

namespace PushPull\Domain\Diff;

final class CanonicalDiffEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $status,
        public readonly ?string $leftHash,
        public readonly ?string $rightHash
    ) {
    }
}
