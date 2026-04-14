<?php

declare(strict_types=1);

namespace PushPull\Integration\Contracts;

interface SiteKeyActivationServiceInterface extends IntegrationActivationServiceInterface
{
    public function activateSiteKey(string $siteKey): SiteKeyActivationResult;
}
