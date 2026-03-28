<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Translation\WpmlTranslationManagementAdapter;
use PushPull\Settings\SettingsRepository;

final class WpmlTranslationManagementAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_wpml_translations'] = [];
    }

    public function testExportScopesWpmlRowsToEnabledManagedPrimaryDomains(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_pages', 'translation_management'],
        ]);
        update_option('icl_sitepress_settings', [
            'custom_posts_sync_option' => [
                'page' => '1',
                'post' => '1',
                'gp_elements' => '2',
            ],
        ]);

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Accueil', 'accueil', 'publish', 0, 'page'),
            new \WP_Post(77, 'Hero', 'hero', 'publish', 0, 'gp_elements'),
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
            [
                'translation_id' => 3,
                'element_type' => 'post_gp_elements',
                'element_id' => 77,
                'trid' => 300,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
        ];

        $adapter = new WpmlTranslationManagementAdapter(new SettingsRepository());
        $snapshot = $adapter->exportSnapshot();

        self::assertCount(1, $snapshot->items);
        self::assertSame(['wordpress_pages:home'], $snapshot->manifest->orderedLogicalKeys);
        self::assertSame('wordpress_pages', $snapshot->items[0]->payload['contentDomain']);
        self::assertSame('home', $snapshot->items[0]->payload['groupKey']);
        self::assertCount(2, $snapshot->items[0]->payload['translations']);
    }
}
