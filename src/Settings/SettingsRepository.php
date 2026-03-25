<?php

declare(strict_types=1);

namespace PushPull\Settings;

final class SettingsRepository
{
    public const OPTION_KEY = 'pushpull_settings';

    public function get(): PushPullSettings
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (! is_array($stored)) {
            $stored = [];
        }

        return PushPullSettings::fromArray(array_merge($this->defaults()->toArray(), $stored));
    }

    public function save(PushPullSettings $settings): void
    {
        update_option(self::OPTION_KEY, $settings->toArray(), false);
    }

    public function defaults(): PushPullSettings
    {
        return PushPullSettings::fromArray([
            'provider_key' => 'github',
            'owner_or_workspace' => '',
            'repository' => '',
            'branch' => 'main',
            'api_token' => '',
            'base_url' => '',
            'manage_generateblocks_global_styles' => false,
            'auto_apply_enabled' => false,
            'diagnostics_enabled' => true,
            'author_name' => '',
            'author_email' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function sanitize(array $input): PushPullSettings
    {
        $existing = $this->get();
        $providerKey = sanitize_key((string) ($input['provider_key'] ?? $existing->providerKey));
        $allowedProviders = ['github', 'gitlab', 'bitbucket'];

        if (! in_array($providerKey, $allowedProviders, true)) {
            $providerKey = 'github';
        }

        $apiToken = isset($input['api_token']) ? trim((string) $input['api_token']) : '';
        if ($apiToken === '') {
            $apiToken = $existing->apiToken;
        }

        return PushPullSettings::fromArray([
            'provider_key' => $providerKey,
            'owner_or_workspace' => sanitize_text_field((string) ($input['owner_or_workspace'] ?? '')),
            'repository' => sanitize_text_field((string) ($input['repository'] ?? '')),
            'branch' => sanitize_text_field((string) ($input['branch'] ?? 'main')),
            'api_token' => $apiToken,
            'base_url' => esc_url_raw((string) ($input['base_url'] ?? '')),
            'manage_generateblocks_global_styles' => ! empty($input['manage_generateblocks_global_styles']),
            'auto_apply_enabled' => ! empty($input['auto_apply_enabled']),
            'diagnostics_enabled' => ! array_key_exists('diagnostics_enabled', $input) || ! empty($input['diagnostics_enabled']),
            'author_name' => sanitize_text_field((string) ($input['author_name'] ?? '')),
            'author_email' => sanitize_email((string) ($input['author_email'] ?? '')),
        ]);
    }
}
