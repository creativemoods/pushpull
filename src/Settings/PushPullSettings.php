<?php

declare(strict_types=1);

namespace PushPull\Settings;

final class PushPullSettings
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $ownerOrWorkspace,
        public readonly string $repository,
        public readonly string $branch,
        public readonly string $apiToken,
        public readonly string $baseUrl,
        public readonly bool $manageGenerateBlocksGlobalStyles,
        public readonly bool $autoApplyEnabled,
        public readonly bool $diagnosticsEnabled,
        public readonly string $authorName,
        public readonly string $authorEmail
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            (string) ($values['provider_key'] ?? 'github'),
            (string) ($values['owner_or_workspace'] ?? ''),
            (string) ($values['repository'] ?? ''),
            (string) ($values['branch'] ?? 'main'),
            (string) ($values['api_token'] ?? ''),
            (string) ($values['base_url'] ?? ''),
            (bool) ($values['manage_generateblocks_global_styles'] ?? false),
            (bool) ($values['auto_apply_enabled'] ?? false),
            (bool) ($values['diagnostics_enabled'] ?? true),
            (string) ($values['author_name'] ?? ''),
            (string) ($values['author_email'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'owner_or_workspace' => $this->ownerOrWorkspace,
            'repository' => $this->repository,
            'branch' => $this->branch,
            'api_token' => $this->apiToken,
            'base_url' => $this->baseUrl,
            'manage_generateblocks_global_styles' => $this->manageGenerateBlocksGlobalStyles,
            'auto_apply_enabled' => $this->autoApplyEnabled,
            'diagnostics_enabled' => $this->diagnosticsEnabled,
            'author_name' => $this->authorName,
            'author_email' => $this->authorEmail,
        ];
    }

    public function maskedApiToken(): string
    {
        if ($this->apiToken === '') {
            return '';
        }

        $suffix = substr($this->apiToken, -4);

        return sprintf('Stored token ending in %s', $suffix);
    }
}
