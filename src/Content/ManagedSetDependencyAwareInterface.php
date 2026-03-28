<?php

declare(strict_types=1);

namespace PushPull\Content;

interface ManagedSetDependencyAwareInterface
{
    /**
     * @return string[]
     */
    public function getManagedSetDependencies(): array;
}
