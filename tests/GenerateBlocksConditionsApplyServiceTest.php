<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksConditionsAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class GenerateBlocksConditionsApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GenerateBlocksConditionsAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GenerateBlocksConditionsAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new ContentMapRepository($this->wpdb),
            new WorkingStateRepository($this->wpdb)
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_terms'] = [];
        $GLOBALS['pushpull_test_object_terms'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 1;
        $GLOBALS['pushpull_test_next_term_id'] = 1;
    }

    public function testApplyCreatesAndAssignsMissingConditionCategories(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 22404,
                'post_title' => 'is_event',
                'post_name' => 'is_event',
                'post_status' => 'publish',
                'menu_order' => 0,
                '_gb_conditions' => serialize([
                    'logic' => 'OR',
                    'groups' => [],
                ]),
                'categories' => [
                    ['slug' => 'events', 'name' => 'Events'],
                    ['slug' => 'homepage', 'name' => 'Homepage'],
                ],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

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
            ['generateblocks_conditions']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertCount(2, $GLOBALS['pushpull_test_terms']['gblocks_condition_cat'] ?? []);

        $post = $GLOBALS['pushpull_test_generateblocks_posts'][0];
        self::assertSame(
            [1, 2],
            $GLOBALS['pushpull_test_object_terms'][$post->ID]['gblocks_condition_cat'] ?? []
        );
    }
}
