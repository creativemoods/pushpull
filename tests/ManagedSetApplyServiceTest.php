<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class ManagedSetApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GenerateBlocksGlobalStylesAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GenerateBlocksGlobalStylesAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new ContentMapRepository($this->wpdb),
            new WorkingStateRepository($this->wpdb)
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 1;
    }

    public function testApplyCreatesUpdatesOrdersAndDeletesToMatchRepository(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 1, ['paddingTop' => '7rem']),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 0, ['borderTopWidth' => '1px']),
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, '.gbp-section', 'gbp-section', 'publish', 0),
            new \WP_Post(11, '.gbp-obsolete', 'gbp-obsolete', 'publish', 1),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            10 => [
                'gb_style_selector' => '.gbp-section',
                'gb_style_data' => ['paddingTop' => '1rem'],
                'gb_style_css' => '.gbp-section { padding-top: 1rem; }',
            ],
            11 => [
                'gb_style_selector' => '.gbp-obsolete',
                'gb_style_data' => ['paddingTop' => '2rem'],
                'gb_style_css' => '.gbp-obsolete { padding-top: 2rem; }',
            ],
        ];
        $GLOBALS['pushpull_test_next_post_id'] = 12;

        $result = $this->applyService->apply(new PushPullSettings(
            'github',
            'creativemoods',
            'pushpulltestrepo',
            'main',
            'token',
            '',
            false,
            true,
            'Jane Doe',
            'jane@example.com',
            ['generateblocks_global_styles']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertSame(1, $result->updatedCount);
        self::assertSame(['gbp-obsolete'], $result->deletedLogicalKeys);
        self::assertCount(2, $GLOBALS['pushpull_test_generateblocks_posts']);

        $postsBySlug = [];
        foreach ($GLOBALS['pushpull_test_generateblocks_posts'] as $post) {
            $postsBySlug[$post->post_name] = $post;
        }

        self::assertSame(10, $postsBySlug['gbp-section']->ID);
        self::assertSame(1, $postsBySlug['gbp-section']->menu_order);
        self::assertSame(12, $postsBySlug['gbp-card']->ID);
        self::assertSame(0, $postsBySlug['gbp-card']->menu_order);
        self::assertSame(['paddingTop' => '7rem'], $GLOBALS['pushpull_test_generateblocks_meta'][10]['gb_style_data']);
        self::assertSame(['borderTopWidth' => '1px'], $GLOBALS['pushpull_test_generateblocks_meta'][12]['gb_style_data']);

        $contentMapRepository = new ContentMapRepository($this->wpdb);
        self::assertSame(10, $contentMapRepository->findByLogicalKey('generateblocks_global_styles', 'generateblocks_global_style', 'gbp-section')?->wpObjectId);
        self::assertSame(12, $contentMapRepository->findByLogicalKey('generateblocks_global_styles', 'generateblocks_global_style', 'gbp-card')?->wpObjectId);
    }

    public function testApplyFailsWhenConflictsArePending(): void
    {
        $tables = new \PushPull\Persistence\TableNames($this->wpdb->prefix);
        $this->wpdb->insert($tables->repoWorkingState(), [
            'managed_set_key' => 'generateblocks_global_styles',
            'branch_name' => 'main',
            'current_branch' => 'main',
            'head_commit_hash' => 'abc',
            'working_tree_json' => '{}',
            'index_json' => null,
            'merge_base_hash' => 'base',
            'merge_target_hash' => 'theirs',
            'conflict_state_json' => '{"conflicts":[{"path":"x"}]}',
            'updated_at' => '2026-03-24 09:00:00',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot apply repository content while merge conflicts are pending.');

        $this->applyService->apply(new PushPullSettings(
            'github',
            'creativemoods',
            'pushpulltestrepo',
            'main',
            'token',
            '',
            false,
            true,
            'Jane Doe',
            'jane@example.com',
            ['generateblocks_global_styles']
        ));
    }

    public function testApplyIgnoresRepositoryBootstrapMarkerFiles(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);

        $result = $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        self::assertNotNull($result->commit);

        $markerBlob = $this->repository->storeBlob("Initialized by PushPull.\n");
        $tree = $this->repository->getTree($result->commit->treeHash);

        self::assertNotNull($tree);

        $entries = $tree->entries;
        $entries[] = new \PushPull\Domain\Repository\TreeEntry('.pushpull-initialized', 'blob', $markerBlob->hash);
        $newTree = $this->repository->storeTree($entries);
        $newCommit = $this->repository->commit(new \PushPull\Domain\Repository\CommitRequest(
            $newTree->hash,
            $result->commit->hash,
            null,
            'Commit with bootstrap marker',
            'Jane Doe',
            'jane@example.com'
        ));
        $this->repository->updateRef('refs/heads/main', $newCommit->hash);
        $this->repository->updateRef('HEAD', $newCommit->hash);

        $applyResult = $this->applyService->apply(new PushPullSettings(
            'github',
            'creativemoods',
            'pushpulltestrepo',
            'main',
            'token',
            '',
            false,
            true,
            'Jane Doe',
            'jane@example.com',
            ['generateblocks_global_styles']
        ));

        self::assertSame(1, $applyResult->createdCount);
        self::assertSame([], $applyResult->deletedLogicalKeys);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);
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
}
