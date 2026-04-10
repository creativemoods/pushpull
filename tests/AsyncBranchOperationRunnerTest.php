<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\ManagedSetDiffService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Merge\JsonThreeWayMerger;
use PushPull\Domain\Merge\ManagedSetMergeService;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Push\RemoteBranchResetService;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\LocalSyncService;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\Operations\OperationLogRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Operations\AsyncBranchOperationRunner;
use PushPull\Support\Operations\OperationLockService;

final class AsyncBranchOperationRunnerTest extends TestCase
{
    public function testChunkedFetchCompletesAndUpdatesTrackingRef(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new AsyncInMemoryProvider();
        $provider->refs['refs/heads/main'] = new \PushPull\Provider\RemoteRef('refs/heads/main', 'commit-1');
        $provider->commits['commit-1'] = new \PushPull\Provider\RemoteCommit('commit-1', 'tree-1', [], 'Initial remote commit');
        $provider->trees['tree-1'] = new \PushPull\Provider\RemoteTree('tree-1', [
            ['path' => 'generateblocks/global-styles/manifest.json', 'type' => 'blob', 'hash' => 'blob-1'],
        ]);
        $provider->blobs['blob-1'] = new \PushPull\Provider\RemoteBlob('blob-1', "{\n  \"schemaVersion\": 1,\n  \"type\": \"generateblocks_global_styles_manifest\",\n  \"orderedLogicalKeys\": []\n}\n");

        $settingsRepository = $this->settingsRepositoryWithStylesEnabled();
        $syncService = $this->buildSyncService($wpdb, $repository, $adapter, $provider, $settingsRepository);
        $runner = new AsyncBranchOperationRunner(
            new OperationLogRepository($wpdb),
            new OperationLockService(),
            $settingsRepository,
            $repository,
            new AsyncInMemoryProviderFactory($provider),
            $syncService,
            [],
            new ManagedSetRegistry([$adapter])
        );

        $started = $runner->start('generateblocks_global_styles', 'fetch');
        $response = null;

        for ($index = 0; $index < 20; $index++) {
            $response = $runner->continue($started['operationId']);

            if ($response['done']) {
                break;
            }
        }

        self::assertNotNull($response);
        self::assertTrue($response['done']);
        self::assertSame('success', $response['status']);
        self::assertSame('indeterminate', $response['progress']['mode']);
        self::assertSame('commit-1', $repository->getRef('refs/remotes/origin/main')?->commitHash);
    }

    public function testChunkedPullFetchesThenFastForwards(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new AsyncInMemoryProvider();
        $provider->refs['refs/heads/main'] = new \PushPull\Provider\RemoteRef('refs/heads/main', 'commit-1');
        $provider->commits['commit-1'] = new \PushPull\Provider\RemoteCommit('commit-1', 'tree-1', [], 'Initial remote commit');
        $provider->trees['tree-1'] = new \PushPull\Provider\RemoteTree('tree-1', [
            ['path' => 'generateblocks/global-styles/manifest.json', 'type' => 'blob', 'hash' => 'blob-1'],
        ]);
        $provider->blobs['blob-1'] = new \PushPull\Provider\RemoteBlob('blob-1', "{\n  \"schemaVersion\": 1,\n  \"type\": \"generateblocks_global_styles_manifest\",\n  \"orderedLogicalKeys\": []\n}\n");

        $settingsRepository = $this->settingsRepositoryWithStylesEnabled();
        $syncService = $this->buildSyncService($wpdb, $repository, $adapter, $provider, $settingsRepository);
        $runner = new AsyncBranchOperationRunner(
            new OperationLogRepository($wpdb),
            new OperationLockService(),
            $settingsRepository,
            $repository,
            new AsyncInMemoryProviderFactory($provider),
            $syncService,
            [],
            new ManagedSetRegistry([$adapter])
        );

        $started = $runner->start('generateblocks_global_styles', 'pull');
        $response = null;

        for ($index = 0; $index < 20; $index++) {
            $response = $runner->continue($started['operationId']);

            if ($response['done']) {
                break;
            }
        }

        self::assertNotNull($response);
        self::assertTrue($response['done']);
        self::assertSame('success', $response['status']);
        self::assertSame('indeterminate', $response['progress']['mode']);
        self::assertSame('commit-1', $repository->getRef('refs/remotes/origin/main')?->commitHash);
        self::assertSame('commit-1', $repository->getRef('refs/heads/main')?->commitHash);
    }

