<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressPostsAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressPostsApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressPostsAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressPostsAdapter();
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

    public function testApplyCreatesPost(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'post_title' => 'Hello World',
                'post_name' => 'hello-world',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Hello Lisbon.</p><!-- /wp:paragraph -->',
                'post_meta' => [
                    ['meta_key' => '_generate-sidebar-layout-meta', 'meta_value' => 'no-sidebar'],
                    ['meta_key' => '_generate-disable-post-image', 'meta_value' => 'true'],
                ],
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
            ['wordpress_posts']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);

        $post = $GLOBALS['pushpull_test_generateblocks_posts'][0];
        self::assertSame('post', $post->post_type);
        self::assertSame('hello-world', $post->post_name);
        self::assertStringContainsString('Hello Lisbon', $post->post_content);
        self::assertSame(['no-sidebar'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate-sidebar-layout-meta']);
        self::assertSame(['true'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate-disable-post-image']);
    }

    public function testApplyUpdatesExistingPost(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new \WP_Post(
            42,
            'Hello World',
            'hello-world',
            'publish',
            0,
            'post',
            '<!-- wp:paragraph --><p>Old content.</p><!-- /wp:paragraph -->'
        );
        $GLOBALS['pushpull_test_generateblocks_meta'][42] = [
            '_edit_lock' => ['1'],
            '_generate-sidebar-layout-meta' => ['left-sidebar'],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'post_title' => 'Hello World',
                'post_name' => 'hello-world',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Hello Lisbon.</p><!-- /wp:paragraph -->',
                'post_meta' => [
                    ['meta_key' => '_generate-sidebar-layout-meta', 'meta_value' => 'no-sidebar'],
                    ['meta_key' => '_generate-disable-post-image', 'meta_value' => 'true'],
                ],
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
            ['wordpress_posts']
        ));

        self::assertSame(1, $result->updatedCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertStringContainsString('Hello Lisbon', $GLOBALS['pushpull_test_generateblocks_posts'][0]->post_content);
        self::assertSame(['1'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_edit_lock']);
        self::assertSame(['no-sidebar'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_generate-sidebar-layout-meta']);
        self::assertSame(['true'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_generate-disable-post-image']);
    }
}
