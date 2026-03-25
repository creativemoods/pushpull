<?php

declare(strict_types=1);

namespace PushPull\Content;

final class ManagedContentItem
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $derived
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $contentType,
        public readonly string $logicalKey,
        public readonly string $displayName,
        public readonly string $selector,
        public readonly string $slug,
        public readonly array $payload,
        public readonly string $postStatus = 'publish',
        public readonly array $metadata = [],
        public readonly array $derived = [],
        public readonly ?int $sourceWpObjectId = null,
        public readonly int $schemaVersion = 1,
        public readonly int $adapterVersion = 1
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $document = [
            'schemaVersion' => $this->schemaVersion,
            'adapterVersion' => $this->adapterVersion,
            'type' => $this->contentType,
            'logicalKey' => $this->logicalKey,
            'displayName' => $this->displayName,
            'selector' => $this->selector,
            'slug' => $this->slug,
            'postStatus' => $this->postStatus,
            'payload' => $this->payload,
        ];

        if ($this->metadata !== []) {
            $document['metadata'] = $this->metadata;
        }

        if ($this->derived !== []) {
            $document['derived'] = $this->derived;
        }

        return $document;
    }
}
