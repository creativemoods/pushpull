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
        public readonly int $schemaVersion = 1,
        public readonly array $extra = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'schemaVersion' => $this->schemaVersion,
            'type' => $this->manifestType,
            'orderedLogicalKeys' => array_values($this->orderedLogicalKeys),
        ];

        foreach ($this->extra as $key => $value) {
            if (
                $key === 'schemaVersion'
                || $key === 'type'
                || $key === 'orderedLogicalKeys'
            ) {
                continue;
            }

            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
