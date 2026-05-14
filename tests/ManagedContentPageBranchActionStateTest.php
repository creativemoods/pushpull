<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Admin\ManagedContentPage;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Content\WordPress\WordPressPagesAdapter;
use PushPull\Domain\Apply\ApplyManagedSetResult;
use PushPull\Domain\Diff\CanonicalDiffEntry;
use PushPull\Domain\Diff\CanonicalDiffResult;
use PushPull\Domain\Diff\CanonicalManagedState;
use PushPull\Domain\Diff\ManagedSetDiffResult;
use PushPull\Domain\Diff\RepositoryRelationship;
use PushPull\Domain\Merge\MergeManagedSetResult;
use PushPull\Domain\Push\PushManagedSetResult;
use PushPull\Domain\Push\ResetRemoteBranchResult;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\CommitManagedSetResult;
use PushPull\Domain\Sync\FetchManagedSetResult;
use PushPull\Domain\Sync\PullManagedSetResult;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Settings\SettingsRepository;

final class ManagedContentPageBranchActionStateTest extends TestCase
{
    public function testBranchActionStateEnablesPullWhenOnlyRemoteTrackingExists(): void
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'owner',
            'repository' => 'repo',
            'branch' => 'main',
            'api_token' => 'token',
            'enabled_managed_sets' => ['wordpress_pages'],
        ]));

        $page = $this->managedContentPage(
            $settingsRepository,
            new ManagedSetRegistry([new WordPressPagesAdapter()]),
            new ManagedContentPageFakeSyncService([
                'wordpress_pages' => $this->diffResult(
                    liveLocalChanged: true,
                    localRemoteChanged: true,
                    relationship: RepositoryRelationship::REMOTE_ONLY
                ),
            ])
        );

        $state = $this->branchActionState($page, $settingsRepository->get());

        self::assertTrue($state['pull']['enabled']);
        self::assertNull($state['pull']['reason']);
        self::assertFalse($state['commitPushAll']['enabled']);
    }

    public function testOverviewBadgeUsesStateDriftSummary(): void
    {
        $page = $this->managedContentPage(
            new SettingsRepository(),
            new ManagedSetRegistry([new WordPressPagesAdapter()]),
            new ManagedContentPageFakeSyncService([])
        );

        $beforeCommit = $this->diffResult(
            liveLocalChanged: true,
            localRemoteChanged: false,
            relationship: RepositoryRelationship::IN_SYNC,
            liveFiles: ['patterns/a.json' => 'live-a'],
            localFiles: [],
            remoteFiles: []
        );
        $afterCommit = $this->diffResult(
            liveLocalChanged: false,
            localRemoteChanged: true,
            relationship: RepositoryRelationship::AHEAD,
            liveFiles: ['patterns/a.json' => 'local-a'],
            localFiles: ['patterns/a.json' => 'local-a'],
            remoteFiles: []
        );

        self::assertSame('1 live, 0 local, 0 remote', $this->overviewBadgeText($page, new WordPressPagesAdapter(), $beforeCommit));
        self::assertSame('0 live, 1 local, 0 remote', $this->overviewBadgeText($page, new WordPressPagesAdapter(), $afterCommit));
    }

    public function testOverviewBadgeUsesDivergedLabelForTwoSidedBranchDrift(): void
    {
        $page = $this->managedContentPage(
            new SettingsRepository(),
            new ManagedSetRegistry([new WordPressPagesAdapter()]),
            new ManagedContentPageFakeSyncService([])
        );

        $diverged = $this->diffResult(
            liveLocalChanged: false,
            localRemoteChanged: true,
            relationship: RepositoryRelationship::DIVERGED
        );

        self::assertSame('0 live, 0 local, 0 remote, 1 diverged', $this->overviewBadgeText($page, new WordPressPagesAdapter(), $diverged));
    }

    public function testPushOnlyModeDisablesApplyActionsButKeepsPushAvailable(): void
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'site_mode' => 'push_only',
            'enabled_managed_sets' => ['wordpress_pages'],
        ]));

        $page = $this->managedContentPage(
            $settingsRepository,
            new ManagedSetRegistry([new WordPressPagesAdapter()]),
            new ManagedContentPageFakeSyncService([
                'wordpress_pages' => $this->diffResult(
                    liveLocalChanged: true,
                    localRemoteChanged: true,
                    relationship: RepositoryRelationship::AHEAD
                ),
            ])
        );

        $branchState = $this->branchActionState($page, $settingsRepository->get());
        $applyState = $this->applyActionState($page, $settingsRepository->get(), true, true, $this->diffResult(
            liveLocalChanged: true,
            localRemoteChanged: false,
            relationship: RepositoryRelationship::IN_SYNC
        ));

        self::assertFalse($branchState['pullApplyAll']['enabled']);
        self::assertSame('This site is configured as push-only. Applying repository state into WordPress is disabled.', $branchState['pullApplyAll']['reason']);
        self::assertTrue($branchState['push']['enabled']);
        self::assertFalse($applyState['enabled']);
    }

    public function testPullOnlyModeDisablesRemoteWriteActions(): void
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'site_mode' => 'pull_only',
            'enabled_managed_sets' => ['wordpress_pages'],
        ]));

        $page = $this->managedContentPage(
            $settingsRepository,
            new ManagedSetRegistry([new WordPressPagesAdapter()]),
            new ManagedContentPageFakeSyncService([
                'wordpress_pages' => $this->diffResult(
                    liveLocalChanged: true,
                    localRemoteChanged: true,
                    relationship: RepositoryRelationship::AHEAD
                ),
            ])
        );

        $branchState = $this->branchActionState($page, $settingsRepository->get());

        self::assertFalse($branchState['push']['enabled']);
        self::assertSame('This site is configured as pull-only. Pushing branch changes to the remote repository is disabled.', $branchState['push']['reason']);
        self::assertFalse($branchState['commitPushAll']['enabled']);
        self::assertSame('This site is configured as pull-only. Pushing branch changes to the remote repository is disabled.', $branchState['commitPushAll']['reason']);
    }

    public function testBuildDiffStatePreservesUnderlyingDiffErrorMessage(): void
    {
        $page = $this->managedContentPage(
            new SettingsRepository(),
            new ManagedSetRegistry([new WordPressPagesAdapter()]),
            new ManagedContentPageFakeSyncService([], ['wordpress_pages' => 'Partners logical keys must be unique.'])
        );

        $state = $this->buildDiffState($page, 'wordpress_pages');

        self::assertNull($state['result']);
        self::assertSame('Partners logical keys must be unique.', $state['error']);
    }

    public function testBuildCommitPushAllPlanSkipsErroringManagedSets(): void
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'enabled_managed_sets' => ['wordpress_pages'],
        ]));

        $page = $this->managedContentPage(
            $settingsRepository,
            new ManagedSetRegistry([new WordPressPagesAdapter()]),
            new ManagedContentPageFakeSyncService([], ['wordpress_pages' => 'Partners logical keys must be unique.'])
        );

        $plan = $this->buildCommitPushAllPlan($page, $settingsRepository->get());

        self::assertSame([], $plan['adapters']);
        self::assertCount(1, $plan['skipped']);
        self::assertSame('WordPress pages', $plan['skipped'][0]['label']);
        self::assertSame('Partners logical keys must be unique.', $plan['skipped'][0]['message']);
    }

    public function testAttachmentsAreNotMarkedAbsentWhenLocalBranchContainsOwnedFiles(): void
    {
        $adapter = new WordPressAttachmentsAdapter();
        $page = $this->managedContentPage(
            new SettingsRepository(),
            new ManagedSetRegistry([$adapter]),
            new ManagedContentPageFakeSyncService([])
        );

        $diffResult = new ManagedSetDiffResult(
            'wordpress_attachments',
            new CanonicalManagedState('live', null, null, 'live', []),
            new CanonicalManagedState('local', null, null, 'local', $this->managedFiles([
                'wordpress/attachments/2026/03/bali-jpg/attachment.json' => '{}',
            ])),
            new CanonicalManagedState('remote', null, null, 'remote', []),
            new CanonicalDiffResult([]),
            new CanonicalDiffResult([]),
            new RepositoryRelationship(RepositoryRelationship::IN_SYNC)
        );

        self::assertFalse($this->isManagedSetAbsentFromLocalBranch($page, $adapter, $diffResult));
    }

    private function managedContentPage(
        SettingsRepository $settingsRepository,
        ManagedSetRegistry $managedSetRegistry,
        SyncServiceInterface $syncService
    ): ManagedContentPage {
        $reflection = new \ReflectionClass(ManagedContentPage::class);
        /** @var ManagedContentPage $page */
        $page = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($page, 'settingsRepository', $settingsRepository);
        $this->setProperty($page, 'managedSetRegistry', $managedSetRegistry);
        $this->setProperty($page, 'syncService', $syncService);

        return $page;
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }

    /**
     * @return array{
     *   managedSetKey: ?string,
     *   commitPushAll: array{enabled: bool, reason: ?string},
     *   pullApplyAll: array{enabled: bool, reason: ?string},
     *   fetch: array{enabled: bool, reason: ?string},
     *   pull: array{enabled: bool, reason: ?string},
     *   merge: array{enabled: bool, reason: ?string},
     *   push: array{enabled: bool, reason: ?string}
     * }
     */
    private function branchActionState(ManagedContentPage $page, \PushPull\Settings\PushPullSettings $settings): array
    {
        $reflection = new \ReflectionMethod($page, 'branchActionState');

        /** @var array{
         *   managedSetKey: ?string,
         *   commitPushAll: array{enabled: bool, reason: ?string},
         *   pullApplyAll: array{enabled: bool, reason: ?string},
         *   fetch: array{enabled: bool, reason: ?string},
         *   pull: array{enabled: bool, reason: ?string},
         *   merge: array{enabled: bool, reason: ?string},
         *   push: array{enabled: bool, reason: ?string}
         * } $state
         */
        $state = $reflection->invoke($page, $settings);

        return $state;
    }

    /**
     * @param array<string, string> $liveFiles
     * @param array<string, string> $localFiles
     * @param array<string, string> $remoteFiles
     */
    private function diffResult(
        bool $liveLocalChanged,
        bool $localRemoteChanged,
        string $relationship,
        array $liveFiles = [],
        array $localFiles = [],
        array $remoteFiles = []
    ): ManagedSetDiffResult
    {
        return $this->diffResultWithFiles(
            $liveLocalChanged,
            $localRemoteChanged,
            $relationship,
            $liveFiles,
            $localFiles,
            $remoteFiles
        );
    }

    /**
     * @param array<string, string> $liveFiles
     * @param array<string, string> $localFiles
     * @param array<string, string> $remoteFiles
     */
    private function diffResultWithFiles(
        bool $liveLocalChanged,
        bool $localRemoteChanged,
        string $relationship,
        array $liveFiles,
        array $localFiles,
        array $remoteFiles
    ): ManagedSetDiffResult
    {
        return new ManagedSetDiffResult(
            'wordpress_pages',
            new CanonicalManagedState('live', null, null, 'live', $this->managedFiles($liveFiles)),
            new CanonicalManagedState('local', null, null, 'local', $this->managedFiles($localFiles)),
            new CanonicalManagedState('remote', null, null, 'remote', $this->managedFiles($remoteFiles)),
            new CanonicalDiffResult($liveLocalChanged ? [new CanonicalDiffEntry('a.json', 'modified', 'a', 'b')] : []),
            new CanonicalDiffResult($localRemoteChanged ? [new CanonicalDiffEntry('b.json', 'modified', 'a', 'b')] : []),
            new RepositoryRelationship($relationship)
        );
    }

    /**
     * @param array<string, string> $files
     * @return array<string, \PushPull\Domain\Diff\CanonicalManagedFile>
     */
    private function managedFiles(array $files): array
    {
        $managedFiles = [];

        foreach ($files as $path => $content) {
            $managedFiles[$path] = new \PushPull\Domain\Diff\CanonicalManagedFile($path, $content);
        }

        return $managedFiles;
    }

    private function overviewBadgeText(
        ManagedContentPage $page,
        WordPressPagesAdapter $adapter,
        ManagedSetDiffResult $diffResult
    ): string {
        $reflection = new \ReflectionMethod($page, 'overviewBadgeText');

        return $reflection->invoke($page, true, $adapter, $diffResult, null);
    }

    /**
     * @return array{enabled: bool, reason: ?string}
     */
    private function applyActionState(
        ManagedContentPage $page,
        \PushPull\Settings\PushPullSettings $settings,
        bool $managedSetEnabled,
        bool $available,
        ?ManagedSetDiffResult $diffResult
    ): array {
        $reflection = new \ReflectionMethod($page, 'applyActionState');

        /** @var array{enabled: bool, reason: ?string} $state */
        $state = $reflection->invoke($page, $settings, $managedSetEnabled, $available, $diffResult);

        return $state;
    }

    /**
     * @return array{result: ?ManagedSetDiffResult, error: ?string}
     */
    private function buildDiffState(ManagedContentPage $page, string $managedSetKey): array
    {
        $reflection = new \ReflectionMethod($page, 'buildDiffState');

        /** @var array{result: ?ManagedSetDiffResult, error: ?string} $state */
        $state = $reflection->invoke($page, $managedSetKey);

        return $state;
    }

    /**
     * @return array{adapters: array<string, \PushPull\Content\ManifestManagedContentAdapterInterface>, skipped: array<int, array{label: string, message: string}>}
     */
    private function buildCommitPushAllPlan(
        ManagedContentPage $page,
        \PushPull\Settings\PushPullSettings $settings
    ): array {
        $reflection = new \ReflectionMethod($page, 'buildCommitPushAllPlan');

        /** @var array{adapters: array<string, \PushPull\Content\ManifestManagedContentAdapterInterface>, skipped: array<int, array{label: string, message: string}>} $plan */
        $plan = $reflection->invoke($page, $settings);

        return $plan;
    }

    private function isManagedSetAbsentFromLocalBranch(
        ManagedContentPage $page,
        \PushPull\Content\ManifestManagedContentAdapterInterface $adapter,
        ManagedSetDiffResult $diffResult
    ): bool {
        $reflection = new \ReflectionMethod($page, 'isManagedSetAbsentFromLocalBranch');

        return $reflection->invoke($page, $adapter, $diffResult);
    }
}

final class ManagedContentPageFakeSyncService implements SyncServiceInterface
{
    /**
     * @param array<string, ManagedSetDiffResult> $diffs
     * @param array<string, string> $diffFailures
     */
    public function __construct(
        private readonly array $diffs,
        private readonly array $diffFailures = []
    )
    {
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
        if (isset($this->diffFailures[$managedSetKey])) {
            throw new \RuntimeException($this->diffFailures[$managedSetKey]);
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
