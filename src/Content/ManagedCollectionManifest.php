<?php

declare(strict_types=1);

namespace PushPull\Content;

final class ManagedCollectionManifest
{
    /**
     * @param string[] $orderedLogicalKeys
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $manifestType,
        public readonly array $orderedLogicalKeys,
        public readonly int $schemaVersion = 1
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'type' => $this->manifestType,
            'orderedLogicalKeys' => array_values($this->orderedLogicalKeys),
        ];
    }
}
