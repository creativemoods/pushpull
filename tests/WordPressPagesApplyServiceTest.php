<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressPagesAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressPagesApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressPagesAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;
    private ContentMapRepository $contentMapRepository;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressPagesAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->contentMapRepository = new ContentMapRepository($this->wpdb);
        $this->applyService = new ManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            $this->contentMapRepository,
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

    public function testApplyCreatesPage(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'post_title' => 'About Us',
                'post_name' => 'about-us',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Welcome to Lisbon.</p><!-- /wp:paragraph -->',
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
            ['wordpress_pages']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);

        $post = $GLOBALS['pushpull_test_generateblocks_posts'][0];
        self::assertSame('page', $post->post_type);
        self::assertSame('about-us', $post->post_name);
        self::assertStringContainsString('Welcome to Lisbon', $post->post_content);
        self::assertSame(['no-sidebar'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate-sidebar-layout-meta']);
        self::assertSame(['true'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate-disable-post-image']);
    }

    public function testApplyUpdatesExistingPage(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new \WP_Post(
            42,
            'About Us',
            'about-us',
            'publish',
            0,
            'page',
            '<!-- wp:paragraph --><p>Old content.</p><!-- /wp:paragraph -->'
        );
        $GLOBALS['pushpull_test_generateblocks_meta'][42] = [
            '_edit_lock' => ['1'],
            '_generate-sidebar-layout-meta' => ['left-sidebar'],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'post_title' => 'About Us',
                'post_name' => 'about-us',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Welcome to Lisbon.</p><!-- /wp:paragraph -->',
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
            ['wordpress_pages']
        ));

        self::assertSame(1, $result->updatedCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertStringContainsString('Welcome to Lisbon', $GLOBALS['pushpull_test_generateblocks_posts'][0]->post_content);
        self::assertSame(['1'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_edit_lock']);
        self::assertSame(['no-sidebar'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_generate-sidebar-layout-meta']);
        self::assertSame(['true'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_generate-disable-post-image']);
    }

    public function testApplyDoesNotTrustMappedFrenchPageForEnglishLogicalKey(): void
    {
        update_option(\PushPull\Settings\SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_pages', 'translation_management'],
        ]);
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'post_page',
                'element_id' => 10,
                'trid' => 100,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 10,
                'post_title' => 'About Us',
                'post_name' => 'about-us',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Welcome to Lisbon.</p><!-- /wp:paragraph -->',
                'post_meta' => [],
                'terms' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(
                42,
                'A propos',
                'about-us',
                'publish',
                0,
                'page',
                '<!-- wp:paragraph --><p>Contenu FR.</p><!-- /wp:paragraph -->'
            ),
        ];
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 2,
                'element_type' => 'post_page',
                'element_id' => 42,
                'trid' => 200,
                'language_code' => 'fr',
                'source_language_code' => 'en',
            ],
        ];
        $GLOBALS['pushpull_test_next_post_id'] = 43;

        $this->contentMapRepository->upsert('wordpress_pages', 'wordpress_page', 'about-us--en', 42, 'stale-hash');

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
            ['wordpress_pages', 'translation_management']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertSame(['about-us--fr'], $result->deletedLogicalKeys);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertSame('About Us', $GLOBALS['pushpull_test_generateblocks_posts'][0]->post_title);
    }

    public function testApplyDoesNotReuseUnsuffixedLivePageForSuffixedRepositoryKey(): void
    {
        update_option(\PushPull\Settings\SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_pages', 'translation_management'],
        ]);
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'post_page',
                'element_id' => 10,
                'trid' => 100,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 10,
                'post_title' => 'FAQ',
                'post_name' => 'faq',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>English FAQ.</p><!-- /wp:paragraph -->',
                'post_meta' => [],
                'terms' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        update_option(\PushPull\Settings\SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_pages'],
        ]);
        $GLOBALS['pushpull_test_wpml_translations'] = [];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(
                42,
                'Questions frequentes',
                'faq',
                'publish',
                0,
                'page',
                '<!-- wp:paragraph --><p>French FAQ.</p><!-- /wp:paragraph -->'
            ),
        ];
        $GLOBALS['pushpull_test_next_post_id'] = 43;

        $this->contentMapRepository->upsert('wordpress_pages', 'wordpress_page', 'faq--en', 42, 'stale-hash');

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
            ['wordpress_pages']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertSame(0, $result->updatedCount);
        self::assertCount(2, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertSame('Questions frequentes', $GLOBALS['pushpull_test_generateblocks_posts'][0]->post_title);
        self::assertSame('FAQ', $GLOBALS['pushpull_test_generateblocks_posts'][1]->post_title);
    }

    public function testApplyAssignsTranslationLanguageFromLogicalKey(): void
    {
        update_option(\PushPull\Settings\SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_pages', 'translation_management'],
        ]);
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'post_page',
                'element_id' => 10,
                'trid' => 100,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 10,
                'post_title' => 'Blog',
                'post_name' => 'blog',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>English blog.</p><!-- /wp:paragraph -->',
                'post_meta' => [],
                'terms' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_wpml_translations'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 91;

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
            ['wordpress_pages', 'translation_management']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertCount(1, $GLOBALS['pushpull_test_wpml_translations']);
        self::assertSame(91, $GLOBALS['pushpull_test_wpml_translations'][0]['element_id']);
        self::assertSame('en', $GLOBALS['pushpull_test_wpml_translations'][0]['language_code']);
        self::assertSame(['blog--en'], $this->adapter->exportSnapshot()->manifest->orderedLogicalKeys);
    }
}
