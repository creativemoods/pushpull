<?php

declare(strict_types=1);

namespace PushPull\Settings;

final class PushPullSettings
{
    /**
     * @param string[] $enabledManagedSets
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $ownerOrWorkspace,
        public readonly string $repository,
        public readonly string $branch,
        public readonly string $apiToken,
        public readonly string $baseUrl,
        public readonly bool $autoApplyEnabled,
        public readonly bool $diagnosticsEnabled,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly array $enabledManagedSets = [],
        public readonly int $fetchAvailabilityCheckIntervalMinutes = 5
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $enabledManagedSets = [];

        if (isset($values['enabled_managed_sets']) && is_array($values['enabled_managed_sets'])) {
            $enabledManagedSets = array_values(array_filter(array_map(
                static fn (mixed $value): string => self::normalizeManagedSetKey((string) $value),
                $values['enabled_managed_sets']
            )));
        } else {
            if (! empty($values['manage_generateblocks_global_styles'])) {
                $enabledManagedSets[] = 'generateblocks_global_styles';
            }

            if (! empty($values['manage_generateblocks_conditions'])) {
                $enabledManagedSets[] = 'generateblocks_conditions';
            }

            if (! empty($values['manage_generateblocks_local_patterns'])) {
                $enabledManagedSets[] = 'wordpress_block_patterns';
            }
        }

        return new self(
            (string) ($values['provider_key'] ?? 'github'),
            (string) ($values['owner_or_workspace'] ?? ''),
            (string) ($values['repository'] ?? ''),
            (string) ($values['branch'] ?? 'main'),
            (string) ($values['api_token'] ?? ''),
            (string) ($values['base_url'] ?? ''),
            (bool) ($values['auto_apply_enabled'] ?? false),
            (bool) ($values['diagnostics_enabled'] ?? true),
            (string) ($values['author_name'] ?? ''),
            (string) ($values['author_email'] ?? ''),
            array_values(array_unique($enabledManagedSets)),
            (int) ($values['fetch_availability_check_interval_minutes'] ?? 5)
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
            'fetch_availability_check_interval_minutes' => $this->fetchAvailabilityCheckIntervalMinutes,
            'enabled_managed_sets' => array_values($this->enabledManagedSets),
            'auto_apply_enabled' => $this->autoApplyEnabled,
            'diagnostics_enabled' => $this->diagnosticsEnabled,
            'author_name' => $this->authorName,
            'author_email' => $this->authorEmail,
        ];
    }

    public function isManagedSetEnabled(string $managedSetKey): bool
    {
        return in_array($managedSetKey, $this->enabledManagedSets, true);
    }

    private static function normalizeManagedSetKey(string $managedSetKey): string
    {
        $normalized = sanitize_key($managedSetKey);

        return match ($normalized) {
            'generateblocks_local_patterns' => 'wordpress_block_patterns',
            default => $normalized,
        };
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
