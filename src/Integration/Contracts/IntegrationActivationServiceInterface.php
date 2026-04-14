<?php

declare(strict_types=1);

namespace PushPull\Integration\Contracts;

interface IntegrationActivationServiceInterface
{
    public function isAvailable(): bool;
}
