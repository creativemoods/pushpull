<?php

declare(strict_types=1);

namespace PushPull\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

use PushPull\Admin\ManagedContentPage;
use PushPull\Admin\OperationsPage;
use PushPull\Admin\AttachmentSyncField;
use PushPull\Admin\AdminBarStatus;
use PushPull\Admin\DomainsPage;
use PushPull\Cli\PushPullCliCommand;
use PushPull\Cli\PushPullConfigCliCommand;
use PushPull\Content\GenerateBlocks\GenerateBlocksConditionsAdapter;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Content\Discovery\WordPressDomainDiscovery;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\Media\RmlMediaOrganizationAdapter;
use PushPull\Admin\SettingsPage;
use PushPull\Content\GenerateBlocks\WordPressBlockPatternsAdapter;
use PushPull\Content\Translation\WpmlTranslationManagementAdapter;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Content\WordPress\WordPressCoreConfigurationAdapter;
use PushPull\Content\WordPress\WordPressCustomCssAdapter;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;
use PushPull\Content\WordPress\WordPressCommentsAdapter;
use PushPull\Content\WordPress\WordPressCategoriesAdapter;
use PushPull\Content\WordPress\WordPressMenusAdapter;
use PushPull\Content\WordPress\WordPressPagesAdapter;
use PushPull\Content\WordPress\WordPressPostsAdapter;
use PushPull\Content\WordPress\WordPressTagsAdapter;
use PushPull\Domain\Apply\ConfigManagedSetApplyService;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Apply\OverlayManagedSetApplyService;
use PushPull\Domain\Diff\ManagedSetDiffService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Merge\ManagedSetConflictResolutionService;
use PushPull\Domain\Merge\ManagedSetMergeService;
use PushPull\Domain\Merge\JsonThreeWayMerger;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Push\RemoteBranchResetService;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\LocalRepositoryResetService;
use PushPull\Persistence\Operations\OperationLogRepository;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\RemoteRepositoryInitializer;
use PushPull\Domain\Sync\LocalSyncService;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\Migrations\SchemaMigrator;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Provider\GitProviderFactory;
use PushPull\Settings\SettingsRegistrar;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Operations\OperationExecutor;
use PushPull\Support\Operations\AsyncBranchOperationRunner;
use PushPull\Support\FetchAvailability\FetchAvailabilityScheduler;
use PushPull\Support\FetchAvailability\FetchAvailabilityService;
use PushPull\Support\Operations\OperationLockService;

