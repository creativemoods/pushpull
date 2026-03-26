<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksConditionsAdapter;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Diff\CanonicalManagedFile;
use PushPull\Domain\Diff\ManagedSetDiffService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Diff\RepositoryRelationship;
use PushPull\Domain\Repository\CommitRequest;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Repository\TreeEntry;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;
use PushPull\Settings\PushPullSettings;

final class ManagedSetDiffServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GenerateBlocksGlobalStylesAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetDiffService $diffService;
    private PushPullSettings $settings;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GenerateBlocksGlobalStylesAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->diffService = new ManagedSetDiffService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            $this->repository
        );
        $this->settings = new PushPullSettings('github', 'owner', 'repo', 'main', 'token', '', false, true, 'Jane Doe', 'jane@example.com', ['generateblocks_global_styles']);
    }

    public function testIdenticalLiveAndLocalStateIsClean(): void
    {
        $snapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);

        $this->committer->commitSnapshot($snapshot, $this->commitRequest('Initial export'));
        $this->replaceLivePosts($snapshot);

        $result = $this->diffService->diff($this->settings);

        self::assertFalse($result->liveToLocal->hasChanges());
        self::assertSame(RepositoryRelationship::LOCAL_ONLY, $result->repositoryRelationship->status);
    }

    public function testLiveOnlyChangesAppearAsUncommittedModifiedFiles(): void
    {
        $localSnapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $liveSnapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '8rem']),
        ]);

        $this->committer->commitSnapshot($localSnapshot, $this->commitRequest('Initial export'));
        $this->replaceLivePosts($liveSnapshot);

        $result = $this->diffService->diff($this->settings);

        self::assertTrue($result->liveToLocal->hasChanges());
        self::assertSame(
            ['generateblocks/global-styles/gbp-section.json' => 'modified'],
            $this->changedPaths($result->liveToLocal)
        );
    }

    public function testRemoteOnlyChangesShowLocalBranchAsBehind(): void
    {
        $base = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $remote = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 1, ['borderTopWidth' => '1px']),
        ]);

        $baseCommit = $this->committer->commitSnapshot($base, $this->commitRequest('Initial export'))->commit;
        self::assertNotNull($baseCommit);
        $this->repository->updateRef('refs/remotes/origin/main', $baseCommit->hash);
        $this->importRemoteSnapshot($remote, 'remote-commit-2', $baseCommit->hash);
        $this->repository->updateRef('refs/heads/main', $baseCommit->hash);

        $result = $this->diffService->diff($this->settings);

        self::assertSame(RepositoryRelationship::BEHIND, $result->repositoryRelationship->status);
        self::assertSame(
            ['generateblocks/global-styles/gbp-card.json' => 'added', 'generateblocks/global-styles/manifest.json' => 'modified'],
            $this->changedPaths($result->localToRemote)
        );
    }

    public function testLocalOnlyChangesShowBranchAsAhead(): void
    {
        $base = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $local = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 1, ['borderTopWidth' => '1px']),
        ]);

        $first = $this->committer->commitSnapshot($base, $this->commitRequest('Initial export'))->commit;
        self::assertNotNull($first);
        $second = $this->committer->commitSnapshot($local, $this->commitRequest('Second export'))->commit;
        self::assertNotNull($second);
        $this->repository->updateRef('refs/remotes/origin/main', $first->hash);

        $result = $this->diffService->diff($this->settings);

        self::assertSame(RepositoryRelationship::AHEAD, $result->repositoryRelationship->status);
        self::assertSame(
            ['generateblocks/global-styles/gbp-card.json' => 'deleted', 'generateblocks/global-styles/manifest.json' => 'modified'],
            $this->changedPaths($result->localToRemote)
        );
    }

    public function testReorderOnlyChangesModifyManifestOnly(): void
    {
        $local = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 1, ['borderTopWidth' => '1px']),
        ]);
        $live = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 1, ['paddingTop' => '7rem']),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 0, ['borderTopWidth' => '1px']),
        ]);

        $this->committer->commitSnapshot($local, $this->commitRequest('Initial export'));
        $this->replaceLivePosts($live);

        $result = $this->diffService->diff($this->settings);

        self::assertSame(
            ['generateblocks/global-styles/manifest.json' => 'modified'],
            $this->changedPaths($result->liveToLocal)
        );
    }

    public function testRepositorySideStatesAreScopedToTheManagedSet(): void
    {
        $snapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);

        $this->committer->commitSnapshot($snapshot, $this->commitRequest('Initial export'));
        $this->replaceLivePosts($snapshot);
        $this->injectForeignManagedSetFiles();

        $result = $this->diffService->diff($this->settings);

        self::assertArrayHasKey('generateblocks/global-styles/gbp-section.json', $result->local->files);
        self::assertArrayHasKey('generateblocks/global-styles/manifest.json', $result->local->files);
        self::assertArrayNotHasKey('generateblocks/conditions/is_event.json', $result->local->files);
        self::assertArrayNotHasKey('generateblocks/conditions/manifest.json', $result->local->files);
    }

    protected function tearDown(): void
    {
        $this->replaceLivePosts(null);
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
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

    private function commitRequest(string $message): CommitManagedSetRequest
    {
        return new CommitManagedSetRequest('main', $message, 'Jane Doe', 'jane@example.com');
    }

    /**
     * @return array<string, string>
     */
    private function changedPaths(\PushPull\Domain\Diff\CanonicalDiffResult $diff): array
    {
        $paths = [];

        foreach ($diff->entries as $entry) {
            if ($entry->status === 'unchanged') {
                continue;
            }

            $paths[$entry->path] = $entry->status;
        }

        ksort($paths);

        return $paths;
    }

    private function importRemoteSnapshot(\PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesSnapshot $snapshot, string $commitHash, ?string $parentHash): void
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
        $this->repository->importRemoteCommit(new RemoteCommit($commitHash, $treeHash, $parentHash !== null ? [$parentHash] : [], 'Remote commit'));
        $this->repository->updateRef('refs/remotes/origin/main', $commitHash);
    }

    private function replaceLivePosts(?\PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesSnapshot $snapshot): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];

        if ($snapshot === null) {
            return;
        }

        $posts = [];
        $id = 100;

        foreach ($snapshot->items as $item) {
            $posts[] = new \WP_Post(
                $id,
                $item->displayName,
                $item->slug,
                $item->postStatus,
                (int) array_search($item->logicalKey, $snapshot->manifest->orderedLogicalKeys, true)
            );
            $GLOBALS['pushpull_test_generateblocks_meta'][$id] = [
                'gb_style_selector' => $item->selector,
                'gb_style_data' => $item->payload,
                'gb_style_css' => $item->derived['generatedCss'] ?? '',
            ];
            $id++;
        }

        $GLOBALS['pushpull_test_generateblocks_posts'] = $posts;
    }

    private function injectForeignManagedSetFiles(): void
    {
        $state = (new RepositoryStateReader($this->repository))->read('local', 'refs/heads/main');
        $stylesFiles = $state->files;
        $conditionsAdapter = new GenerateBlocksConditionsAdapter();
        $conditionItem = $conditionsAdapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 10,
                'post_title' => 'is_event',
                'post_name' => 'is_event',
                'post_status' => 'publish',
                'menu_order' => 0,
                '_gb_conditions' => serialize([
                    'logic' => 'OR',
                    'groups' => [],
                ]),
            ],
        ]);

        $conditionFiles = [];
        foreach ($conditionItem->items as $item) {
            $path = $conditionsAdapter->getRepositoryPath($item);
            $conditionFiles[$path] = new CanonicalManagedFile($path, $conditionsAdapter->serialize($item));
        }
        $manifestPath = $conditionsAdapter->getManifestPath();
        $conditionFiles[$manifestPath] = new CanonicalManagedFile($manifestPath, $conditionsAdapter->serializeManifest($conditionItem->manifest));

        $allFiles = array_merge($stylesFiles, $conditionFiles);
        ksort($allFiles);

        $entries = [];
        foreach ($allFiles as $path => $file) {
            $blob = $this->repository->storeBlob($file->content);
            $entries[] = new TreeEntry($path, 'blob', $blob->hash);
        }

        $tree = $this->repository->storeTree($entries);
        $headCommit = $this->localRepositoryHeadCommitHash();
        $commit = $this->repository->commit(
            new CommitRequest(
                $tree->hash,
                $headCommit,
                null,
                'Jane Doe',
                'jane@example.com',
                'Mixed managed-set snapshot'
            )
        );
        $this->repository->updateRef('refs/heads/main', $commit->hash);
    }

    private function localRepositoryHeadCommitHash(): ?string
    {
        return $this->repository->getHeadCommit('main')?->hash;
    }
}
