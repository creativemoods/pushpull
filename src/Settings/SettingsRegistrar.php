<?php

declare(strict_types=1);

namespace PushPull\Settings;

use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\OverlayManagedSetInterface;

final class SettingsRegistrar
{
    public const SETTINGS_GROUP = 'pushpull_settings';
    public const SETTINGS_PAGE_SLUG = 'pushpull-settings';

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly ManagedSetRegistry $managedSetRegistry
    ) {
    }

    public function register(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            SettingsRepository::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
                'default' => $this->settingsRepository->defaults()->toArray(),
                'show_in_rest' => false,
            ]
        );

        add_settings_section(
            'pushpull_provider',
            __('Provider Selection', 'pushpull'),
            static function (): void {
                echo '<p>' . esc_html__('Select the remote Git provider and repository coordinates.', 'pushpull') . '</p>';
                echo '<p class="description">' . esc_html__('GitLab currently linearizes pushed merge results into normal commits instead of preserving merge topology on the remote branch.', 'pushpull') . '</p>';
            },
            self::SETTINGS_PAGE_SLUG
        );

        add_settings_section(
            'pushpull_remote',
            __('Remote Repository Settings', 'pushpull'),
            static function (): void {
                echo '<p>Configure the branch and repository location for the managed content set.</p>';
            },
            self::SETTINGS_PAGE_SLUG
        );

        add_settings_section(
            'pushpull_auth',
            __('Authentication', 'pushpull'),
            static function (): void {
                echo '<p>' . esc_html__('Store the provider token here. The field remains masked after save.', 'pushpull') . '</p>';
                echo '<p class="description">' . esc_html__('For GitLab fine-grained personal access tokens, grant Project: Read, Branch: Read, Commit: Read, Commit: Create, and Repository: Read.', 'pushpull') . '</p>';
            },
            self::SETTINGS_PAGE_SLUG
        );

        add_settings_section(
            'pushpull_managed_sets',
            __('Managed Content Sets', 'pushpull'),
            static function (): void {
                echo '<p>Enable the content domains that PushPull should manage and serialize into the repository.</p>';
            },
            self::SETTINGS_PAGE_SLUG
        );

        add_settings_section(
            'pushpull_sync',
            __('Sync Behavior Options', 'pushpull'),
            static function (): void {
                echo '<p>These options shape later workflow slices and are currently informational.</p>';
            },
            self::SETTINGS_PAGE_SLUG
        );

        $this->registerField('pushpull_provider', 'provider_key', __('Git provider', 'pushpull'));
        $this->registerField('pushpull_remote', 'owner_or_workspace', __('Owner / workspace / group', 'pushpull'));
        $this->registerField('pushpull_remote', 'repository', __('Repository name', 'pushpull'));
        $this->registerField('pushpull_remote', 'branch', __('Branch', 'pushpull'));
        $this->registerField('pushpull_auth', 'api_token', __('API token', 'pushpull'));
        $this->registerField('pushpull_auth', 'base_url', __('Base URL', 'pushpull'));
        $this->registerField('pushpull_managed_sets', 'enabled_managed_sets', __('Enabled managed sets', 'pushpull'));
        $this->registerField('pushpull_sync', 'author_name', __('Commit author name', 'pushpull'));
        $this->registerField('pushpull_sync', 'author_email', __('Commit author email', 'pushpull'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitizeSettings(array $input): array
    {
        return $this->settingsRepository->sanitize($input)->toArray();
    }

    private function registerField(string $section, string $field, string $label): void
    {
        add_settings_field(
            'pushpull_' . $field,
            $label,
            function () use ($field): void {
                $settings = $this->settingsRepository->get();
                $value = $settings->toArray()[$field] ?? null;
                $name = SettingsRepository::OPTION_KEY . '[' . $field . ']';

                switch ($field) {
                    case 'provider_key':
                        $this->renderSelect($name, (string) $value, [
                            'github' => 'GitHub',
                            'gitlab' => 'GitLab',
                            'bitbucket' => 'Bitbucket',
                        ]);
                        break;

                    case 'api_token':
                        $this->renderPassword($name, $settings->maskedApiToken());
                        break;

                    case 'enabled_managed_sets':
                        $this->renderManagedSetCheckboxes(SettingsRepository::OPTION_KEY . '[enabled_managed_sets][]', $settings);
                        break;

                    case 'author_email':
                        $this->renderInput($name, (string) $value, 'email', 'name@example.com');
                        break;

                    case 'base_url':
                        $this->renderInput($name, (string) $value, 'url', 'https://gitlab.example.com');
                        break;

                    default:
                        $this->renderInput($name, (string) $value);
                        break;
                }
            },
            self::SETTINGS_PAGE_SLUG,
            $section
        );
    }

    /**
     * @param array<string, string> $options
     */
    private function renderSelect(string $name, string $value, array $options): void
    {
        printf('<select class="regular-text" name="%s">', esc_attr($name));

        foreach ($options as $optionValue => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($optionValue),
                selected($value, $optionValue, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    private function renderPassword(string $name, string $caption): void
    {
        printf(
            '<input class="regular-text" type="password" name="%s" value="" autocomplete="new-password" />',
            esc_attr($name)
        );

        if ($caption !== '') {
            printf('<p class="description">%s. Leave blank to keep it.</p>', esc_html($caption));
            return;
        }

        echo '<p class="description">Leave blank until you have a provider token.</p>';
    }

    private function renderManagedSetCheckboxes(string $name, PushPullSettings $settings): void
    {
        $primary = [];
        $overlay = [];

        foreach ($this->managedSetRegistry->allInDependencyOrder() as $managedSetKey => $adapter) {
            $target = $adapter instanceof OverlayManagedSetInterface && $adapter->isOverlayManagedSet()
                ? 'overlay'
                : 'primary';

            if ($target === 'overlay') {
                $overlay[$managedSetKey] = $adapter->getManagedSetLabel();
            } else {
                $primary[$managedSetKey] = $adapter->getManagedSetLabel();
            }
        }

        if ($primary !== []) {
            echo '<p><strong>' . esc_html__('Primary domains', 'pushpull') . '</strong></p>';
            foreach ($primary as $managedSetKey => $label) {
                printf(
                    '<label><input type="checkbox" name="%s" value="%s" %s /> %s</label><br />',
                    esc_attr($name),
                    esc_attr($managedSetKey),
                    checked($settings->isManagedSetEnabled($managedSetKey), true, false),
                    esc_html($label)
                );
            }
        }

        if ($overlay !== []) {
            echo '<p><strong>' . esc_html__('Overlay domains', 'pushpull') . '</strong></p>';
            foreach ($overlay as $managedSetKey => $label) {
                printf(
                    '<label><input type="checkbox" name="%s" value="%s" %s /> %s</label><br />',
                    esc_attr($name),
                    esc_attr($managedSetKey),
                    checked($settings->isManagedSetEnabled($managedSetKey), true, false),
                    esc_html($label)
                );
            }
        }
    }

    private function renderInput(string $name, string $value, string $type = 'text', string $placeholder = ''): void
    {
        printf(
            '<input class="regular-text" type="%s" name="%s" value="%s" placeholder="%s" />',
            esc_attr($type),
            esc_attr($name),
            esc_attr($value),
            esc_attr($placeholder)
        );
    }
}