final class Plugin
{
    public function boot(): void
    {
        $schemaMigrator = new SchemaMigrator();
        $schemaMigrator->maybeMigrate();

        global $wpdb;

        $settingsRepository = new SettingsRepository();
        $providerFactory = new GitProviderFactory();
        $wordPressDomainDiscovery = new WordPressDomainDiscovery();
        $localRepository = new DatabaseLocalRepository($wpdb);
        $fetchAvailabilityService = new FetchAvailabilityService($settingsRepository, $providerFactory, $localRepository);
        $fetchAvailabilityScheduler = new FetchAvailabilityScheduler($settingsRepository);
        $localRepositoryResetService = new LocalRepositoryResetService($wpdb);
        $remoteRepositoryInitializer = new RemoteRepositoryInitializer($providerFactory, $localRepository);
        $operationLogRepository = new OperationLogRepository($wpdb);
        $operationLockService = new OperationLockService();
        $operationExecutor = new OperationExecutor($operationLogRepository, $operationLockService);
        $generateBlocksStylesAdapter = new GenerateBlocksGlobalStylesAdapter();
        $generateBlocksConditionsAdapter = new GenerateBlocksConditionsAdapter();
        $wordPressBlockPatternsAdapter = new WordPressBlockPatternsAdapter();
        $wordPressAttachmentsAdapter = new WordPressAttachmentsAdapter();
        $wordPressCategoriesAdapter = new WordPressCategoriesAdapter();
        $wordPressCommentsAdapter = new WordPressCommentsAdapter();
        $wordPressCoreConfigurationAdapter = new WordPressCoreConfigurationAdapter();
        $wordPressCustomCssAdapter = new WordPressCustomCssAdapter();
        $generatePressElementsAdapter = new GeneratePressElementsAdapter();
        $wordPressMenusAdapter = new WordPressMenusAdapter();
        $wordPressPagesAdapter = new WordPressPagesAdapter();
        $wordPressPostsAdapter = new WordPressPostsAdapter();
        $wordPressTagsAdapter = new WordPressTagsAdapter();
        $wpmlTranslationManagementAdapter = new WpmlTranslationManagementAdapter($settingsRepository);
        $rmlMediaOrganizationAdapter = new RmlMediaOrganizationAdapter($settingsRepository);
        $workingStateRepository = new WorkingStateRepository($wpdb);
        $contentMapRepository = new ContentMapRepository($wpdb);
        $stateReader = new RepositoryStateReader($localRepository);
        $managedSetRegistry = new ManagedSetRegistry([
            $generateBlocksStylesAdapter,
            $generateBlocksConditionsAdapter,
            $wordPressBlockPatternsAdapter,
            $wordPressAttachmentsAdapter,
            $wordPressCategoriesAdapter,
            $wordPressCommentsAdapter,
            $wordPressCoreConfigurationAdapter,
            $wordPressCustomCssAdapter,
            $generatePressElementsAdapter,
            $wordPressMenusAdapter,
            $wordPressPagesAdapter,
            $wordPressPostsAdapter,
            $wordPressTagsAdapter,
            $rmlMediaOrganizationAdapter,
            $wpmlTranslationManagementAdapter,
        ], [
            static fn () => array_merge(
                $wordPressDomainDiscovery->discoverCustomPostTypeAdapters(),
                $wordPressDomainDiscovery->discoverCustomTaxonomyAdapters()
            ),
        ]);
        $managedSetCommitters = [];
        $managedSetDiffServices = [];
        $managedSetApplyServices = [];

        foreach ($managedSetRegistry->all() as $managedSetKey => $adapter) {
            $managedSetCommitters[$managedSetKey] = new ManagedSetRepositoryCommitter($localRepository, $adapter);
            $managedSetDiffServices[$managedSetKey] = new ManagedSetDiffService($adapter, $stateReader, $localRepository);
            if ($adapter instanceof \PushPull\Content\OverlayManagedContentAdapterInterface) {
                $managedSetApplyServices[$managedSetKey] = new OverlayManagedSetApplyService(
                    $adapter,
                    $stateReader,
                    $workingStateRepository
                );
            } elseif ($adapter instanceof WordPressCoreConfigurationAdapter) {
                $managedSetApplyServices[$managedSetKey] = new ConfigManagedSetApplyService(
                    $adapter,
                    $stateReader,
                    $workingStateRepository
                );
            } else {
                $managedSetApplyServices[$managedSetKey] = new ManagedSetApplyService(
                    $adapter,
                    $stateReader,
                    $contentMapRepository,
                    $workingStateRepository
                );
            }
        }
        $mergeService = new ManagedSetMergeService(
            $localRepository,
            $stateReader,
            new JsonThreeWayMerger(),
            $workingStateRepository
        );
        $conflictResolutionService = new ManagedSetConflictResolutionService(
            $localRepository,
            $workingStateRepository
        );
        $pushService = new ManagedSetPushService($localRepository, $providerFactory);
        $remoteBranchResetService = new RemoteBranchResetService($localRepository, $providerFactory);
        $syncService = new LocalSyncService(
            $managedSetRegistry,
            $managedSetCommitters,
            $managedSetDiffServices,
            $managedSetApplyServices,
            $mergeService,
            $pushService,
            $remoteBranchResetService,
            $localRepository,
            $settingsRepository,
            $providerFactory,
            $stateReader,
            $contentMapRepository,
            $workingStateRepository
        );
        $settingsRegistrar = new SettingsRegistrar($settingsRepository);
        $settingsPage = new SettingsPage(
            $settingsRepository,
            $managedSetRegistry,
            $syncService,
            $providerFactory,
            $localRepositoryResetService,
            $remoteRepositoryInitializer,
            $operationExecutor
        );
        $domainsPage = new DomainsPage(
            $settingsRepository,
            $managedSetRegistry,
            $wordPressDomainDiscovery
        );
        $operationsPage = new OperationsPage($operationLogRepository);
        $adminBarStatus = new AdminBarStatus(
            $settingsRepository,
            $managedSetRegistry,
            $syncService
        );

        add_action('admin_bar_menu', [$adminBarStatus, 'register'], 90);
        add_filter('cron_schedules', [$fetchAvailabilityScheduler, 'registerSchedule']);
        add_action('init', [$fetchAvailabilityScheduler, 'ensureScheduled']);
        add_action(FetchAvailabilityScheduler::CRON_HOOK, [$fetchAvailabilityService, 'checkAndStore']);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('pushpull', new PushPullCliCommand(
                $settingsRepository,
                $managedSetRegistry,
                $syncService,
                $providerFactory,
                $localRepositoryResetService,
                $remoteRepositoryInitializer,
                $conflictResolutionService,
                $fetchAvailabilityService,
                $localRepository
            ));
            \WP_CLI::add_command('pushpull config', new PushPullConfigCliCommand(
                $settingsRepository,
                $managedSetRegistry
            ));
        }

