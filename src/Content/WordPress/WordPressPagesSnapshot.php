<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedContentSnapshot;

final class WordPressPagesSnapshot extends ManagedContentSnapshot
{
    /**
     * @param ManagedContentItem[] $items
     */
    public function __construct(array $items, ManagedCollectionManifest $manifest)
    {
        parent::__construct($items, $manifest);
    }
}
