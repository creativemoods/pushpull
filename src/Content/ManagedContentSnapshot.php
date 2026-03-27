<?php

declare(strict_types=1);

namespace PushPull\Content;

class ManagedContentSnapshot
{
    /**
     * @param ManagedContentItem[] $items
     * @param array<string, string> $files
     * @param string[] $orderedLogicalKeys
     */
    public function __construct(
        public readonly array $items,
        public readonly ManagedCollectionManifest $manifest,
        public readonly array $files = [],
        public readonly array $orderedLogicalKeys = [],
        public readonly bool $repositoryFilesAuthoritative = false
    ) {
    }
}
