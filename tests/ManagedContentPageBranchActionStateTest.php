<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Admin\ManagedContentPage;
use PushPull\Content\ManagedSetRegistry;
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
}

final class ManagedContentPageFakeSyncService implements SyncServiceInterface
{
    /**
     * @param array<string, ManagedSetDiffResult> $diffs
     */
    public function __construct(private readonly array $diffs)
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
