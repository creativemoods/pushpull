<?php

declare(strict_types=1);

namespace PushPull\Provider;

use PushPull\Settings\PushPullSettings;

final class GitRemoteConfig
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $ownerOrWorkspace,
        public readonly string $repository,
        public readonly string $branch,
        public readonly string $apiToken,
        public readonly ?string $baseUrl
    ) {
    }

    public static function fromSettings(PushPullSettings $settings): self
    {
        return new self(
            $settings->providerKey,
            $settings->ownerOrWorkspace,
            $settings->repository,
            $settings->branch,
            $settings->apiToken,
            $settings->baseUrl !== '' ? $settings->baseUrl : null
        );
    }

    public function repositoryPath(): string
    {
        return trim($this->ownerOrWorkspace . '/' . $this->repository, '/');
    }
}
