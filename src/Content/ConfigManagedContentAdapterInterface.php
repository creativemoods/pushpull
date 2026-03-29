<?php

declare(strict_types=1);

namespace PushPull\Content;

interface ConfigManagedContentAdapterInterface extends ManifestManagedContentAdapterInterface
{
    public function applyItem(ManagedContentItem $item): void;
}
