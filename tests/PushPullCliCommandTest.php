<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Cli\PushPullCliCommand;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Domain\Apply\ApplyManagedSetResult;
use PushPull\Domain\Diff\CanonicalDiffResult;
use PushPull\Domain\Diff\CanonicalManagedState;
use PushPull\Domain\Diff\ManagedSetDiffResult;
use PushPull\Domain\Diff\RepositoryRelationship;
use PushPull\Domain\Merge\ManagedSetConflictResolutionService;
use PushPull\Domain\Merge\MergeManagedSetResult;
use PushPull\Domain\Push\PushManagedSetResult;
use PushPull\Domain\Push\RemoteBranchResetService;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\CommitManagedSetResult;
use PushPull\Domain\Sync\FetchManagedSetResult;
use PushPull\Domain\Sync\InitializeRemoteRepositoryResult;
use PushPull\Domain\Sync\PullManagedSetResult;
use PushPull\Domain\Sync\RemoteRepositoryInitializer;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\LocalRepositoryResetService;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\ProviderCapabilities;
use PushPull\Provider\ProviderConnectionResult;
use PushPull\Provider\ProviderValidationResult;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteRef;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\UpdateRefResult;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\FetchAvailability\FetchAvailabilityService;
use RuntimeException;

final class PushPullCliCommandTest extends TestCase
{
    protected function setUp(): void
    {
        \WP_CLI::$lines = [];
        \WP_CLI::$successes = [];
        \WP_CLI::$warnings = [];
        \WP_CLI::$errors = [];
        \WP_CLI::$commands = [];
    }

    public function testDomainsListsEnabledAndAvailableDomains(): void
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
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $registry = new ManagedSetRegistry([$adapter]);
        $command = $this->buildCommand($settingsRepository, $registry, new CliSyncServiceStub());

        $command->domains([], []);

        self::assertNotEmpty(\WP_CLI::$lines);
        self::assertStringContainsString('managed_set', \WP_CLI::$lines[0]);
        self::assertStringContainsString('label', \WP_CLI::$lines[0]);
        self::assertStringContainsString('enabled', \WP_CLI::$lines[0]);
        self::assertStringContainsString('available', \WP_CLI::$lines[0]);
        self::assertStringContainsString('generateblocks_global_styles', \WP_CLI::$lines[1]);
        self::assertStringContainsString('GenerateBlocks global styles', \WP_CLI::$lines[1]);
        self::assertStringContainsString('yes', \WP_CLI::$lines[1]);
    }

    public function testCommitPushAllCommitsEnabledAvailableDomainsAndPushes(): void
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'owner',
            'repository' => 'repo',
            'branch' => 'main',
            'api_token' => 'token',
            'enabled_managed_sets' => ['generateblocks_global_styles'],
            'author_name' => 'Jane Doe',
            'author_email' => 'jane@example.com',
        ]));
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $registry = new ManagedSetRegistry([$adapter]);
        $syncService = new CliSyncServiceStub();
        $syncService->commitResults['generateblocks_global_styles'] = new CommitManagedSetResult(
            'generateblocks_global_styles',
            true,
            null,
            null,
            ['manifest.json' => 'hash-1', 'item.json' => 'hash-2'],
            true
        );
        $syncService->pushResult = new PushManagedSetResult(
            'generateblocks_global_styles',
            'main',
            'pushed',
            'local-1',
            'remote-2',
            ['commit-1'],
            ['tree-1'],
            ['blob-1']
        );
        $command = $this->buildCommand($settingsRepository, $registry, $syncService);

        $command->commitPushAll([], []);

        self::assertCount(1, $syncService->commitRequests);
        self::assertSame('generateblocks_global_styles', $syncService->commitRequests[0]['managedSetKey']);
        self::assertSame('main', $syncService->commitRequests[0]['request']->branch);
        self::assertSame('generateblocks_global_styles', $syncService->pushManagedSetKey);
        self::assertSame(
            'Committed 1 changed domain(s) across 2 file(s) and pushed branch main to remote commit remote-2.',
            \WP_CLI::$successes[0]
        );
    }

    private function buildCommand(
        SettingsRepository $settingsRepository,
        ManagedSetRegistry $registry,
        SyncServiceInterface $syncService
    ): PushPullCliCommand {
        $wpdb = new \wpdb();
        $localRepository = new DatabaseLocalRepository($wpdb);
        $providerFactory = new CliProviderFactoryStub(new CliProviderStub());

        return new PushPullCliCommand(
            $settingsRepository,
            $registry,
            $syncService,
            $providerFactory,
            new LocalRepositoryResetService($wpdb),
            new RemoteRepositoryInitializer($providerFactory, $localRepository),
            new ManagedSetConflictResolutionService($localRepository, new WorkingStateRepository($wpdb)),
            new FetchAvailabilityService($settingsRepository, $providerFactory, $localRepository),
            $localRepository
        );
    }
}