        if (! is_admin()) {
            return;
        }

        $managedContentPage = new ManagedContentPage(
            $settingsRepository,
            $localRepository,
            $managedSetRegistry,
            $syncService,
            $workingStateRepository,
            $conflictResolutionService,
            $operationExecutor,
            new AsyncBranchOperationRunner(
                $operationLogRepository,
                $operationLockService,
                $settingsRepository,
                $localRepository,
                $providerFactory,
                $syncService,
                $managedSetApplyServices,
                $managedSetRegistry,
                $stateReader,
                $contentMapRepository,
                $workingStateRepository
            ),
            $fetchAvailabilityService
        );
        $attachmentSyncField = new AttachmentSyncField();

        add_action('admin_init', [$settingsRegistrar, 'register']);
        add_action('admin_init', [$attachmentSyncField, 'register']);
        add_action('admin_menu', [$settingsPage, 'register']);
        add_action('admin_menu', [$domainsPage, 'register']);
        add_action('admin_menu', [$managedContentPage, 'register']);
        add_action('admin_menu', [$operationsPage, 'register']);
        add_action('admin_post_pushpull_save_domains', [$domainsPage, 'handleSave']);
        add_action('admin_post_pushpull_test_connection', [$settingsPage, 'handleTestConnection']);
        add_action('admin_post_pushpull_reset_local_repository', [$settingsPage, 'handleResetLocalRepository']);
        add_action('admin_post_pushpull_reset_remote_branch', [$settingsPage, 'handleResetRemoteBranch']);
        add_action('admin_post_pushpull_initialize_remote_repository', [$settingsPage, 'handleInitializeRemoteRepository']);
        add_action('admin_post_pushpull_commit_managed_set', [$managedContentPage, 'handleCommit']);
        add_action('admin_post_pushpull_pull_managed_set', [$managedContentPage, 'handlePull']);
        add_action('admin_post_pushpull_fetch_managed_set', [$managedContentPage, 'handleFetch']);
        add_action('admin_post_pushpull_merge_managed_set', [$managedContentPage, 'handleMerge']);
        add_action('admin_post_pushpull_apply_managed_set', [$managedContentPage, 'handleApply']);
        add_action('admin_post_pushpull_push_managed_set', [$managedContentPage, 'handlePush']);
        add_action('admin_post_pushpull_commit_push_all', [$managedContentPage, 'handleCommitPushAll']);
        add_action('admin_post_pushpull_pull_apply_all', [$managedContentPage, 'handlePullApplyAll']);
        add_action('wp_ajax_pushpull_start_branch_action', [$managedContentPage, 'handleAjaxStartBranchAction']);
        add_action('wp_ajax_pushpull_continue_branch_action', [$managedContentPage, 'handleAjaxContinueBranchAction']);
        add_action('admin_post_pushpull_resolve_conflict_managed_set', [$managedContentPage, 'handleResolveConflict']);
        add_action('admin_post_pushpull_finalize_merge_managed_set', [$managedContentPage, 'handleFinalizeMerge']);
        add_action('admin_enqueue_scripts', [$settingsPage, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$domainsPage, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$operationsPage, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$managedContentPage, 'enqueueAssets']);
    }
}
