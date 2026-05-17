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
use PushPull\Settings\SettingsRepository;

final class GenericWordPressCustomPostTypeApplyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_post_types'] = [
            'le_event' => new \WP_Post_Type('le_event', 'Events', false, true, false),
            'le_partner' => new \WP_Post_Type('le_partner', 'Partners', false, true, false),
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
        $GLOBALS['pushpull_test_wpml_translations'] = [];
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

    public function testApplyPersistsWpmlLanguageAndKeepsDesiredSlugAcrossLanguageCollision(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'identifier_managed_sets' => ['custom_post_type_le_partner'],
        ]);

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(1, 'Leman Experiences', 'leman-experiences', 'publish', 0, 'le_partner', '<p>EN</p>'),
        ];
        $GLOBALS['pushpull_test_next_post_id'] = 2;
        $GLOBALS['pushpull_test_generateblocks_meta'][1][GenericWordPressCustomPostTypeAdapter::IDENTIFIER_META_KEY] = 'leman-experiences-en';
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'post_le_partner',
                'element_id' => 1,
                'trid' => 10,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
        ];

        $wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($wpdb);
        $adapter = new GenericWordPressCustomPostTypeAdapter('le_partner', 'Partners');
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
                'pushpull_identifier' => 'leman-experiences-en',
                'post_title' => 'Leman Experiences',
                'post_name' => 'leman-experiences',
                'post_status' => 'publish',
                'post_content' => '<p>EN</p>',
                'wpml_language' => 'en',
                'post_meta' => [],
                'terms' => [],
            ],
            [
                'wp_object_id' => 77,
                'pushpull_identifier' => 'leman-experiences-fr',
                'post_title' => 'Leman Experiences',
                'post_name' => 'leman-experiences',
                'post_status' => 'publish',
                'post_content' => '<p>FR</p>',
                'wpml_language' => 'fr',
                'post_meta' => [],
                'terms' => [],
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

        self::assertCount(2, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertSame('leman-experiences', $GLOBALS['pushpull_test_generateblocks_posts'][0]->post_name);
        self::assertSame('leman-experiences', $GLOBALS['pushpull_test_generateblocks_posts'][1]->post_name);
        self::assertSame('fr', $GLOBALS['pushpull_test_wpml_translations'][1]['language_code']);
    }
}
