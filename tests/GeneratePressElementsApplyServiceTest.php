<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentSnapshot;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;
use PushPull\Settings\SettingsRepository;

final class GeneratePressElementsApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GeneratePressElementsAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GeneratePressElementsAdapter();
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
        $GLOBALS['pushpull_test_wpml_translations'] = [];
    }

    public function testApplyCreatesGeneratePressElementWithOwnedMeta(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new \WP_Post(
            17,
            'Share an event',
            'share-an-event',
            'publish',
            0,
            'page'
        );

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'post_title' => 'Header CTA',
                'post_name' => 'header-cta',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>CTA</p><!-- /wp:paragraph -->',
                'post_meta' => [
                    ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
                    ['meta_key' => '_generate_hook', 'meta_value' => 'generate_after_header'],
                    ['meta_key' => '_generate_element_display_conditions', 'meta_value' => [['object' => '17', 'rule' => 'post:page']]],
                    ['meta_key' => '_generate_block_type', 'meta_value' => 'hook'],
                    ['meta_key' => '_wpml_word_count', 'meta_value' => '44'],
                ],
                'terms' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(
                91,
                'Share an event',
                'share-an-event',
                'publish',
                0,
                'page'
            ),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 92;

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
            ['generatepress_elements']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(
            1,
            array_values(array_filter(
                $GLOBALS['pushpull_test_generateblocks_posts'],
                static fn (\WP_Post $post): bool => $post->post_type === 'gp_elements'
            ))
        );

        $post = array_values(array_filter(
            $GLOBALS['pushpull_test_generateblocks_posts'],
            static fn (\WP_Post $candidate): bool => $candidate->post_type === 'gp_elements'
        ))[0];
        self::assertSame('gp_elements', $post->post_type);
        self::assertSame('header-cta', $post->post_name);
        self::assertSame(['block'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate_element_type']);
        self::assertSame(['generate_after_header'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate_hook']);
        $conditions = $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate_element_display_conditions'][0];
        self::assertSame('91', $conditions[0]['object']);
        self::assertSame('post:page', $conditions[0]['rule']);
        self::assertArrayNotHasKey('_wpml_word_count', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]);
    }

    public function testApplyPreservesUnmanagedMetaOnExistingGeneratePressElement(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new \WP_Post(
            42,
            'Header CTA',
            'header-cta',
            'publish',
            0,
            'gp_elements',
            '<!-- wp:paragraph --><p>Old CTA</p><!-- /wp:paragraph -->'
        );
        $GLOBALS['pushpull_test_generateblocks_meta'][42] = [
            '_wpml_word_count' => ['44'],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'post_title' => 'Header CTA',
                'post_name' => 'header-cta',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>CTA</p><!-- /wp:paragraph -->',
                'post_meta' => [
                    ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
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
            ['generatepress_elements']
        ));

        self::assertSame(1, $result->updatedCount);
        self::assertSame(['44'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_wpml_word_count']);
        self::assertSame(['block'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_generate_element_type']);
    }

    public function testApplyResolvesMixedConditionArraysAgainstDestinationPageIds(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(42, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(64, 'Blog', 'blog', 'publish', 0, 'page'),
            new \WP_Post(3420, 'Other hero', 'other-hero', 'publish', 0, 'gp_elements', '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            3420 => [
                '_generate_element_exclude_conditions' => [[
                    ['object' => '0', 'rule' => 'general:front_page'],
                    ['object' => '64', 'rule' => 'post:page'],
                    ['object' => '42', 'rule' => 'post:page'],
                ]],
            ],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 3420,
                'post_title' => 'Other hero',
                'post_name' => 'other-hero',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
                'post_meta' => [
                    ['meta_key' => '_generate_element_exclude_conditions', 'meta_value' => [
                        ['object' => '0', 'rule' => 'general:front_page'],
                        ['object' => '64', 'rule' => 'post:page'],
                        ['object' => '42', 'rule' => 'post:page'],
                    ]],
                    ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
                ],
                'terms' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(92, 'Blog', 'blog', 'publish', 0, 'page'),
            new \WP_Post(200, 'Other hero', 'other-hero', 'publish', 0, 'gp_elements', '<!-- wp:paragraph --><p>Old Hero</p><!-- /wp:paragraph -->'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];

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
            ['generatepress_elements']
        ));

        self::assertSame(1, $result->updatedCount);
        $conditions = $GLOBALS['pushpull_test_generateblocks_meta'][200]['_generate_element_exclude_conditions'][0];
        self::assertSame('0', $conditions[0]['object']);
        self::assertSame('general:front_page', $conditions[0]['rule']);
        self::assertSame('92', $conditions[1]['object']);
        self::assertSame('post:page', $conditions[1]['rule']);
        self::assertSame('91', $conditions[2]['object']);
        self::assertSame('post:page', $conditions[2]['rule']);
    }

    public function testApplyPersistsWpmlLanguageAndIdentifierForGeneratePressElementsOverride(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'identifier_managed_sets' => ['generatepress_elements'],
        ]);

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'pushpull_identifier' => 'course-template-en',
                'post_title' => 'Course template EN',
                'post_name' => 'course-template-en',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Template</p><!-- /wp:paragraph -->',
                'wpml_language' => 'en',
                'post_meta' => [
                    ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
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
            ['generatepress_elements']
        ));

        self::assertSame(1, $result->createdCount);
        $post = array_values(array_filter(
            $GLOBALS['pushpull_test_generateblocks_posts'],
            static fn (\WP_Post $candidate): bool => $candidate->post_type === 'gp_elements'
        ))[0];
        self::assertSame('course-template-en', $post->post_name);
        self::assertSame('course-template-en', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID][GeneratePressElementsAdapter::IDENTIFIER_META_KEY]);
        self::assertSame('en', $GLOBALS['pushpull_test_wpml_translations'][0]['language_code']);
        self::assertSame('post_gp_elements', $GLOBALS['pushpull_test_wpml_translations'][0]['element_type']);
    }

    public function testApplyMatchesExistingGeneratePressElementByLanguageWhenLogicalKeyCollides(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(40, 'Course template FR', 'course-template-en', 'publish', 0, 'gp_elements', '<p>Old FR</p>'),
            new \WP_Post(41, 'Course template EN', 'course-template-en', 'publish', 0, 'gp_elements', '<p>Old EN</p>'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            40 => ['_generate_element_type' => ['block']],
            41 => ['_generate_element_type' => ['block']],
        ];
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'post_gp_elements',
                'element_id' => 40,
                'trid' => 10,
                'language_code' => 'fr',
                'source_language_code' => null,
            ],
            [
                'translation_id' => 2,
                'element_type' => 'post_gp_elements',
                'element_id' => 41,
                'trid' => 10,
                'language_code' => 'en',
                'source_language_code' => 'fr',
            ],
        ];

        $item = $this->adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 99,
            'post_title' => 'Course template EN',
            'post_name' => 'course-template-en',
            'post_status' => 'publish',
            'post_content' => '<p>New EN</p>',
            'wpml_language' => 'en',
            'post_meta' => [
                ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
            ],
            'terms' => [],
        ]);
        $snapshot = new ManagedContentSnapshot(
            [$item],
            new ManagedCollectionManifest('generatepress_elements', 'generatepress_elements_manifest', ['course-template-en'])
        );

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
            ['generatepress_elements']
        ));

        self::assertSame(1, $result->updatedCount);
        self::assertSame('<p>Old FR</p>', $GLOBALS['pushpull_test_generateblocks_posts'][0]->post_content);
        self::assertSame('<p>New EN</p>', $GLOBALS['pushpull_test_generateblocks_posts'][1]->post_content);
    }
}
