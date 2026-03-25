<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Merge\ManagedSetMergeService;
use PushPull\Domain\Merge\JsonThreeWayMerger;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\GenerateBlocksRepositoryCommitter;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;

final class ManagedSetMergeServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GenerateBlocksGlobalStylesAdapter $adapter;
    private GenerateBlocksRepositoryCommitter $committer;
    private WorkingStateRepository $workingStateRepository;
    private ManagedSetMergeService $mergeService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GenerateBlocksGlobalStylesAdapter();
        $this->committer = new GenerateBlocksRepositoryCommitter($this->repository, $this->adapter);
        $this->workingStateRepository = new WorkingStateRepository($this->wpdb);
        $this->mergeService = new ManagedSetMergeService(
            $this->repository,
            new RepositoryStateReader($this->repository),
            new JsonThreeWayMerger(),
            $this->workingStateRepository
        );
    }

    public function testMergeFastForwardsWhenLocalBranchIsMergeBase(): void
    {
        $base = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $remote = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '8rem']),
        ]);

        $baseCommit = $this->committer->commitSnapshot($base, $this->request('Initial export'))->commit;
        self::assertNotNull($baseCommit);
        $this->importRemoteSnapshot($remote, 'remote-commit-1', $baseCommit->hash);

        $result = $this->mergeService->merge('generateblocks_global_styles', 'main');

        self::assertSame('fast_forward', $result->status);
        self::assertSame('remote-commit-1', $this->repository->getRef('refs/heads/main')?->commitHash);
        self::assertNull($this->workingStateRepository->get('generateblocks_global_styles', 'main')?->mergeTargetHash);
    }

    public function testMergeBootstrapsEmptyLocalBranchFromFetchedRemoteTrackingRef(): void
    {
        $remote = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '8rem']),
        ]);

        $this->importRemoteSnapshot($remote, 'remote-commit-bootstrap', 'remote-parent');
        $this->repository->updateRef('refs/heads/main', '');
        $this->repository->updateRef('HEAD', '');

        $result = $this->mergeService->merge('generateblocks_global_styles', 'main');

        self::assertSame('fast_forward', $result->status);
        self::assertNull($result->oursCommitHash);
        self::assertSame('remote-commit-bootstrap', $result->theirsCommitHash);
        self::assertSame('remote-commit-bootstrap', $this->repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame('remote-commit-bootstrap', $this->repository->getRef('HEAD')?->commitHash);
    }

    public function testMergeCreatesMergeCommitForNonConflictingChanges(): void
    {
        $base = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $local = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '8rem']),
        ]);
        $remote = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem', 'paddingBottom' => '4rem']),
        ]);

        $baseCommit = $this->committer->commitSnapshot($base, $this->request('Initial export'))->commit;
        self::assertNotNull($baseCommit);
        $localCommit = $this->committer->commitSnapshot($local, $this->request('Local change'))->commit;
        self::assertNotNull($localCommit);
        $this->importRemoteSnapshot($remote, 'remote-commit-2', $baseCommit->hash);

        $result = $this->mergeService->merge('generateblocks_global_styles', 'main');

        self::assertSame('merged', $result->status);
        self::assertNotNull($result->commit);
        self::assertSame($localCommit->hash, $result->commit?->parentHash);
        self::assertSame('remote-commit-2', $result->commit?->secondParentHash);
        self::assertSame($result->commit?->hash, $this->repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame([], $result->conflicts);

        $mergedState = (new RepositoryStateReader($this->repository))->readCommit('merged', $result->commit->hash);
        $content = $mergedState->files['generateblocks/global-styles/gbp-section.json']->content ?? null;
        self::assertIsString($content);
        self::assertStringContainsString('"paddingTop": "8rem"', $content);
        self::assertStringContainsString('"paddingBottom": "4rem"', $content);
    }

    public function testMergePersistsConflictStateWhenBothSidesEditSameLeaf(): void
    {
        $base = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $local = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '8rem']),
        ]);
        $remote = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '9rem']),
        ]);

        $baseCommit = $this->committer->commitSnapshot($base, $this->request('Initial export'))->commit;
        self::assertNotNull($baseCommit);
        $localCommit = $this->committer->commitSnapshot($local, $this->request('Local change'))->commit;
        self::assertNotNull($localCommit);
        $this->importRemoteSnapshot($remote, 'remote-commit-3', $baseCommit->hash);

        $result = $this->mergeService->merge('generateblocks_global_styles', 'main');

        self::assertSame('conflict', $result->status);
        self::assertCount(1, $result->conflicts);
        self::assertSame(['$.payload.paddingTop'], $result->conflicts[0]->jsonPaths);
        self::assertSame($localCommit->hash, $this->repository->getRef('refs/heads/main')?->commitHash);

        $workingState = $this->workingStateRepository->get('generateblocks_global_styles', 'main');
        self::assertNotNull($workingState);
        self::assertTrue($workingState->hasConflicts());
        self::assertSame('remote-commit-3', $workingState->mergeTargetHash);
        self::assertCount(1, $workingState->conflicts);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function snapshot(array $records): \PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesSnapshot
    {
        return $this->adapter->snapshotFromRuntimeRecords($records);
    }

    /**
     * @param array<string, mixed> $styleData
     * @return array<string, mixed>
     */
    private function runtimeRecord(string $selector, string $slug, int $menuOrder, array $styleData = []): array
    {
        return [
            'wp_object_id' => 1,
            'post_title' => $selector,
            'post_name' => $slug,
            'post_status' => 'publish',
            'menu_order' => $menuOrder,
            'gb_style_selector' => $selector,
            'gb_style_data' => serialize($styleData),
            'gb_style_css' => $selector . ' { color: red; }',
        ];
    }

    private function request(string $message): CommitManagedSetRequest
    {
        return new CommitManagedSetRequest('main', $message, 'Jane Doe', 'jane@example.com');
    }

    private function importRemoteSnapshot(\PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesSnapshot $snapshot, string $commitHash, string $parentHash): void
    {
        $entries = [];
        $counter = 1;

        foreach ($snapshot->items as $item) {
            $path = $this->adapter->getRepositoryPath($item);
            $blobHash = sprintf('%s-blob-%d', $commitHash, $counter++);
            $this->repository->importRemoteBlob(new RemoteBlob($blobHash, $this->adapter->serialize($item)));
            $entries[] = ['path' => $path, 'type' => 'blob', 'hash' => $blobHash];
        }

        $manifestHash = sprintf('%s-blob-%d', $commitHash, $counter);
        $this->repository->importRemoteBlob(new RemoteBlob($manifestHash, $this->adapter->serializeManifest($snapshot->manifest)));
        $entries[] = ['path' => $this->adapter->getManifestPath(), 'type' => 'blob', 'hash' => $manifestHash];

        $treeHash = $commitHash . '-tree';
        $this->repository->importRemoteTree(new RemoteTree($treeHash, $entries));
        $this->repository->importRemoteCommit(new RemoteCommit($commitHash, $treeHash, [$parentHash], 'Remote commit'));
        $this->repository->updateRef('refs/remotes/origin/main', $commitHash);
    }
}
