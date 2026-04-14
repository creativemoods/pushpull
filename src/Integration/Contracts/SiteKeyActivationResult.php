<?php

declare(strict_types=1);

namespace PushPull\Integration\Contracts;

final class SiteKeyActivationResult
{
    /**
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        public readonly string $integrationKey,
        public readonly string $siteKey,
        public readonly string $storedSiteKey,
        public readonly array $rawResponse
    ) {
    }
}
