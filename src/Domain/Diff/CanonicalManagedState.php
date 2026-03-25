<?php

declare(strict_types=1);

namespace PushPull\Domain\Diff;

final class CanonicalManagedState
{
    /**
     * @param array<string, CanonicalManagedFile> $files
     */
    public function __construct(
        public readonly string $source,
        public readonly ?string $refName,
        public readonly ?string $commitHash,
        public readonly ?string $treeHash,
        public readonly array $files
    ) {
    }

    public function exists(): bool
    {
        return $this->commitHash !== null || $this->files !== [];
    }

    /**
     * @return string[]
     */
    public function paths(): array
    {
        $paths = array_keys($this->files);
        sort($paths);

        return $paths;
    }
}
