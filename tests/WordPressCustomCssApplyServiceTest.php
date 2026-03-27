<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressCustomCssAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressCustomCssApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressCustomCssAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressCustomCssAdapter();
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
        $GLOBALS['pushpull_test_term_meta'] = [];
        $GLOBALS['pushpull_test_object_terms'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 1;
        $GLOBALS['pushpull_test_next_term_id'] = 1;
    }

    public function testApplyCreatesCustomCssPost(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 14744,
                'post_title' => 'newlisboaevents',
                'post_name' => 'newlisboaevents',
                'post_status' => 'publish',
                'post_content' => ".sticky-sidebar {\n  position: sticky;\n}\n",
                'post_meta' => [],
                'terms' => [],
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
            ['wordpress_custom_css']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);

        $post = $GLOBALS['pushpull_test_generateblocks_posts'][0];
        self::assertSame('custom_css', $post->post_type);
        self::assertSame('newlisboaevents', $post->post_name);
        self::assertStringContainsString('.sticky-sidebar', $post->post_content);
        self::assertArrayNotHasKey($post->ID, $GLOBALS['pushpull_test_generateblocks_meta']);
    }

    public function testApplyUpdatesExistingCustomCssPost(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new \WP_Post(
            14744,
            'newlisboaevents',
            'newlisboaevents',
            'publish',
            0,
            'custom_css',
            '.old-rule { color: red; }'
        );
        $GLOBALS['pushpull_test_generateblocks_meta'][14744] = [
            '_edit_lock' => ['1'],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 14744,
                'post_title' => 'newlisboaevents',
                'post_name' => 'newlisboaevents',
                'post_status' => 'publish',
                'post_content' => ".sticky-sidebar {\n  position: sticky;\n}\n",
                'post_meta' => [],
                'terms' => [],
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
            ['wordpress_custom_css']
        ));

        self::assertSame(1, $result->updatedCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertStringContainsString('.sticky-sidebar', $GLOBALS['pushpull_test_generateblocks_posts'][0]->post_content);
        self::assertSame(['1'], $GLOBALS['pushpull_test_generateblocks_meta'][14744]['_edit_lock']);
    }
}
