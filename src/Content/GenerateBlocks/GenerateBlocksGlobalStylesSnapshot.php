<?php

declare(strict_types=1);

namespace PushPull\Content\GenerateBlocks;

use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;

final class GenerateBlocksGlobalStylesSnapshot
{
    /**
     * @param ManagedContentItem[] $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ManagedCollectionManifest $manifest
    ) {
    }
}
