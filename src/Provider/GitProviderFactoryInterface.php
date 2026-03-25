<?php

declare(strict_types=1);

namespace PushPull\Provider;

interface GitProviderFactoryInterface
{
    public function make(string $providerKey): GitProviderInterface;
}
