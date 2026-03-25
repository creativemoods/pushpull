<?php

declare(strict_types=1);

namespace PushPull\Domain\Repository;

final class TreeEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly string $hash
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type,
            'hash' => $this->hash,
        ];
    }
}
