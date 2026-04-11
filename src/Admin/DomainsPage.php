<?php

declare(strict_types=1);

namespace PushPull\Admin;

use PushPull\Content\ConfigManagedSetInterface;
use PushPull\Content\Discovery\WordPressDomainDiscovery;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Content\OverlayManagedSetInterface;
use PushPull\Content\WordPress\GenericWordPressCustomPostTypeAdapter;
use PushPull\Content\WordPress\GenericWordPressCustomTaxonomyAdapter;
use PushPull\Settings\PushPullSettings;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Capabilities;

final class DomainsPage
{
    public const MENU_SLUG = 'pushpull-domains';
    private const SAVE_ACTION = 'pushpull_save_domains';

    /**
     * @var array<string, array{label: string, source: string}>
     */
    private const SUPPORTED_PLUGIN_PLACEHOLDERS = [
        'polylang' => ['label' => 'Polylang', 'source' => 'plugin'],
        'translatepress' => ['label' => 'TranslatePress', 'source' => 'plugin'],
    ];

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly ManagedSetRegistry $managedSetRegistry,
        private readonly WordPressDomainDiscovery $wordPressDomainDiscovery = new WordPressDomainDiscovery()
    ) {
    }

    public function register(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Domains', 'pushpull'),
            __('Domains', 'pushpull'),
            Capabilities::MANAGE_PLUGIN,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'pushpull_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'pushpull-admin',
            PUSHPULL_PLUGIN_URL . 'plugin-assets/css/admin.css',
            [],
            PUSHPULL_VERSION
        );
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        $settings = $this->settingsRepository->get();
        $groups = $this->groupedDomains($settings);
        $customContent = $this->customContentPanels();

        echo '<div class="wrap pushpull-admin">';
        echo '<h1>' . esc_html__('PushPull Domains', 'pushpull') . '</h1>';
        echo '<p class="pushpull-intro">' . esc_html__('Choose what PushPull manages on this site. Domains are grouped first by source, then by role, so WordPress core, installed plugin integrations, and future custom content can scale without turning Settings into one long checkbox list.', 'pushpull') . '</p>';
        $this->renderPrimaryNavigation();

        $notice = $this->notice();
        if ($notice !== null) {
            printf(
                '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pushpull_save_domains" />';
        wp_nonce_field(self::SAVE_ACTION);

        echo '<div class="pushpull-domain-source-grid">';
        $this->renderSourcePanel(
            __('WordPress Core', 'pushpull'),
            __('Core WordPress content, configuration, and future core overlays.', 'pushpull'),
            $groups['core']['primary'],
            $groups['core']['config'],
            $groups['core']['overlay'],
            []
        );
        $this->renderSourcePanel(
            __('Installed Plugin Integrations', 'pushpull'),
            __('Only plugin domains whose integrations are currently available on this site are shown as active. Future plugin slots stay visible in a disabled state so the structure remains predictable as support grows.', 'pushpull'),
            [],
            [],
            [],
            $this->pluginSourcePanels($groups['plugin'])
        );
        $this->renderSourcePanel(
            __('Custom Content', 'pushpull'),
            __('PushPull detects site-specific custom post types and taxonomies here and can manage them through the generic adapter path.', 'pushpull'),
            [],
            [],
            [],
            $customContent
        );
        echo '</div>';

        submit_button(__('Save Domains', 'pushpull'));
        echo '</form>';
        echo '</div>';
    }

    public function handleSave(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::SAVE_ACTION);

        $settings = $this->settingsRepository->get();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified just above.
        $rawEnabledManagedSets = isset($_POST['enabled_managed_sets']) && is_array($_POST['enabled_managed_sets'])
            ? wp_unslash($_POST['enabled_managed_sets']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw array is immediately unslashed and sanitized below.
            : [];
        $enabledManagedSets = is_array($rawEnabledManagedSets)
            ? array_values(array_map(
                static fn (mixed $value): string => sanitize_key(wp_unslash((string) $value)),
                $rawEnabledManagedSets
            ))
            : [];

        $this->settingsRepository->save($this->settingsRepository->sanitize([
            'provider_key' => $settings->providerKey,
            'owner_or_workspace' => $settings->ownerOrWorkspace,
            'repository' => $settings->repository,
            'branch' => $settings->branch,
            'api_token' => $settings->apiToken,
            'base_url' => $settings->baseUrl,
            'fetch_availability_check_interval_minutes' => $settings->fetchAvailabilityCheckIntervalMinutes,
            'enabled_managed_sets' => $enabledManagedSets,
            'auto_apply_enabled' => $settings->autoApplyEnabled,
            'diagnostics_enabled' => $settings->diagnosticsEnabled,
            'author_name' => $settings->authorName,
            'author_email' => $settings->authorEmail,
        ]));

        $url = add_query_arg([
            'page' => self::MENU_SLUG,
            'pushpull_domains_status' => 'success',
            'pushpull_domains_message' => 'Managed domains were updated.',
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private function renderPrimaryNavigation(): void
    {
        echo '<nav class="nav-tab-wrapper wp-clearfix pushpull-page-nav">';
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)),
            esc_html__('Settings', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab nav-tab-active">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)),
            esc_html__('Domains', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG)),
            esc_html__('Managed Content', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . OperationsPage::MENU_SLUG)),
            esc_html__('Audit Log', 'pushpull')
        );
        echo '</nav>';
    }

    /**
     * @return array{
     *   core: array{primary: array<int, array<string, mixed>>, config: array<int, array<string, mixed>>, overlay: array<int, array<string, mixed>>},
     *   plugin: array<string, array{title: string, description: string, sections: array{primary: array<int, array<string, mixed>>, config: array<int, array<string, mixed>>, overlay: array<int, array<string, mixed>>}, disabled: bool}>
     * }
     */
    private function groupedDomains(PushPullSettings $settings): array
    {
        $core = [
            'primary' => [],
            'config' => [],
            'overlay' => [],
        ];
        $plugin = [];

        foreach ($this->managedSetRegistry->allInDependencyOrder() as $managedSetKey => $adapter) {
            $entry = [
                'key' => $managedSetKey,
                'label' => $adapter->getManagedSetLabel(),
                'enabled' => $settings->isManagedSetEnabled($managedSetKey),
                'available' => $adapter->isAvailable(),
            ];
            $role = $this->roleForAdapter($adapter);
            $source = $this->sourceForManagedSet($managedSetKey);

            if ($source['type'] === 'core') {
                $core[$role][] = $entry;
                continue;
            }

            if ($source['type'] === 'custom') {
                continue;
            }

            $pluginKey = $source['key'];
            if (! isset($plugin[$pluginKey])) {
                $plugin[$pluginKey] = [
                    'title' => $source['label'],
                    'description' => $source['description'],
                    'sections' => [
                        'primary' => [],
                        'config' => [],
                        'overlay' => [],
                    ],
                    'disabled' => false,
                ];
            }

            $plugin[$pluginKey]['sections'][$role][] = $entry;
        }

        foreach (self::SUPPORTED_PLUGIN_PLACEHOLDERS as $pluginKey => $pluginSource) {
            if (isset($plugin[$pluginKey])) {
                continue;
            }

            $plugin[$pluginKey] = [
                'title' => $pluginSource['label'],
                'description' => __('This integration area becomes active when the plugin is installed and PushPull support is present.', 'pushpull'),
                'sections' => [
                    'primary' => [],
                    'config' => [],
                    'overlay' => [],
                ],
                'disabled' => true,
            ];
        }

        return [
            'core' => $core,
            'plugin' => $plugin,
        ];
    }

    /**
     * @param array<string, array{title: string, description: string, sections: array{primary: array<int, array<string, mixed>>, config: array<int, array<string, mixed>>, overlay: array<int, array<string, mixed>>}, disabled: bool}> $plugins
     * @return array<int, array{title: string, description: string, sections: array{primary: array<int, array<string, mixed>>, config: array<int, array<string, mixed>>, overlay: array<int, array<string, mixed>>}, disabled: bool}>
     */
    private function pluginSourcePanels(array $plugins): array
    {
        uasort($plugins, static fn (array $left, array $right): int => [$left['disabled'], $left['title']] <=> [$right['disabled'], $right['title']]);

        return array_values($plugins);
    }

    /**
     * @param array<int, array<string, mixed>> $primary
     * @param array<int, array<string, mixed>> $config
     * @param array<int, array<string, mixed>> $overlay
     * @param array<int, array{title: string, description: string, sections: array{primary: array<int, array<string, mixed>>, config: array<int, array<string, mixed>>, overlay: array<int, array<string, mixed>>}, disabled: bool}> $nestedPanels
     */
    private function renderSourcePanel(string $title, string $description, array $primary, array $config, array $overlay, array $nestedPanels): void
    {
        echo '<section class="pushpull-panel pushpull-domain-source-panel">';
        printf('<h2>%s</h2>', esc_html($title));
        printf('<p class="description">%s</p>', esc_html($description));

        if ($primary !== [] || $config !== [] || $overlay !== []) {
            $this->renderDomainRoleSection(__('Primary domains', 'pushpull'), $primary, false, __('No primary domains in this source yet.', 'pushpull'));
            $this->renderDomainRoleSection(__('Config domains', 'pushpull'), $config, false, __('No config domains in this source yet.', 'pushpull'));
            $this->renderDomainRoleSection(__('Overlay domains', 'pushpull'), $overlay, false, __('No overlay domains in this source yet.', 'pushpull'));
        }

        foreach ($nestedPanels as $panel) {
            $panelClass = $panel['disabled'] ? ' pushpull-domain-group--disabled' : '';
            $expandPanel = $this->panelHasCheckableEntries($panel['sections']);
            printf(
                '<details class="pushpull-domain-group%s"%s>',
                esc_attr($panelClass),
                $expandPanel ? ' open' : ''
            );
            printf('<summary><h3>%s</h3></summary>', esc_html($panel['title']));
            printf('<p class="description">%s</p>', esc_html($panel['description']));
            $this->renderDomainRoleSection(__('Primary domains', 'pushpull'), $panel['sections']['primary'], $panel['disabled'], __('No primary domains detected in this integration yet.', 'pushpull'));
            $this->renderDomainRoleSection(__('Config domains', 'pushpull'), $panel['sections']['config'], $panel['disabled'], __('No config domains detected in this integration yet.', 'pushpull'));
            $this->renderDomainRoleSection(__('Overlay domains', 'pushpull'), $panel['sections']['overlay'], $panel['disabled'], __('No overlay domains detected in this integration yet.', 'pushpull'));
            echo '</details>';
        }

        echo '</section>';
    }

    /**
     * @param array{primary: array<int, array<string, mixed>>, config: array<int, array<string, mixed>>, overlay: array<int, array<string, mixed>>} $sections
     */
    private function panelHasCheckableEntries(array $sections): bool
    {
        foreach (['primary', 'config', 'overlay'] as $role) {
            foreach ($sections[$role] as $entry) {
                if (! empty($entry['available'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function renderDomainRoleSection(string $title, array $entries, bool $disabled, string $emptyMessage): void
    {
        echo '<div class="pushpull-domain-role-section">';
        printf('<h4>%s</h4>', esc_html($title));

        if ($entries === []) {
            printf(
                '<p class="description pushpull-domain-placeholder%s">%s</p>',
                $disabled ? ' is-disabled' : '',
                esc_html($emptyMessage)
            );
            echo '</div>';

            return;
        }

        echo '<div class="pushpull-domain-list">';

        foreach ($entries as $entry) {
            printf(
                '<label class="pushpull-domain-row%s">',
                ! empty($entry['available']) ? '' : ' is-disabled'
            );
            printf(
                '<input type="checkbox" name="enabled_managed_sets[]" value="%s" %s %s />',
                esc_attr((string) $entry['key']),
                checked(! empty($entry['enabled']), true, false),
                disabled($disabled || empty($entry['available']), true, false)
            );
            echo '<span class="pushpull-domain-row__body">';
            printf('<span class="pushpull-domain-row__label">%s</span>', esc_html((string) $entry['label']));

            if (! empty($entry['meta']) && is_string($entry['meta'])) {
                printf(
                    '<span class="pushpull-domain-row__meta">%s</span>',
                    esc_html($entry['meta'])
                );
            } elseif (empty($entry['available'])) {
                printf(
                    '<span class="pushpull-domain-row__meta">%s</span>',
                    esc_html__('Unavailable on this site right now.', 'pushpull')
                );
            }

            echo '</span>';
            echo '</label>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function roleForAdapter(ManifestManagedContentAdapterInterface $adapter): string
    {
        if ($adapter instanceof OverlayManagedSetInterface && $adapter->isOverlayManagedSet()) {
            return 'overlay';
        }

        if ($adapter instanceof ConfigManagedSetInterface && $adapter->isConfigManagedSet()) {
            return 'config';
        }

        return 'primary';
    }

    /**
     * @return array{type: string, key: string, label: string, description: string}
     */
    private function sourceForManagedSet(string $managedSetKey): array
    {
        if (
            str_starts_with($managedSetKey, 'custom_post_type_')
            || str_starts_with($managedSetKey, 'custom_taxonomy_')
        ) {
            return [
                'type' => 'custom',
                'key' => 'custom',
                'label' => 'Custom Content',
                'description' => __('Site-specific content discovered from runtime registration.', 'pushpull'),
            ];
        }

        return match ($managedSetKey) {
            'generateblocks_global_styles', 'generateblocks_conditions' => [
                'type' => 'plugin',
                'key' => 'generateblocks',
                'label' => 'GenerateBlocks',
                'description' => __('GenerateBlocks-managed design and condition domains.', 'pushpull'),
            ],
            'generatepress_elements' => [
                'type' => 'plugin',
                'key' => 'generatepress',
                'label' => 'GeneratePress',
                'description' => __('GeneratePress-managed layout and element domains.', 'pushpull'),
            ],
            'translation_management' => [
                'type' => 'plugin',
                'key' => 'wpml',
                'label' => 'WPML',
                'description' => __('Translation relationship overlays backed by the active translation plugin.', 'pushpull'),
            ],
            'media_organization' => [
                'type' => 'plugin',
                'key' => 'real-media-library',
                'label' => 'Real Media Library',
                'description' => __('Attachment folder overlays backed by Real Media Library.', 'pushpull'),
            ],
            default => [
                'type' => 'core',
                'key' => 'wordpress',
                'label' => 'WordPress',
                'description' => __('Core WordPress content and configuration domains.', 'pushpull'),
            ],
        };
    }

    /**
     * @return array<int, array{title: string, description: string, sections: array{primary: array<int, array<string, mixed>>, config: array<int, array<string, mixed>>, overlay: array<int, array<string, mixed>>}, disabled: bool}>
     */
    private function customContentPanels(): array
    {
        return [
            [
                'title' => __('Discovered Custom Post Types', 'pushpull'),
                'description' => __('Site-specific custom post types detected from runtime registration.', 'pushpull'),
                'disabled' => false,
                'sections' => [
                    'primary' => array_map(
                        fn (array $postType): array => [
                            'key' => GenericWordPressCustomPostTypeAdapter::managedSetKeyForPostType($postType['slug']),
                            'label' => $postType['label'],
                            'enabled' => $this->settingsRepository->get()->isManagedSetEnabled(GenericWordPressCustomPostTypeAdapter::managedSetKeyForPostType($postType['slug'])),
                            'available' => $this->managedSetRegistry->has(GenericWordPressCustomPostTypeAdapter::managedSetKeyForPostType($postType['slug'])),
                            'meta' => sprintf(
                                /* translators: 1: post type slug, 2: yes/no hierarchy hint. */
                                __('Slug: %1$s. Hierarchical: %2$s.', 'pushpull'),
                                $postType['slug'],
                                $postType['hierarchical'] ? __('yes', 'pushpull') : __('no', 'pushpull')
                            ),
                        ],
                        $this->wordPressDomainDiscovery->discoverCustomPostTypes()
                    ),
                    'config' => [],
                    'overlay' => [],
                ],
            ],
            [
                'title' => __('Discovered Custom Taxonomies', 'pushpull'),
                'description' => __('Site-specific custom taxonomies detected from runtime registration.', 'pushpull'),
                'disabled' => false,
                'sections' => [
                    'primary' => array_map(
                        fn (array $taxonomy): array => [
                            'key' => GenericWordPressCustomTaxonomyAdapter::managedSetKeyForTaxonomy($taxonomy['slug']),
                            'label' => $taxonomy['label'],
                            'enabled' => $this->settingsRepository->get()->isManagedSetEnabled(GenericWordPressCustomTaxonomyAdapter::managedSetKeyForTaxonomy($taxonomy['slug'])),
                            'available' => $this->managedSetRegistry->has(GenericWordPressCustomTaxonomyAdapter::managedSetKeyForTaxonomy($taxonomy['slug'])),
                            'meta' => sprintf(
                                /* translators: 1: taxonomy slug, 2: attached object types, 3: yes/no hierarchy hint. */
                                __('Slug: %1$s. Attached to: %2$s. Hierarchical: %3$s.', 'pushpull'),
                                $taxonomy['slug'],
                                $taxonomy['objectTypes'] !== [] ? implode(', ', $taxonomy['objectTypes']) : __('unknown', 'pushpull'),
                                $taxonomy['hierarchical'] ? __('yes', 'pushpull') : __('no', 'pushpull')
                            ),
                        ],
                        $this->wordPressDomainDiscovery->discoverCustomTaxonomies()
                    ),
                    'config' => [],
                    'overlay' => [],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function notice(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameters from the redirect target.
        $status = isset($_GET['pushpull_domains_status']) ? sanitize_key((string) $_GET['pushpull_domains_status']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameters from the redirect target.
        $message = isset($_GET['pushpull_domains_message']) ? sanitize_text_field(wp_unslash((string) $_GET['pushpull_domains_message'])) : '';

        if ($status === '' || $message === '') {
            return null;
        }

        return [
            'type' => in_array($status, ['success', 'warning'], true) ? $status : 'error',
            'message' => $message,
        ];
    }
}
