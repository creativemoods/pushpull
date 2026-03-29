<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Translation\WpmlTranslationManagementAdapter;
use PushPull\Domain\Apply\OverlayManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;
use PushPull\Settings\SettingsRepository;

final class WpmlTranslationManagementApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_wpml_translations'] = [];
    }

    public function testApplyMapsTranslationRowsToDestinationPageIds(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_pages', 'translation_management'],
        ]);
        update_option('icl_sitepress_settings', [
            'active_languages' => ['en', 'fr'],
            'custom_posts_sync_option' => [
                'page' => '1',
            ],
        ]);

        $this->wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($this->wpdb);
        $adapter = new WpmlTranslationManagementAdapter(new SettingsRepository());
        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $applyService = new OverlayManagedSetApplyService(
            $adapter,
            new RepositoryStateReader($repository),
            new WorkingStateRepository($this->wpdb)
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Accueil', 'accueil', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'post_page',
                'element_id' => 10,
                'trid' => 100,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
            [
                'translation_id' => 2,
                'element_type' => 'post_page',
                'element_id' => 11,
                'trid' => 100,
                'language_code' => 'fr',
                'source_language_code' => 'en',
            ],
        ];

        $snapshot = $adapter->exportSnapshot();
        $committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(92, 'Accueil', 'accueil', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_wpml_translations'] = [];

        $result = $applyService->apply(new PushPullSettings(
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
        self::assertCount(2, $GLOBALS['pushpull_test_wpml_translations']);
        self::assertSame(91, $GLOBALS['pushpull_test_wpml_translations'][0]['element_id']);
        self::assertSame(92, $GLOBALS['pushpull_test_wpml_translations'][1]['element_id']);
        self::assertSame(1, $GLOBALS['pushpull_test_wpml_translations'][0]['trid']);
        self::assertSame(1, $GLOBALS['pushpull_test_wpml_translations'][1]['trid']);
    }

    public function testApplyPreservesExistingTranslationRowsOutsideDesiredGroups(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_pages', 'translation_management'],
        ]);
        update_option('icl_sitepress_settings', [
            'active_languages' => ['en', 'fr'],
            'custom_posts_sync_option' => [
                'page' => '1',
            ],
        ]);

        $this->wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($this->wpdb);
        $adapter = new WpmlTranslationManagementAdapter(new SettingsRepository());
        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $applyService = new OverlayManagedSetApplyService(
            $adapter,
            new RepositoryStateReader($repository),
            new WorkingStateRepository($this->wpdb)
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Accueil', 'accueil', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'post_page',
                'element_id' => 10,
                'trid' => 100,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
            [
                'translation_id' => 2,
                'element_type' => 'post_page',
                'element_id' => 11,
                'trid' => 100,
                'language_code' => 'fr',
                'source_language_code' => 'en',
            ],
        ];

        $snapshot = $adapter->exportSnapshot();
        $committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(92, 'Accueil', 'accueil', 'publish', 0, 'page'),
            new \WP_Post(93, 'Blog', 'blog', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 50,
                'element_type' => 'post_page',
                'element_id' => 93,
                'trid' => 999,
                'language_code' => 'fr',
                'source_language_code' => null,
            ],
        ];

        $result = $applyService->apply(new PushPullSettings(
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

        self::assertSame([], $result->deletedLogicalKeys);
        self::assertCount(3, $GLOBALS['pushpull_test_wpml_translations']);
        self::assertSame(93, $GLOBALS['pushpull_test_wpml_translations'][0]['element_id']);
    }
}
