<?php

declare(strict_types=1);

namespace PushPull\Content;

interface OverlayManagedContentAdapterInterface extends ManifestManagedContentAdapterInterface, OverlayManagedSetInterface
{
    public function applyOverlayItem(ManagedContentItem $item): bool;

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    public function deleteMissingOverlayItems(array $desiredLogicalKeys): array;
}
