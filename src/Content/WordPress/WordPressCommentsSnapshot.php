<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedContentSnapshot;

final class WordPressCommentsSnapshot extends ManagedContentSnapshot
{
    /**
     * @param ManagedContentItem[] $items
     * @param array<string, string> $files
     * @param string[] $orderedLogicalKeys
     */
    public function __construct(
        array $items,
        ManagedCollectionManifest $manifest,
        array $files = [],
        array $orderedLogicalKeys = []
    ) {
        parent::__construct($items, $manifest, $files, $orderedLogicalKeys);
    }
}