    public function testChunkedPushReportsDeterminateProgressAndUpdatesRefs(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new AsyncInMemoryPushProvider();
        $settingsRepository = $this->settingsRepositoryWithStylesEnabled();
        $repository->importRemoteTree(new \PushPull\Provider\RemoteTree('remote-tree-base', []));
        $repository->importRemoteCommit(new \PushPull\Provider\RemoteCommit('remote-base', 'remote-tree-base', [], 'Base'));
        $repository->updateRef('refs/remotes/origin/main', 'remote-base');
        $repository->updateRef('refs/heads/main', 'remote-base');
        $repository->updateRef('HEAD', 'remote-base');

        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $snapshot = $adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 1,
                'post_title' => '.gbp-section',
                'post_name' => 'gbp-section',
                'post_status' => 'publish',
                'menu_order' => 0,
                'gb_style_selector' => '.gbp-section',
                'gb_style_data' => serialize(['paddingTop' => '7rem']),
                'gb_style_css' => '.gbp-section { color: red; }',
            ],
        ]);
        $commitResult = $committer->commitSnapshot($snapshot, new \PushPull\Domain\Sync\CommitManagedSetRequest('main', 'Local change', 'Jane Doe', 'jane@example.com'));
        $provider->trees['remote-tree-base'] = new \PushPull\Provider\RemoteTree('remote-tree-base', []);
        $provider->commits['remote-base'] = new \PushPull\Provider\RemoteCommit('remote-base', 'remote-tree-base', [], 'Base');
        $provider->refs['refs/heads/main'] = new \PushPull\Provider\RemoteRef('refs/heads/main', 'remote-base');

        $syncService = $this->buildSyncService($wpdb, $repository, $adapter, $provider, $settingsRepository);
        $runner = new AsyncBranchOperationRunner(
            new OperationLogRepository($wpdb),
            new OperationLockService(),
            $settingsRepository,
            $repository,
            new AsyncInMemoryProviderFactory($provider),
            $syncService,
            [],
            new ManagedSetRegistry([$adapter])
        );

        $started = $runner->start('generateblocks_global_styles', 'push');
        self::assertSame('determinate', $started['progress']['mode']);
        $response = null;

        for ($index = 0; $index < 20; $index++) {
            $response = $runner->continue($started['operationId']);

            if ($response['done']) {
                break;
            }
        }

        self::assertNotNull($response);
        self::assertTrue($response['done']);
        self::assertSame('success', $response['status']);
        self::assertSame('determinate', $response['progress']['mode']);
        self::assertSame($provider->refs['refs/heads/main']->commitHash, $repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame($provider->refs['refs/heads/main']->commitHash, $repository->getRef('refs/remotes/origin/main')?->commitHash);
    }

    public function testChunkedPushUsesActualProviderCommitHashWhenUpdateReturnsDifferentHash(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new AsyncInMemoryPushProvider();
        $provider->updatedCommitHashOverride = 'provider-commit-2';
        $settingsRepository = $this->settingsRepositoryWithStylesEnabled();
        $repository->importRemoteTree(new \PushPull\Provider\RemoteTree('remote-tree-base', []));
        $repository->importRemoteCommit(new \PushPull\Provider\RemoteCommit('remote-base', 'remote-tree-base', [], 'Base'));
        $repository->updateRef('refs/remotes/origin/main', 'remote-base');
        $repository->updateRef('refs/heads/main', 'remote-base');
        $repository->updateRef('HEAD', 'remote-base');

        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $snapshot = $adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 1,
                'post_title' => '.gbp-section',
                'post_name' => 'gbp-section',
                'post_status' => 'publish',
                'menu_order' => 0,
                'gb_style_selector' => '.gbp-section',
                'gb_style_data' => serialize(['paddingTop' => '7rem']),
                'gb_style_css' => '.gbp-section { color: red; }',
            ],
        ]);
        $committer->commitSnapshot($snapshot, new \PushPull\Domain\Sync\CommitManagedSetRequest('main', 'Local change', 'Jane Doe', 'jane@example.com'));
        $provider->trees['remote-tree-base'] = new \PushPull\Provider\RemoteTree('remote-tree-base', []);
        $provider->commits['remote-base'] = new \PushPull\Provider\RemoteCommit('remote-base', 'remote-tree-base', [], 'Base');
        $provider->refs['refs/heads/main'] = new \PushPull\Provider\RemoteRef('refs/heads/main', 'remote-base');

        $syncService = $this->buildSyncService($wpdb, $repository, $adapter, $provider, $settingsRepository);
        $runner = new AsyncBranchOperationRunner(
            new OperationLogRepository($wpdb),
            new OperationLockService(),
            $settingsRepository,
            $repository,
            new AsyncInMemoryProviderFactory($provider),
            $syncService,
            [],
            new ManagedSetRegistry([$adapter])
        );

        $started = $runner->start('generateblocks_global_styles', 'push');
        $response = null;

        for ($index = 0; $index < 20; $index++) {
            $response = $runner->continue($started['operationId']);

            if ($response['done']) {
                break;
            }
        }

        self::assertNotNull($response);
        self::assertTrue($response['done']);
        self::assertSame('provider-commit-2', $repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame('provider-commit-2', $repository->getRef('refs/remotes/origin/main')?->commitHash);
        self::assertNotNull($repository->getCommit('provider-commit-2'));
    }

    public function testChunkedApplyProcessesManagedSetAndRedirectsBackToDetailView(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new AsyncInMemoryProvider();
        $settingsRepository = $this->settingsRepositoryWithStylesEnabled();
        $workingStateRepository = new WorkingStateRepository($wpdb);
        $applyService = new ManagedSetApplyService(
            $adapter,
            new RepositoryStateReader($repository),
            new ContentMapRepository($wpdb),
            $workingStateRepository
        );

        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $snapshot = $adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 1,
                'post_title' => '.gbp-section',
                'post_name' => 'gbp-section',
                'post_status' => 'publish',
                'menu_order' => 0,
                'gb_style_selector' => '.gbp-section',
                'gb_style_data' => serialize(['paddingTop' => '7rem']),
                'gb_style_css' => '.gbp-section { color: red; }',
            ],
            [
                'wp_object_id' => 2,
                'post_title' => '.gbp-card',
                'post_name' => 'gbp-card',
                'post_status' => 'publish',
                'menu_order' => 1,
                'gb_style_selector' => '.gbp-card',
                'gb_style_data' => serialize(['borderTopWidth' => '1px']),
                'gb_style_css' => '.gbp-card { border-top-width: 1px; }',
            ],
        ]);
        $committer->commitSnapshot($snapshot, new \PushPull\Domain\Sync\CommitManagedSetRequest('main', 'Local change', 'Jane Doe', 'jane@example.com'));

        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 1;

        $syncService = $this->buildSyncService($wpdb, $repository, $adapter, $provider, $settingsRepository);
        $runner = new AsyncBranchOperationRunner(
            new OperationLogRepository($wpdb),
            new OperationLockService(),
            $settingsRepository,
            $repository,
            new AsyncInMemoryProviderFactory($provider),
            $syncService,
            [$adapter->getManagedSetKey() => $applyService],
            new ManagedSetRegistry([$adapter])
        );

        $started = $runner->start('generateblocks_global_styles', 'apply');
        self::assertSame('determinate', $started['progress']['mode']);
        $response = null;

        for ($index = 0; $index < 20; $index++) {
            $response = $runner->continue($started['operationId']);

            if ($response['done']) {
                break;
            }
        }

        self::assertNotNull($response);
        self::assertTrue($response['done']);
        self::assertSame('success', $response['status']);
        self::assertSame('determinate', $response['progress']['mode']);
        self::assertSame('generateblocks_global_styles', $response['redirectManagedSetKey']);
        self::assertCount(2, $GLOBALS['pushpull_test_generateblocks_posts']);
    }

    public function testCommitPushAllCommitsAndPushesEnabledDomains(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new AsyncInMemoryPushProvider();
        $settingsRepository = $this->settingsRepositoryWithStylesEnabled();
        $repository->importRemoteTree(new \PushPull\Provider\RemoteTree('remote-tree-base', []));
        $repository->importRemoteCommit(new \PushPull\Provider\RemoteCommit('remote-base', 'remote-tree-base', [], 'Base'));
        $repository->updateRef('refs/remotes/origin/main', 'remote-base');
        $repository->updateRef('refs/heads/main', 'remote-base');
        $repository->updateRef('HEAD', 'remote-base');

        $provider->trees['remote-tree-base'] = new \PushPull\Provider\RemoteTree('remote-tree-base', []);
        $provider->commits['remote-base'] = new \PushPull\Provider\RemoteCommit('remote-base', 'remote-tree-base', [], 'Base');
        $provider->refs['refs/heads/main'] = new \PushPull\Provider\RemoteRef('refs/heads/main', 'remote-base');

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(
                1,
                '.gbp-section',
                'gbp-section',
                'publish',
                0,
                'gblocks_styles'
            ),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            1 => [
                'gb_style_selector' => '.gbp-section',
                'gb_style_data' => serialize(['paddingTop' => '7rem']),
                'gb_style_css' => '.gbp-section { color: red; }',
            ],
        ];

        $syncService = $this->buildSyncService($wpdb, $repository, $adapter, $provider, $settingsRepository);
        $runner = new AsyncBranchOperationRunner(
            new OperationLogRepository($wpdb),
            new OperationLockService(),
            $settingsRepository,
            $repository,
            new AsyncInMemoryProviderFactory($provider),
            $syncService,
            [],
            new ManagedSetRegistry([$adapter])
        );

        $started = $runner->start('generateblocks_global_styles', 'commit_push_all');
        self::assertSame('indeterminate', $started['progress']['mode']);
        $response = null;

        for ($index = 0; $index < 30; $index++) {
            $response = $runner->continue($started['operationId']);

            if ($response['done']) {
                break;
            }
        }

        self::assertNotNull($response);
        self::assertTrue($response['done']);
        self::assertSame('success', $response['status']);
        self::assertStringContainsString('Committed 1 changed domain(s)', $response['message']);
        self::assertSame($provider->refs['refs/heads/main']->commitHash, $repository->getRef('refs/heads/main')?->commitHash);
    }

    public function testPullApplyAllPullsAndAppliesEnabledDomains(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new AsyncInMemoryProvider();
        $settingsRepository = $this->settingsRepositoryWithStylesEnabled();
        $workingStateRepository = new WorkingStateRepository($wpdb);
        $applyService = new ManagedSetApplyService(
            $adapter,
            new RepositoryStateReader($repository),
            new ContentMapRepository($wpdb),
            $workingStateRepository
        );

        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $snapshot = $adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 1,
                'post_title' => '.gbp-section',
                'post_name' => 'gbp-section',
                'post_status' => 'publish',
                'menu_order' => 0,
                'gb_style_selector' => '.gbp-section',
                'gb_style_data' => serialize(['paddingTop' => '7rem']),
                'gb_style_css' => '.gbp-section { color: red; }',
            ],
            [
                'wp_object_id' => 2,
                'post_title' => '.gbp-card',
                'post_name' => 'gbp-card',
                'post_status' => 'publish',
                'menu_order' => 1,
                'gb_style_selector' => '.gbp-card',
                'gb_style_data' => serialize(['borderTopWidth' => '1px']),
                'gb_style_css' => '.gbp-card { border-top-width: 1px; }',
            ],
        ]);
        $committer->commitSnapshot($snapshot, new \PushPull\Domain\Sync\CommitManagedSetRequest('main', 'Local change', 'Jane Doe', 'jane@example.com'));
        $localHeadHash = $repository->getRef('refs/heads/main')?->commitHash;
        self::assertIsString($localHeadHash);
        self::assertNotSame('', $localHeadHash);
        $localHeadCommit = $repository->getCommit($localHeadHash);
        self::assertNotNull($localHeadCommit);
        $localHeadTree = $repository->getTree($localHeadCommit->treeHash);
        self::assertNotNull($localHeadTree);
        $provider->commits[$localHeadHash] = new \PushPull\Provider\RemoteCommit(
            $localHeadHash,
            $localHeadCommit->treeHash,
            array_values(array_filter([$localHeadCommit->parentHash, $localHeadCommit->secondParentHash])),
            $localHeadCommit->message
        );
        $provider->trees[$localHeadCommit->treeHash] = new \PushPull\Provider\RemoteTree($localHeadCommit->treeHash, array_map(
            static fn (\PushPull\Domain\Repository\TreeEntry $entry): array => [
                'path' => $entry->path,
                'type' => $entry->type,
                'hash' => $entry->hash,
            ],
            $localHeadTree->entries
        ));
        foreach ($localHeadTree->entries as $entry) {
            if ($entry->type !== 'blob') {
                continue;
            }

            $blob = $repository->getBlob($entry->hash);
            self::assertNotNull($blob);
            $provider->blobs[$entry->hash] = new \PushPull\Provider\RemoteBlob($entry->hash, $blob->content);
        }
        $provider->refs['refs/heads/main'] = new \PushPull\Provider\RemoteRef('refs/heads/main', $localHeadHash);
        $repository->updateRef('refs/remotes/origin/main', $localHeadHash);

        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 1;

        $syncService = $this->buildSyncService($wpdb, $repository, $adapter, $provider, $settingsRepository);
        $runner = new AsyncBranchOperationRunner(
            new OperationLogRepository($wpdb),
            new OperationLockService(),
            $settingsRepository,
            $repository,
            new AsyncInMemoryProviderFactory($provider),
            $syncService,
            [$adapter->getManagedSetKey() => $applyService],
            new ManagedSetRegistry([$adapter])
        );

        $started = $runner->start('generateblocks_global_styles', 'pull_apply_all');
        self::assertSame('indeterminate', $started['progress']['mode']);
        $response = null;

        for ($index = 0; $index < 30; $index++) {
            $response = $runner->continue($started['operationId']);

            if ($response['done']) {
                break;
            }
        }

        self::assertNotNull($response);
        self::assertTrue($response['done']);
        self::assertSame('success', $response['status']);
        self::assertStringContainsString('Applied 1 managed domain(s) to WordPress', $response['message']);
        self::assertCount(2, $GLOBALS['pushpull_test_generateblocks_posts']);
    }

    private function settingsRepositoryWithStylesEnabled(): SettingsRepository
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'owner',
            'repository' => 'repo',
            'branch' => 'main',
            'api_token' => 'token',
            'enabled_managed_sets' => ['generateblocks_global_styles'],
        ]));

        return $settingsRepository;
    }

    private function buildSyncService(
        \wpdb $wpdb,
        DatabaseLocalRepository $repository,
        GenerateBlocksGlobalStylesAdapter $adapter,
        AsyncInMemoryProvider $provider,
        SettingsRepository $settingsRepository
    ): LocalSyncService {
        $workingStateRepository = new WorkingStateRepository($wpdb);
        $providerFactory = new AsyncInMemoryProviderFactory($provider);
        $registry = new ManagedSetRegistry([$adapter]);

        return new LocalSyncService(
            $registry,
            [$adapter->getManagedSetKey() => new ManagedSetRepositoryCommitter($repository, $adapter)],
            [$adapter->getManagedSetKey() => new ManagedSetDiffService($adapter, new RepositoryStateReader($repository), $repository)],
            [$adapter->getManagedSetKey() => new ManagedSetApplyService($adapter, new RepositoryStateReader($repository), new ContentMapRepository($wpdb), $workingStateRepository)],
            new ManagedSetMergeService($repository, new RepositoryStateReader($repository), new JsonThreeWayMerger(), $workingStateRepository),
            new ManagedSetPushService($repository, $providerFactory),
            new RemoteBranchResetService($repository, $providerFactory),
            $repository,
            $settingsRepository,
            $providerFactory
        );
    }
}

