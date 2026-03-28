<?php

declare(strict_types=1);

namespace PushPull\Admin;

use PushPull\Content\ManagedSetRegistry;
use PushPull\Persistence\LocalRepositoryResetService;
use PushPull\Persistence\Migrations\SchemaMigrator;
use PushPull\Domain\Sync\RemoteRepositoryInitializer;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Provider\Exception\ProviderException;
use PushPull\Provider\Exception\UnsupportedProviderException;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Settings\SettingsRegistrar;
use PushPull\Settings\PushPullSettings;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Capabilities;
use PushPull\Support\Operations\OperationExecutor;

final class SettingsPage
{
    public const MENU_SLUG = 'pushpull-settings';
    private const TEST_CONNECTION_ACTION = 'pushpull_test_connection';
    private const RESET_LOCAL_REPOSITORY_ACTION = 'pushpull_reset_local_repository';
    private const RESET_REMOTE_BRANCH_ACTION = 'pushpull_reset_remote_branch';
    private const INITIALIZE_REMOTE_REPOSITORY_ACTION = 'pushpull_initialize_remote_repository';

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly ManagedSetRegistry $managedSetRegistry,
        private readonly SyncServiceInterface $syncService,
        private readonly GitProviderFactoryInterface $providerFactory,
        private readonly LocalRepositoryResetService $localRepositoryResetService,
        private readonly RemoteRepositoryInitializer $remoteRepositoryInitializer,
        private readonly OperationExecutor $operationExecutor
    ) {
    }

    public function register(): void
    {
        add_menu_page(
            __('PushPull', 'pushpull'),
            __('PushPull', 'pushpull'),
            Capabilities::MANAGE_PLUGIN,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-cloud-saved'
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'pushpull'),
            __('Settings', 'pushpull'),
            Capabilities::MANAGE_PLUGIN,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_' . self::MENU_SLUG) {
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

        echo '<div class="wrap pushpull-admin">';
        echo '<h1>' . esc_html__('PushPull Settings', 'pushpull') . '</h1>';
        echo '<p class="pushpull-intro">' . esc_html__('Configure the remote provider, repository, branch, and managed content settings that drive PushPull fetch, merge, apply, and push workflows.', 'pushpull') . '</p>';
        $this->renderPrimaryNavigation();
        $notice = $this->notice();
        if ($notice !== null) {
            printf(
                '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
        }

        settings_errors(SettingsRepository::OPTION_KEY);

        echo '<div class="pushpull-layout">';
        echo '<div class="pushpull-main">';
        echo '<form action="options.php" method="post">';
        settings_fields(SettingsRegistrar::SETTINGS_GROUP);
        do_settings_sections(SettingsRegistrar::SETTINGS_PAGE_SLUG);
        submit_button(__('Save Settings', 'pushpull'));
        echo '</form>';
        echo '</div>';

        echo '<aside class="pushpull-sidebar">';
        $this->renderConnectionActions();
        $this->renderDangerZone();
        $this->renderCurrentSummary($settings);
        $this->renderProviderStatus($settings);
        echo '</aside>';
        echo '</div>';
        echo '</div>';
    }

    private function renderPrimaryNavigation(): void
    {
        echo '<nav class="nav-tab-wrapper wp-clearfix pushpull-page-nav">';
        printf(
            '<a href="%s" class="nav-tab nav-tab-active">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)),
            esc_html__('Settings', 'pushpull')
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

    private function renderConnectionActions(): void
    {
        echo '<div class="pushpull-panel">';
        echo '<h2>' . esc_html__('Connection Actions', 'pushpull') . '</h2>';
        echo '<p>' . esc_html__('Use this to verify that the configured provider, repository, token, and branch are reachable before running fetch or push workflows.', 'pushpull') . '</p>';
        echo '<div class="pushpull-button-grid">';
        $this->renderTestConnectionButton();
        if ($this->shouldOfferRemoteInitialization()) {
            $this->renderInitializeRemoteButton();
        }
        echo '</div>';
        echo '</div>';
    }

    private function renderDangerZone(): void
    {
        echo '<div class="pushpull-panel">';
        echo '<h2>' . esc_html__('Repository Reset', 'pushpull') . '</h2>';
        echo '<p>' . esc_html__('This clears PushPull local repository state, fetched objects, refs, and conflicts while keeping your saved configuration, operation history, live WordPress content, and remote repository untouched.', 'pushpull') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return window.confirm(\'Reset the local PushPull repository state? This keeps settings but removes all local commits, fetch data, and conflicts.\');">';
        echo '<input type="hidden" name="action" value="pushpull_reset_local_repository" />';
        wp_nonce_field(self::RESET_LOCAL_REPOSITORY_ACTION);
        submit_button(__('Reset local repository', 'pushpull'), 'delete', 'submit', false);
        echo '</form>';
        echo '<hr />';
        echo '<p>' . esc_html__('This resets the configured remote branch by creating one new commit that removes all tracked files from the branch. It does not rewrite Git history.', 'pushpull') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return window.confirm(\'Reset the remote branch to an empty commit? This will not delete Git history, but it will create one new remote commit that removes all tracked files from the branch.\');">';
        echo '<input type="hidden" name="action" value="pushpull_reset_remote_branch" />';
        wp_nonce_field(self::RESET_REMOTE_BRANCH_ACTION);
        submit_button(__('Reset remote branch', 'pushpull'), 'delete', 'submit', false);
        echo '</form>';
        echo '</div>';
    }

    private function renderCurrentSummary(PushPullSettings $settings): void
    {
        echo '<div class="pushpull-panel">';
        echo '<h2>' . esc_html__('Current Summary', 'pushpull') . '</h2>';
        echo '<dl class="pushpull-summary">';
        printf('<dt>%s</dt><dd>%s</dd>', esc_html__('Provider', 'pushpull'), esc_html($settings->providerKey));
        printf('<dt>%s</dt><dd>%s</dd>', esc_html__('Repository', 'pushpull'), esc_html(trim($settings->ownerOrWorkspace . '/' . $settings->repository, '/')));
        printf('<dt>%s</dt><dd>%s</dd>', esc_html__('Branch', 'pushpull'), esc_html($settings->branch));
        $managedSets = [];
        if ($settings->isManagedSetEnabled('generateblocks_global_styles')) {
            $managedSets[] = 'GenerateBlocks global styles';
        }
        if ($settings->isManagedSetEnabled('generateblocks_conditions')) {
            $managedSets[] = 'GenerateBlocks conditions';
        }
        if ($settings->isManagedSetEnabled('wordpress_block_patterns')) {
            $managedSets[] = 'WordPress block patterns';
        }
        if ($settings->isManagedSetEnabled('wordpress_attachments')) {
            $managedSets[] = 'WordPress attachments';
        }
        if ($settings->isManagedSetEnabled('wordpress_custom_css')) {
            $managedSets[] = 'WordPress custom CSS';
        }
        if ($settings->isManagedSetEnabled('generatepress_elements')) {
            $managedSets[] = 'GeneratePress elements';
        }
        if ($settings->isManagedSetEnabled('wordpress_pages')) {
            $managedSets[] = 'WordPress pages';
        }
        if ($settings->isManagedSetEnabled('wordpress_posts')) {
            $managedSets[] = 'WordPress posts';
        }
        printf('<dt>%s</dt><dd>%s</dd>', esc_html__('Managed sets', 'pushpull'), esc_html($managedSets !== [] ? implode(', ', $managedSets) : 'Not enabled'));
        printf('<dt>%s</dt><dd>%s</dd>', esc_html__('Token', 'pushpull'), esc_html($settings->maskedApiToken() !== '' ? $settings->maskedApiToken() : 'Not stored'));
        printf('<dt>%s</dt><dd>%s</dd>', esc_html__('Schema', 'pushpull'), esc_html((new SchemaMigrator())->installedVersion() ?: 'Not installed'));
        echo '</dl>';
        echo '</div>';
    }

    private function renderProviderStatus(PushPullSettings $settings): void
    {
        echo '<div class="pushpull-panel">';
        echo '<h2>' . esc_html__('Provider Status', 'pushpull') . '</h2>';

        try {
            $provider = $this->providerFactory->make($settings->providerKey);
            $validation = $provider->validateConfig(GitRemoteConfig::fromSettings($settings));
            $capabilities = array_keys(array_filter($provider->getCapabilities()->toArray()));

            printf('<p><strong>%s</strong></p>', esc_html($provider->getLabel()));
            printf(
                '<p>%s</p>',
                esc_html($validation->isValid() ? 'Configuration shape looks valid for this provider.' : 'Configuration is incomplete for this provider.')
            );

            if ($capabilities !== []) {
                printf(
                    '<p class="description">%s</p>',
                    esc_html('Declared capabilities: ' . implode(', ', $capabilities))
                );
            }

            foreach ($validation->messages as $message) {
                printf('<p class="description">%s</p>', esc_html($message));
            }
        } catch (UnsupportedProviderException $exception) {
            printf('<p>%s</p>', esc_html($exception->getMessage()));
        }

        echo '</div>';
    }

    public function handleTestConnection(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::TEST_CONNECTION_ACTION);

        $settings = $this->settingsRepository->get();

        try {
            $provider = $this->providerFactory->make($settings->providerKey);
            $result = $provider->testConnection(GitRemoteConfig::fromSettings($settings));
        } catch (ProviderException | UnsupportedProviderException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message);
        }

        $message = $result->emptyRepository
            ? sprintf(
                'Connection successful for %s, but the repository is empty. Initialize branch %s to create the first commit and enable fetch and push.',
                $result->repositoryPath,
                $result->resolvedBranch ?? $settings->branch
            )
            : sprintf(
                'Connection successful for %s. Default branch: %s. Resolved branch: %s.',
                $result->repositoryPath,
                $result->defaultBranch ?? 'n/a',
                $result->resolvedBranch ?? 'n/a'
            );

        $this->redirectWithNotice($result->emptyRepository ? 'warning' : 'success', $message, $result->emptyRepository);
    }

    public function handleResetLocalRepository(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::RESET_LOCAL_REPOSITORY_ACTION);

        try {
            $this->operationExecutor->run('', 'reset_local_repository', [], function (): void {
                $this->localRepositoryResetService->reset();
            });
        } catch (\RuntimeException $exception) {
            $this->redirectWithNotice('error', $exception->getMessage());
        }

        $this->redirectWithNotice(
            'success',
            'Local PushPull repository state was reset. Settings were kept, and live WordPress content was not changed.'
        );
    }

    public function handleInitializeRemoteRepository(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::INITIALIZE_REMOTE_REPOSITORY_ACTION);

        $settings = $this->settingsRepository->get();

        try {
            $result = $this->operationExecutor->run(
                'generateblocks_global_styles',
                'initialize_remote_repository',
                ['branch' => $settings->branch],
                fn () => $this->remoteRepositoryInitializer->initialize('generateblocks_global_styles', $settings)
            );
        } catch (\Throwable $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message);
        }

        $this->redirectWithNotice(
            'success',
            sprintf(
                'Initialized remote branch %s with first commit %s and fetched it into %s.',
                $result->branch,
                $result->remoteCommitHash,
                $result->remoteRefName
            )
        );
    }

    public function handleResetRemoteBranch(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::RESET_REMOTE_BRANCH_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->branchActionManagedSetKey($settings);

        if ($managedSetKey === null) {
            $this->redirectWithNotice('error', 'Enable at least one managed set before resetting the remote branch.');
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'reset_remote_branch',
                ['branch' => $settings->branch],
                fn () => $this->syncService->resetRemote($managedSetKey)
            );
        } catch (\RuntimeException | ProviderException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message);
        }

        $this->redirectWithNotice(
            'success',
            sprintf(
                'Reset remote branch %s to commit %s. The local tracking ref %s was updated.',
                $result->branch,
                $result->remoteCommitHash,
                $result->remoteRefName
            )
        );
    }

    private function renderTestConnectionButton(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pushpull_test_connection" />';
        wp_nonce_field(self::TEST_CONNECTION_ACTION);
        submit_button(__('Test connection', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderInitializeRemoteButton(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return window.confirm(\'Initialize the configured remote repository? PushPull will create the first commit on the configured branch so fetch and push can start working.\');">';
        echo '<input type="hidden" name="action" value="pushpull_initialize_remote_repository" />';
        wp_nonce_field(self::INITIALIZE_REMOTE_REPOSITORY_ACTION);
        submit_button(__('Initialize remote repository', 'pushpull'), 'primary', 'submit', false);
        echo '</form>';
    }

    private function branchActionManagedSetKey(PushPullSettings $settings): ?string
    {
        foreach ($this->managedSetRegistry->all() as $managedSetKey => $_adapter) {
            if ($settings->isManagedSetEnabled($managedSetKey)) {
                return $managedSetKey;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function notice(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameters from the redirect target.
        $status = isset($_GET['pushpull_settings_status']) ? sanitize_key((string) $_GET['pushpull_settings_status']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameters from the redirect target.
        $message = isset($_GET['pushpull_settings_message']) ? sanitize_text_field(wp_unslash((string) $_GET['pushpull_settings_message'])) : '';

        if ($status === '' || $message === '') {
            return null;
        }

        return [
            'type' => in_array($status, ['success', 'warning'], true) ? $status : 'error',
            'message' => $message,
        ];
    }

    private function redirectWithNotice(string $status, string $message, bool $offerRemoteInitialization = false): never
    {
        $queryArgs = [
            'page' => self::MENU_SLUG,
            'pushpull_settings_status' => $status,
            'pushpull_settings_message' => $message,
        ];

        if ($offerRemoteInitialization) {
            $queryArgs['pushpull_offer_remote_initialization'] = '1';
        }

        $url = add_query_arg($queryArgs, admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private function shouldOfferRemoteInitialization(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameters from the redirect target.
        return isset($_GET['pushpull_offer_remote_initialization']) && sanitize_key((string) $_GET['pushpull_offer_remote_initialization']) === '1';
    }
}
