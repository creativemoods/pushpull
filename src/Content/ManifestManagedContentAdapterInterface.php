<?php

declare(strict_types=1);

namespace PushPull\Content;

interface ManifestManagedContentAdapterInterface extends ManagedContentAdapterInterface
{
    public function exportSnapshot(): ManagedContentSnapshot;

    public function getManifestPath(): string;

    public function ownsRepositoryPath(string $path): bool;

    public function serializeManifest(ManagedCollectionManifest $manifest): string;

    public function hashItem(ManagedContentItem $item): string;

    public function hashManifest(ManagedCollectionManifest $manifest): string;

    /**
     * @param ManagedContentItem[] $items
     */
    public function validateManifest(ManagedCollectionManifest $manifest, array $items): void;

    public function parseManifest(string $content): ManagedCollectionManifest;

    public function buildCommitMessage(): string;
}
