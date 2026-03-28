<?php

declare(strict_types=1);

namespace PushPull\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

use PushPull\Admin\ManagedContentPage;
use PushPull\Admin\OperationsPage;
use PushPull\Admin\AttachmentSyncField;
use PushPull\Content\GenerateBlocks\GenerateBlocksConditionsAdapter;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Admin\SettingsPage;
use PushPull\Content\GenerateBlocks\WordPressBlockPatternsAdapter;
use PushPull\Content\Translation\WpmlTranslationManagementAdapter;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Content\WordPress\WordPressCustomCssAdapter;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;
use PushPull\Content\WordPress\WordPressPagesAdapter;
use PushPull\Content\WordPress\WordPressPostsAdapter;
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
use PushPull\Support\Operations\OperationLockService;

final class Plugin
{
    public function boot(): void
    {
        $schemaMigrator = new SchemaMigrator();
        $schemaMigrator->maybeMigrate();

        global $wpdb;

        if (! is_admin()) {
            return;
        }

        $settingsRepository = new SettingsRepository();
        $providerFactory = new GitProviderFactory();
        $localRepository = new DatabaseLocalRepository($wpdb);
        $localRepositoryResetService = new LocalRepositoryResetService($wpdb);
        $remoteRepositoryInitializer = new RemoteRepositoryInitializer($providerFactory, $localRepository);
        $operationLogRepository = new OperationLogRepository($wpdb);
        $operationLockService = new OperationLockService();
        $operationExecutor = new OperationExecutor($operationLogRepository, $operationLockService);
        $generateBlocksStylesAdapter = new GenerateBlocksGlobalStylesAdapter();
        $generateBlocksConditionsAdapter = new GenerateBlocksConditionsAdapter();
        $wordPressBlockPatternsAdapter = new WordPressBlockPatternsAdapter();
        $wordPressAttachmentsAdapter = new WordPressAttachmentsAdapter();
        $wordPressCustomCssAdapter = new WordPressCustomCssAdapter();
        $generatePressElementsAdapter = new GeneratePressElementsAdapter();
        $wordPressPagesAdapter = new WordPressPagesAdapter();
        $wordPressPostsAdapter = new WordPressPostsAdapter();
        $wpmlTranslationManagementAdapter = new WpmlTranslationManagementAdapter($settingsRepository);
        $workingStateRepository = new WorkingStateRepository($wpdb);
        $contentMapRepository = new ContentMapRepository($wpdb);
        $stateReader = new RepositoryStateReader($localRepository);
        $managedSetRegistry = new ManagedSetRegistry([
            $generateBlocksStylesAdapter,
            $generateBlocksConditionsAdapter,
            $wordPressBlockPatternsAdapter,
            $wordPressAttachmentsAdapter,
            $wordPressCustomCssAdapter,
            $generatePressElementsAdapter,
            $wordPressPagesAdapter,
            $wordPressPostsAdapter,
            $wpmlTranslationManagementAdapter,
        ]);
        $managedSetCommitters = [];
        $managedSetDiffServices = [];
        $managedSetApplyServices = [];

        foreach ($managedSetRegistry->all() as $managedSetKey => $adapter) {
            $managedSetCommitters[$managedSetKey] = new ManagedSetRepositoryCommitter($localRepository, $adapter);
            $managedSetDiffServices[$managedSetKey] = new ManagedSetDiffService($adapter, $stateReader, $localRepository);
            if ($adapter instanceof WpmlTranslationManagementAdapter) {
                $managedSetApplyServices[$managedSetKey] = new OverlayManagedSetApplyService(
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
            $providerFactory
        );
        $settingsRegistrar = new SettingsRegistrar($settingsRepository, $managedSetRegistry);
        $settingsPage = new SettingsPage(
            $settingsRepository,
            $managedSetRegistry,
            $syncService,
            $providerFactory,
            $localRepositoryResetService,
            $remoteRepositoryInitializer,
            $operationExecutor
        );
        $operationsPage = new OperationsPage($operationLogRepository);
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
                $managedSetApplyServices
            )
        );
        $attachmentSyncField = new AttachmentSyncField();

        add_action('admin_init', [$settingsRegistrar, 'register']);
        add_action('admin_init', [$attachmentSyncField, 'register']);
        add_action('admin_menu', [$settingsPage, 'register']);
        add_action('admin_menu', [$managedContentPage, 'register']);
        add_action('admin_menu', [$operationsPage, 'register']);
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
        add_action('wp_ajax_pushpull_start_branch_action', [$managedContentPage, 'handleAjaxStartBranchAction']);
        add_action('wp_ajax_pushpull_continue_branch_action', [$managedContentPage, 'handleAjaxContinueBranchAction']);
        add_action('admin_post_pushpull_resolve_conflict_managed_set', [$managedContentPage, 'handleResolveConflict']);
        add_action('admin_post_pushpull_finalize_merge_managed_set', [$managedContentPage, 'handleFinalizeMerge']);
        add_action('admin_enqueue_scripts', [$settingsPage, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$operationsPage, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$managedContentPage, 'enqueueAssets']);
    }
}