final class AsyncInMemoryProviderFactory implements \PushPull\Provider\GitProviderFactoryInterface
{
    public function __construct(private readonly AsyncInMemoryProvider $provider)
    {
    }

    public function make(string $providerKey): \PushPull\Provider\GitProviderInterface
    {
        return $this->provider;
    }
}

class AsyncInMemoryProvider implements \PushPull\Provider\GitProviderInterface
{
    /** @var array<string, \PushPull\Provider\RemoteRef> */
    public array $refs = [];
    /** @var array<string, \PushPull\Provider\RemoteCommit> */
    public array $commits = [];
    /** @var array<string, \PushPull\Provider\RemoteTree> */
    public array $trees = [];
    /** @var array<string, \PushPull\Provider\RemoteBlob> */
    public array $blobs = [];

    public function getKey(): string
    {
        return 'memory';
    }

    public function getLabel(): string
    {
        return 'Memory';
    }

    public function getCapabilities(): \PushPull\Provider\ProviderCapabilities
    {
        return new \PushPull\Provider\ProviderCapabilities(true, true, true, true);
    }

    public function validateConfig(\PushPull\Provider\GitRemoteConfig $config): \PushPull\Provider\ProviderValidationResult
    {
        return new \PushPull\Provider\ProviderValidationResult(true, []);
    }

