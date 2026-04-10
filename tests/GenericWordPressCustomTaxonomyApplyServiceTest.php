<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\GenericWordPressCustomTaxonomyAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class GenericWordPressCustomTaxonomyApplyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_taxonomies'] = [
            'le_region' => new \WP_Taxonomy('le_region', 'Regions', true, true, false, ['le_event']),
        ];
        $GLOBALS['pushpull_test_terms'] = [];
        $GLOBALS['pushpull_test_term_meta'] = [];
        $GLOBALS['pushpull_test_object_terms'] = [];
        $GLOBALS['pushpull_test_next_term_id'] = 1;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pushpull_test_taxonomies']);
    }

    public function testApplyCreatesGenericCustomTaxonomyTerms(): void
    {
        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenericWordPressCustomTaxonomyAdapter('le_region', 'Regions');
        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $applyService = new ManagedSetApplyService(
            $adapter,
            new RepositoryStateReader($repository),
            new ContentMapRepository($wpdb),
            new WorkingStateRepository($wpdb)
        );

        $snapshot = $adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 1,
                'slug' => 'suisse',
                'name' => 'Suisse',
                'description' => '',
                'parentSlug' => '',
                'termMeta' => [],
            ],
            [
                'wp_object_id' => 2,
                'slug' => 'vaud',
                'name' => 'Vaud',
                'description' => '',
                'parentSlug' => 'suisse',
                'termMeta' => [
                    ['meta_key' => 'code', 'meta_value' => 'VD'],
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

        self::assertCount(2, $GLOBALS['pushpull_test_terms']['le_region']);
        self::assertSame(1, $GLOBALS['pushpull_test_terms']['le_region'][2]->parent);
        self::assertSame(['VD'], $GLOBALS['pushpull_test_term_meta'][2]['code']);
    }
}
