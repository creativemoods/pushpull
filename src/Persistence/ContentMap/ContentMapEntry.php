<?php

declare(strict_types=1);

namespace PushPull\Persistence\ContentMap;

final class ContentMapEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $managedSetKey,
        public readonly string $contentType,
        public readonly string $logicalKey,
        public readonly ?int $wpObjectId,
        public readonly ?string $lastKnownHash,
        public readonly string $status
    ) {
    }
}
