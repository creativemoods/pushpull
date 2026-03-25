<?php

declare(strict_types=1);

namespace PushPull\Domain\Merge;

final class MergeConflict
{
    /**
     * @param string[] $jsonPaths
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $baseContent,
        public readonly ?string $oursContent,
        public readonly ?string $theirsContent,
        public readonly array $jsonPaths = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'baseContent' => $this->baseContent,
            'oursContent' => $this->oursContent,
            'theirsContent' => $this->theirsContent,
            'jsonPaths' => array_values($this->jsonPaths),
        ];
    }
}
