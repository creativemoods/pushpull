<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\GenericWordPressCustomPostTypeAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class GenericWordPressCustomPostTypeApplyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_post_types'] = [
            'le_event' => new \WP_Post_Type('le_event', 'Events', false, true, false),
        ];
        $GLOBALS['pushpull_test_taxonomies'] = [
            'le_event_type' => new \WP_Taxonomy('le_event_type', 'Event Types', false, true, false, ['le_event']),
        ];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_terms'] = [];
        $GLOBALS['pushpull_test_term_meta'] = [];
        $GLOBALS['pushpull_test_object_terms'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 1;
        $GLOBALS['pushpull_test_next_term_id'] = 1;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pushpull_test_post_types'], $GLOBALS['pushpull_test_taxonomies']);
    }

    public function testApplyCreatesGenericCustomPostTypeItem(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenericWordPressCustomPostTypeAdapter('le_event', 'Events');
        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $applyService = new ManagedSetApplyService(
            $adapter,
            new RepositoryStateReader($repository),
            new ContentMapRepository($wpdb),
            new WorkingStateRepository($wpdb)
        );

        $snapshot = $adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 77,
                'post_title' => 'Festival',
                'post_name' => 'festival',
                'post_status' => 'publish',
                'post_content' => '<p>Hello</p>',
                'post_meta' => [
                    ['meta_key' => 'startdate', 'meta_value' => '2026-04-10'],
                ],
                'terms' => [
                    [
                        'taxonomy' => 'le_event_type',
                        'slug' => 'music',
                        'name' => 'Music',
                        'description' => '',
                        'parentSlug' => '',
                        'termMeta' => [],
                    ],
                ],
            ],
        ]);

        $committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $applyService->apply(new PushPullSettings(
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
            [$adapter->getManagedSetKey()]
        ));

        self::assertSame('le_event', $GLOBALS['pushpull_test_generateblocks_posts'][0]->post_type);
        self::assertSame(['2026-04-10'], $GLOBALS['pushpull_test_generateblocks_meta'][1]['startdate']);
        self::assertSame(['1'], array_map('strval', $GLOBALS['pushpull_test_object_terms'][1]['le_event_type']));
    }
}
