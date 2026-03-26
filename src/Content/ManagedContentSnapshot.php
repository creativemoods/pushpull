<?php

declare(strict_types=1);

namespace PushPull\Content;

class ManagedContentSnapshot
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
