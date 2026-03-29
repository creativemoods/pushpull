<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Admin\AdminBarStatus;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;
use PushPull\Content\WordPress\WordPressPagesAdapter;
use PushPull\Domain\Apply\ApplyManagedSetResult;
use PushPull\Domain\Diff\CanonicalDiffEntry;
use PushPull\Domain\Diff\CanonicalDiffResult;
use PushPull\Domain\Diff\CanonicalManagedState;
use PushPull\Domain\Diff\ManagedSetDiffResult;
use PushPull\Domain\Diff\RepositoryRelationship;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\CommitManagedSetResult;
use PushPull\Domain\Sync\FetchManagedSetResult;
use PushPull\Domain\Sync\PullManagedSetResult;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Domain\Merge\MergeManagedSetResult;
use PushPull\Domain\Push\PushManagedSetResult;
use PushPull\Domain\Push\ResetRemoteBranchResult;
use PushPull\Settings\SettingsRepository;

final class AdminBarStatusTest extends TestCase
{
    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_current_user_can'] = true;
        $GLOBALS['pushpull_test_admin_bar_showing'] = true;
    }

    public function testRegisterAddsHighLevelPushPullStatusNodes(): void
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'owner',
            'repository' => 'repo',
            'branch' => 'main',
            'api_token' => 'token',
            'enabled_managed_sets' => ['wordpress_pages', 'generatepress_elements'],
        ]));

        $registry = new ManagedSetRegistry([
            new GeneratePressElementsAdapter(),
            new WordPressPagesAdapter(),
        ]);

        $syncService = new AdminBarStatusFakeSyncService([
            'wordpress_pages' => $this->diffResult(
                liveLocalChanged: true,
                localRemoteChanged: false,
                relationship: RepositoryRelationship::IN_SYNC
            ),
            'generatepress_elements' => $this->diffResult(
                liveLocalChanged: false,
                localRemoteChanged: true,
                relationship: RepositoryRelationship::DIVERGED
            ),
        ]);

        $adminBar = new \WP_Admin_Bar();
        $status = new AdminBarStatus($settingsRepository, $registry, $syncService);

        $status->register($adminBar);

        self::assertSame('PushPull 1 local / 1 remote', $adminBar->nodes['pushpull-status']['title']);
        self::assertSame('Live vs local: 1 changed set(s)', $adminBar->nodes['pushpull-status-live-local']['title']);
        self::assertSame('Local vs remote: 1 changed set(s)', $adminBar->nodes['pushpull-status-local-remote']['title']);
        self::assertSame('Branch: main', $adminBar->nodes['pushpull-status-branch']['title']);
        self::assertSame('Enabled domains: 2', $adminBar->nodes['pushpull-status-enabled']['title']);
        self::assertSame('Diverged sets: 1', $adminBar->nodes['pushpull-status-conflicts']['title']);
    }

    public function testRegisterMarksUnavailableSetStatusesWithoutBreakingTheMenu(): void
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'owner',
            'repository' => 'repo',
            'branch' => 'main',
            'api_token' => 'token',
            'enabled_managed_sets' => ['wordpress_pages', 'generatepress_elements'],
        ]));

        $registry = new ManagedSetRegistry([
            new GeneratePressElementsAdapter(),
            new WordPressPagesAdapter(),
        ]);

        $syncService = new AdminBarStatusFakeSyncService([
            'wordpress_pages' => $this->diffResult(
                liveLocalChanged: false,
                localRemoteChanged: false,
                relationship: RepositoryRelationship::IN_SYNC
            ),
        ], ['generatepress_elements']);

        $adminBar = new \WP_Admin_Bar();
        $status = new AdminBarStatus($settingsRepository, $registry, $syncService);

        $status->register($adminBar);

        self::assertSame('PushPull 0 local / 0 remote / 1 unavailable', $adminBar->nodes['pushpull-status']['title']);
        self::assertSame('Unavailable status: 1 set(s)', $adminBar->nodes['pushpull-status-unavailable']['title']);
    }

    private function diffResult(bool $liveLocalChanged, bool $localRemoteChanged, string $relationship): ManagedSetDiffResult
    {
        return new ManagedSetDiffResult(
            'test',
            new CanonicalManagedState('live', null, null, 'live', []),
            new CanonicalManagedState('local', null, null, 'local', []),
            new CanonicalManagedState('remote', null, null, 'remote', []),
            new CanonicalDiffResult($liveLocalChanged ? [new CanonicalDiffEntry('a.json', 'modified', 'a', 'b')] : []),
            new CanonicalDiffResult($localRemoteChanged ? [new CanonicalDiffEntry('b.json', 'modified', 'a', 'b')] : []),
            new RepositoryRelationship($relationship)
        );
    }
}

final class AdminBarStatusFakeSyncService implements SyncServiceInterface
{
    /**
     * @param array<string, ManagedSetDiffResult> $diffs
     * @param string[] $failingManagedSetKeys
     */
    public function __construct(
        private readonly array $diffs,
        private readonly array $failingManagedSetKeys = []
    ) {
    }

    public function commitManagedSet(string $managedSetKey, CommitManagedSetRequest $request): CommitManagedSetResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function fetch(string $managedSetKey): FetchManagedSetResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function pull(string $managedSetKey): PullManagedSetResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function diff(string $managedSetKey): ManagedSetDiffResult
    {
        if (in_array($managedSetKey, $this->failingManagedSetKeys, true)) {
            throw new \RuntimeException('Diff unavailable.');
        }

        return $this->diffs[$managedSetKey];
    }

    public function merge(string $managedSetKey): MergeManagedSetResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function apply(string $managedSetKey): ApplyManagedSetResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function push(string $managedSetKey): PushManagedSetResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function resetRemote(string $managedSetKey): ResetRemoteBranchResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }
}