final class CliSyncServiceStub implements SyncServiceInterface
{
    /** @var array<string, CommitManagedSetResult> */
    public array $commitResults = [];
    /** @var array<int, array{managedSetKey: string, request: CommitManagedSetRequest}> */
    public array $commitRequests = [];
    public ?PushManagedSetResult $pushResult = null;
    public ?string $pushManagedSetKey = null;

    public function commitManagedSet(string $managedSetKey, CommitManagedSetRequest $request): CommitManagedSetResult
    {
        $this->commitRequests[] = [
            'managedSetKey' => $managedSetKey,
            'request' => $request,
        ];

        return $this->commitResults[$managedSetKey] ?? new CommitManagedSetResult($managedSetKey, false, null, null, [], true);
    }

    public function fetch(string $managedSetKey): FetchManagedSetResult
    {
        return new FetchManagedSetResult($managedSetKey, 'refs/remotes/origin/main', 'remote-1', [], [], [], [], [], []);
    }

    public function pull(string $managedSetKey): PullManagedSetResult
    {
        return new PullManagedSetResult(
            $managedSetKey,
            'main',
            new FetchManagedSetResult($managedSetKey, 'refs/remotes/origin/main', 'remote-1', [], [], [], [], [], []),
            new MergeManagedSetResult($managedSetKey, 'main', null, null, null, 'already_up_to_date', null, [], [])
        );
    }

    public function diff(string $managedSetKey): ManagedSetDiffResult
    {
        return new ManagedSetDiffResult(
            $managedSetKey,
            new CanonicalManagedState('live', null, null, null, []),
            new CanonicalManagedState('local', null, null, null, []),
            new CanonicalManagedState('remote', null, null, null, []),
            new CanonicalDiffResult([]),
            new CanonicalDiffResult([]),
            new RepositoryRelationship(RepositoryRelationship::IN_SYNC)
        );
    }

    public function merge(string $managedSetKey): MergeManagedSetResult
    {
        return new MergeManagedSetResult($managedSetKey, 'main', null, null, null, 'already_up_to_date', null, [], []);
    }

    public function apply(string $managedSetKey): ApplyManagedSetResult
    {
        return new ApplyManagedSetResult($managedSetKey, 'main', 'commit-1', 0, 0, [], []);
    }

    public function push(string $managedSetKey): PushManagedSetResult
    {
        $this->pushManagedSetKey = $managedSetKey;

        return $this->pushResult ?? new PushManagedSetResult($managedSetKey, 'main', 'already_up_to_date', null, null, [], [], []);
    }

    public function resetRemote(string $managedSetKey): \PushPull\Domain\Push\ResetRemoteBranchResult
    {
        return new \PushPull\Domain\Push\ResetRemoteBranchResult($managedSetKey, 'main', 'old', 'new', 'tree');
    }
}

final class CliProviderFactoryStub implements GitProviderFactoryInterface
{
    public function __construct(private readonly GitProviderInterface $provider)
    {
    }

    public function make(string $providerKey): GitProviderInterface
    {
        return $this->provider;
    }
}

final class CliProviderStub implements GitProviderInterface
{
    public function getKey(): string
    {
        return 'memory';
    }

    public function getLabel(): string
    {
        return 'Memory';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, true, true, true);
    }

    public function validateConfig(\PushPull\Provider\GitRemoteConfig $config): ProviderValidationResult
    {
        return new ProviderValidationResult(true, []);
    }

    public function testConnection(\PushPull\Provider\GitRemoteConfig $config): ProviderConnectionResult
    {
        return new ProviderConnectionResult(true, 'owner/repo', 'main', 'main', false, []);
    }

    public function getRef(\PushPull\Provider\GitRemoteConfig $config, string $refName): ?RemoteRef
    {
        return null;
    }

    public function getDefaultBranch(\PushPull\Provider\GitRemoteConfig $config): ?string
    {
        return 'main';
    }

    public function getCommit(\PushPull\Provider\GitRemoteConfig $config, string $hash): ?RemoteCommit
    {
        return null;
    }

    public function getTree(\PushPull\Provider\GitRemoteConfig $config, string $hash): ?RemoteTree
    {
        return null;
    }

    public function getBlob(\PushPull\Provider\GitRemoteConfig $config, string $hash): ?RemoteBlob
    {
        return null;
    }

    public function createBlob(\PushPull\Provider\GitRemoteConfig $config, string $content): string
    {
        return 'blob';
    }

    public function createTree(\PushPull\Provider\GitRemoteConfig $config, array $entries): string
    {
        return 'tree';
    }

    public function createCommit(\PushPull\Provider\GitRemoteConfig $config, \PushPull\Provider\CreateRemoteCommitRequest $request): string
    {
        return 'commit';
    }

    public function updateRef(\PushPull\Provider\GitRemoteConfig $config, \PushPull\Provider\UpdateRemoteRefRequest $request): UpdateRefResult
    {
        return new UpdateRefResult(true, $request->refName, $request->newCommitHash);
    }

    public function initializeEmptyRepository(\PushPull\Provider\GitRemoteConfig $config, string $commitMessage): RemoteRef
    {
        return new RemoteRef('refs/heads/main', 'commit');
    }
}
