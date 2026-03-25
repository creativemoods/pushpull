<?php

declare(strict_types=1);

namespace PushPull\Content\GenerateBlocks;

final class GenerateBlocksRepositoryLayout
{
    public function itemPath(string $logicalKey): string
    {
        return sprintf('generateblocks/global-styles/%s.json', $logicalKey);
    }

    public function manifestPath(): string
    {
        return 'generateblocks/global-styles/manifest.json';
    }
}
