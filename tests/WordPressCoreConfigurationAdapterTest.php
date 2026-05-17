<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressCoreConfigurationAdapter;

final class WordPressCoreConfigurationAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
    }

    public function testExportSnapshotNormalizesReadingSettingsPageReferences(): void
    {
        update_option('show_on_front', 'page');
        update_option('page_on_front', 10);
        update_option('page_for_posts', 11);
        update_option('permalink_structure', '/%postname%/');

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Blog', 'blog', 'publish', 0, 'page'),
        ];

        $adapter = new WordPressCoreConfigurationAdapter();
        $snapshot = $adapter->exportSnapshot();

        self::assertSame(['permalink-settings', 'reading-settings'], $snapshot->orderedLogicalKeys);
        self::assertCount(2, $snapshot->items);

        self::assertSame('wordpress_permalink_settings', $snapshot->items[0]->contentType);
        self::assertSame('/%postname%/', $snapshot->items[0]->payload['permalinkStructure']);

        $item = $snapshot->items[1];
        self::assertSame('wordpress_core_configuration', $item->managedSetKey);
        self::assertSame('wordpress_reading_settings', $item->contentType);
        self::assertSame('page', $item->payload['showOnFront']);
        self::assertSame('home', $item->payload['frontPageRef']['logicalKey']);
        self::assertSame('blog', $item->payload['postsPageRef']['logicalKey']);
    }

    public function testExportSnapshotUsesDefaultSiteLanguageWhenWpmlCurrentLanguageDiffers(): void
    {
        update_option('icl_sitepress_settings', [
            'default_language' => 'fr',
            'active_languages' => ['fr', 'en'],
        ]);
        update_option('show_on_front', 'page');
        update_option('page_on_front', 10);
        update_option('page_for_posts', 11);
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Page principale', 'page-principale', 'publish', 0, 'page'),
            new \WP_Post(11, 'Le Blog', 'leblog', 'publish', 0, 'page'),
            new \WP_Post(20, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(21, 'Blog', 'blog', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_wpml_translations'] = [
            ['element_type' => 'post_page', 'element_id' => 10, 'trid' => 1, 'language_code' => 'fr', 'source_language_code' => null],
            ['element_type' => 'post_page', 'element_id' => 20, 'trid' => 1, 'language_code' => 'en', 'source_language_code' => 'fr'],
            ['element_type' => 'post_page', 'element_id' => 11, 'trid' => 2, 'language_code' => 'fr', 'source_language_code' => null],
            ['element_type' => 'post_page', 'element_id' => 21, 'trid' => 2, 'language_code' => 'en', 'source_language_code' => 'fr'],
        ];
        $GLOBALS['pushpull_test_wpml_current_language'] = 'en';

        $snapshot = (new WordPressCoreConfigurationAdapter())->exportSnapshot();
        $item = $snapshot->items[1];

        self::assertSame('page-principale', $item->payload['frontPageRef']['logicalKey']);
        self::assertSame('leblog', $item->payload['postsPageRef']['logicalKey']);
    }
}
