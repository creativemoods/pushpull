<?php

declare(strict_types=1);

namespace PushPull\Domain\Diff;

final class CanonicalManagedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $content
    ) {
    }

    public function contentHash(): string
    {
        return sha1($this->content);
    }
}
