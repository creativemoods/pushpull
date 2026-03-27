<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\ManagedSetDiffService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Merge\JsonThreeWayMerger;
use PushPull\Domain\Merge\ManagedSetMergeService;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Push\RemoteBranchResetService;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\LocalSyncService;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\SettingsRepository;

final class LocalSyncServicePullTest extends TestCase
{
    public function testPullFetchesAndFastForwardsWhenRemoteExistsAndLocalIsEmpty(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new InMemoryProvider();
        $provider->refs['refs/heads/main'] = new \PushPull\Provider\RemoteRef('refs/heads/main', 'commit-1');
        $provider->commits['commit-1'] = new \PushPull\Provider\RemoteCommit('commit-1', 'tree-1', [], 'Initial remote commit');
        $provider->trees['tree-1'] = new \PushPull\Provider\RemoteTree('tree-1', [
            ['path' => 'generateblocks/global-styles/manifest.json', 'type' => 'blob', 'hash' => 'blob-1'],
        ]);
        $provider->blobs['blob-1'] = new \PushPull\Provider\RemoteBlob('blob-1', "{\n  \"schemaVersion\": 1,\n  \"type\": \"generateblocks_global_styles_manifest\",\n  \"orderedLogicalKeys\": []\n}\n");

        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'owner',
            'repository' => 'repo',
            'branch' => 'main',
            'api_token' => 'token',
            'manage_generateblocks_global_styles' => '1',
        ]));

        $workingStateRepository = new WorkingStateRepository($wpdb);
        $providerFactory = new InMemoryProviderFactory($provider);
        $registry = new ManagedSetRegistry([$adapter]);
        $syncService = new LocalSyncService(
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

        $result = $syncService->pull('generateblocks_global_styles');

        self::assertSame('commit-1', $result->fetchResult->remoteCommitHash);
        self::assertSame('fast_forward', $result->mergeResult->status);
        self::assertSame('commit-1', $repository->getRef('refs/remotes/origin/main')?->commitHash);
        self::assertSame('commit-1', $repository->getRef('refs/heads/main')?->commitHash);
    }

    public function testCommitRequiresFetchFirstWhenRemoteBranchAlreadyHasHistory(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $provider = new InMemoryProvider();
        $provider->refs['refs/heads/main'] = new \PushPull\Provider\RemoteRef('refs/heads/main', 'commit-1');
        $provider->commits['commit-1'] = new \PushPull\Provider\RemoteCommit('commit-1', 'tree-1', [], 'Initial remote commit');
        $provider->trees['tree-1'] = new \PushPull\Provider\RemoteTree('tree-1', []);

        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'owner',
            'repository' => 'repo',
            'branch' => 'main',
            'api_token' => 'token',
            'manage_generateblocks_global_styles' => '1',
        ]));

        $workingStateRepository = new WorkingStateRepository($wpdb);
        $providerFactory = new InMemoryProviderFactory($provider);
        $registry = new ManagedSetRegistry([$adapter]);
        $syncService = new LocalSyncService(
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Remote branch main already has commits. Fetch it before creating the first local commit.');

        $syncService->commitManagedSet(
            'generateblocks_global_styles',
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );
    }
}
