<?php

declare(strict_types=1);

namespace PushPull\Content;

interface WordPressManagedContentAdapterInterface extends ManifestManagedContentAdapterInterface
{
    public function isManagedItemPath(string $path): bool;

    public function findExistingWpObjectIdByLogicalKey(string $logicalKey): ?int;

    public function postExists(int $postId): bool;

    public function upsertItem(ManagedContentItem $item, int $menuOrder, ?int $existingId): int;

    public function persistItemMeta(int $postId, ManagedContentItem $item): void;

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    public function deleteMissingItems(array $desiredLogicalKeys): array;
}
