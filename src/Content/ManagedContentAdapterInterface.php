<?php

declare(strict_types=1);

namespace PushPull\Content;

interface ManagedContentAdapterInterface
{
    public function getManagedSetKey(): string;

    public function getManagedSetLabel(): string;

    public function getContentType(): string;

    public function isAvailable(): bool;

    /**
     * @return ManagedContentItem[]
     */
    public function exportAll(): array;

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem;

    /**
     * @param array<string, mixed> $wpRecord
     */
    public function computeLogicalKey(array $wpRecord): string;

    public function getRepositoryPath(ManagedContentItem $item): string;

    public function serialize(ManagedContentItem $item): string;

    public function deserialize(string $path, string $content): ManagedContentItem;
}
