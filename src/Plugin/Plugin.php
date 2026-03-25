<?php

declare(strict_types=1);

namespace PushPull\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

use PushPull\Admin\ManagedContentPage;
use PushPull\Admin\OperationsPage;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Admin\SettingsPage;
use PushPull\Domain\Apply\ManagedSetApplyService;
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
use PushPull\Domain\Sync\GenerateBlocksRepositoryCommitter;
use PushPull\Domain\Sync\RemoteRepositoryInitializer;
use PushPull\Domain\Sync\LocalSyncService;
use PushPull\Persistence\Migrations\SchemaMigrator;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Provider\GitProviderFactory;
use PushPull\Settings\SettingsRegistrar;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Operations\OperationExecutor;
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
        $operationExecutor = new OperationExecutor($operationLogRepository, new OperationLockService());
        $generateBlocksAdapter = new GenerateBlocksGlobalStylesAdapter();
        $workingStateRepository = new WorkingStateRepository($wpdb);
        $contentMapRepository = new ContentMapRepository($wpdb);
        $diffService = new ManagedSetDiffService(
            $generateBlocksAdapter,
            new RepositoryStateReader($localRepository),
            $localRepository
        );
        $mergeService = new ManagedSetMergeService(
            $localRepository,
            new RepositoryStateReader($localRepository),
            new JsonThreeWayMerger(),
            $workingStateRepository
        );
        $conflictResolutionService = new ManagedSetConflictResolutionService(
            $localRepository,
            $workingStateRepository
        );
        $applyService = new ManagedSetApplyService(
            $generateBlocksAdapter,
            new RepositoryStateReader($localRepository),
            $contentMapRepository,
            $workingStateRepository
        );
        $pushService = new ManagedSetPushService($localRepository, $providerFactory);
        $remoteBranchResetService = new RemoteBranchResetService($localRepository, $providerFactory);
        $syncService = new LocalSyncService(
            $generateBlocksAdapter,
            new GenerateBlocksRepositoryCommitter($localRepository, $generateBlocksAdapter),
            $diffService,
            $mergeService,
            $applyService,
            $pushService,
            $remoteBranchResetService,
            $localRepository,
            $settingsRepository,
            $providerFactory
        );
        $settingsRegistrar = new SettingsRegistrar($settingsRepository);
        $settingsPage = new SettingsPage($settingsRepository, $providerFactory, $localRepositoryResetService, $remoteRepositoryInitializer, $operationExecutor);
        $operationsPage = new OperationsPage($operationLogRepository);
        $managedContentPage = new ManagedContentPage(
            $settingsRepository,
            $localRepository,
            $generateBlocksAdapter,
            $syncService,
            $workingStateRepository,
            $conflictResolutionService,
            $operationExecutor
        );

        add_action('admin_init', [$settingsRegistrar, 'register']);
        add_action('admin_menu', [$settingsPage, 'register']);
        add_action('admin_menu', [$operationsPage, 'register']);
        add_action('admin_menu', [$managedContentPage, 'register']);
        add_action('admin_post_pushpull_test_connection', [$settingsPage, 'handleTestConnection']);
        add_action('admin_post_pushpull_reset_local_repository', [$settingsPage, 'handleResetLocalRepository']);
        add_action('admin_post_pushpull_initialize_remote_repository', [$settingsPage, 'handleInitializeRemoteRepository']);
        add_action('admin_post_pushpull_commit_generateblocks', [$managedContentPage, 'handleCommit']);
        add_action('admin_post_pushpull_fetch_generateblocks', [$managedContentPage, 'handleFetch']);
        add_action('admin_post_pushpull_merge_generateblocks', [$managedContentPage, 'handleMerge']);
        add_action('admin_post_pushpull_apply_generateblocks', [$managedContentPage, 'handleApply']);
        add_action('admin_post_pushpull_push_generateblocks', [$managedContentPage, 'handlePush']);
        add_action('admin_post_pushpull_reset_remote_generateblocks', [$managedContentPage, 'handleResetRemote']);
        add_action('admin_post_pushpull_resolve_conflict_generateblocks', [$managedContentPage, 'handleResolveConflict']);
        add_action('admin_post_pushpull_finalize_merge_generateblocks', [$managedContentPage, 'handleFinalizeMerge']);
        add_action('admin_enqueue_scripts', [$settingsPage, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$operationsPage, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$managedContentPage, 'enqueueAssets']);
    }
}