    public function testConnection(\PushPull\Provider\GitRemoteConfig $config): \PushPull\Provider\ProviderConnectionResult
    {
        return new \PushPull\Provider\ProviderConnectionResult(true, $config->repositoryPath(), 'main', $config->branch, false, []);
    }

    public function getRef(\PushPull\Provider\GitRemoteConfig $config, string $refName): ?\PushPull\Provider\RemoteRef
    {
        return $this->refs[$refName] ?? null;
    }

    public function getDefaultBranch(\PushPull\Provider\GitRemoteConfig $config): ?string
    {
        return 'main';
    }

    public function getCommit(\PushPull\Provider\GitRemoteConfig $config, string $hash): ?\PushPull\Provider\RemoteCommit
    {
        return $this->commits[$hash] ?? null;
    }

    public function getTree(\PushPull\Provider\GitRemoteConfig $config, string $hash): ?\PushPull\Provider\RemoteTree
    {
        return $this->trees[$hash] ?? null;
    }

    public function getBlob(\PushPull\Provider\GitRemoteConfig $config, string $hash): ?\PushPull\Provider\RemoteBlob
    {
        return $this->blobs[$hash] ?? null;
    }

    public function createBlob(\PushPull\Provider\GitRemoteConfig $config, string $content): string
    {
        throw new \RuntimeException('Not used in async branch tests.');
    }

    public function createTree(\PushPull\Provider\GitRemoteConfig $config, array $entries): string
    {
        throw new \RuntimeException('Not used in async branch tests.');
    }

    public function createCommit(\PushPull\Provider\GitRemoteConfig $config, \PushPull\Provider\CreateRemoteCommitRequest $request): string
    {
        throw new \RuntimeException('Not used in async branch tests.');
    }

    public function updateRef(\PushPull\Provider\GitRemoteConfig $config, \PushPull\Provider\UpdateRemoteRefRequest $request): \PushPull\Provider\UpdateRefResult
    {
        throw new \RuntimeException('Not used in async branch tests.');
    }

    public function initializeEmptyRepository(\PushPull\Provider\GitRemoteConfig $config, string $commitMessage): \PushPull\Provider\RemoteRef
    {
        throw new \RuntimeException('Not used in async branch tests.');
    }
}

final class AsyncInMemoryPushProvider extends AsyncInMemoryProvider
{
    public ?string $updatedCommitHashOverride = null;

    public function createBlob(\PushPull\Provider\GitRemoteConfig $config, string $content): string
    {
        $hash = 'remote-blob-' . sha1($content);
        $this->blobs[$hash] = new \PushPull\Provider\RemoteBlob($hash, $content);

        return $hash;
    }

    public function createTree(\PushPull\Provider\GitRemoteConfig $config, array $entries): string
    {
        $hash = 'remote-tree-' . sha1(wp_json_encode($entries));
        $this->trees[$hash] = new \PushPull\Provider\RemoteTree($hash, $entries);

        return $hash;
    }

    public function createCommit(\PushPull\Provider\GitRemoteConfig $config, \PushPull\Provider\CreateRemoteCommitRequest $request): string
    {
        $hash = 'remote-commit-' . sha1($request->treeHash . '|' . implode(',', $request->parentHashes) . '|' . $request->message);
        $this->commits[$hash] = new \PushPull\Provider\RemoteCommit($hash, $request->treeHash, $request->parentHashes, $request->message);

        return $hash;
    }

    public function updateRef(\PushPull\Provider\GitRemoteConfig $config, \PushPull\Provider\UpdateRemoteRefRequest $request): \PushPull\Provider\UpdateRefResult
    {
        $finalCommitHash = $this->updatedCommitHashOverride ?? $request->newCommitHash;
        $this->refs[$request->refName] = new \PushPull\Provider\RemoteRef($request->refName, $finalCommitHash);

        return new \PushPull\Provider\UpdateRefResult(true, $request->refName, $finalCommitHash);
    }
}
