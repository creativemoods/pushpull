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
        $GLOBALS['sitepress'] = new \PushPull_Test_SitePress();
        unset($GLOBALS['pushpull_test_wpml_current_language']);
        unset($GLOBALS['pushpull_test_wpml_filter_get_term']);
    }

    public function testAdapterIsAvailableWhenWpmlIntegrationIsPresentEvenBeforeTranslationsExist(): void
    {
        $adapter = new WpmlTranslationManagementAdapter(new SettingsRepository());

        self::assertTrue($adapter->isAvailable());
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

    public function testExportIncludesTranslatedMenusWhenMenusAreManaged(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_menus', 'translation_management'],
        ]);
        update_option('icl_sitepress_settings', [
            'taxonomies_sync_option' => [
                'nav_menu' => '1',
            ],
        ]);

        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        $menuFrId = (int) wp_create_nav_menu('Footer menu FR');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'footer-menu-fr']);

        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'tax_nav_menu',
                'element_id' => $menuEnId,
                'trid' => 500,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
            [
                'translation_id' => 2,
                'element_type' => 'tax_nav_menu',
                'element_id' => $menuFrId,
                'trid' => 500,
                'language_code' => 'fr',
                'source_language_code' => 'en',
            ],
        ];

        $adapter = new WpmlTranslationManagementAdapter(new SettingsRepository());
        $snapshot = $adapter->exportSnapshot();

        self::assertCount(1, $snapshot->items);
        self::assertSame(['wordpress_menus:footer-menu-en'], $snapshot->manifest->orderedLogicalKeys);
        self::assertSame('wordpress_menus', $snapshot->items[0]->payload['contentDomain']);
        self::assertSame('footer-menu-en', $snapshot->items[0]->payload['groupKey']);
        self::assertSame('footer-menu-fr', $snapshot->items[0]->payload['translations'][1]['contentLogicalKey']);
    }

    public function testExportIncludesAllMenuTranslationsEvenWhenWpGetNavMenusIsLanguageFiltered(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_menus', 'translation_management'],
        ]);
        update_option('icl_sitepress_settings', [
            'taxonomies_sync_option' => [
                'nav_menu' => '1',
            ],
        ]);

        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        $menuFrId = (int) wp_create_nav_menu('Footer menu FR');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'footer-menu-fr']);

        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'tax_nav_menu',
                'element_id' => $menuEnId,
                'trid' => 500,
                'language_code' => 'en',
                'source_language_code' => 'fr',
            ],
            [
                'translation_id' => 2,
                'element_type' => 'tax_nav_menu',
                'element_id' => $menuFrId,
                'trid' => 500,
                'language_code' => 'fr',
                'source_language_code' => null,
            ],
        ];
        $GLOBALS['pushpull_test_wpml_current_language'] = 'fr';

        $adapter = new WpmlTranslationManagementAdapter(new SettingsRepository());
        $snapshot = $adapter->exportSnapshot();

        self::assertCount(1, $snapshot->items);
        self::assertSame(['wordpress_menus:footer-menu-fr'], $snapshot->manifest->orderedLogicalKeys);
        self::assertSame('footer-menu-fr', $snapshot->items[0]->payload['groupKey']);
        self::assertCount(2, $snapshot->items[0]->payload['translations']);
        self::assertSame('footer-menu-en', $snapshot->items[0]->payload['translations'][0]['contentLogicalKey']);
        self::assertSame('footer-menu-fr', $snapshot->items[0]->payload['translations'][1]['contentLogicalKey']);
    }

    public function testExportIncludesMenuTranslationsWhenWpmlRowsUseTermTaxonomyIds(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_menus', 'translation_management'],
        ]);
        update_option('icl_sitepress_settings', [
            'taxonomies_sync_option' => [
                'nav_menu' => '1',
            ],
        ]);

        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        $menuFrId = (int) wp_create_nav_menu('Footer menu FR');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'footer-menu-fr']);
        $GLOBALS['pushpull_test_terms']['nav_menu'][$menuEnId]->term_taxonomy_id = 101;
        $GLOBALS['pushpull_test_terms']['nav_menu'][$menuFrId]->term_taxonomy_id = 102;

        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'tax_nav_menu',
                'element_id' => 101,
                'trid' => 500,
                'language_code' => 'en',
                'source_language_code' => 'fr',
            ],
            [
                'translation_id' => 2,
                'element_type' => 'tax_nav_menu',
                'element_id' => 102,
                'trid' => 500,
                'language_code' => 'fr',
                'source_language_code' => null,
            ],
        ];
        $GLOBALS['pushpull_test_wpml_current_language'] = 'fr';

        $snapshot = (new WpmlTranslationManagementAdapter(new SettingsRepository()))->exportSnapshot();

        self::assertCount(1, $snapshot->items);
        self::assertSame(['wordpress_menus:footer-menu-fr'], $snapshot->manifest->orderedLogicalKeys);
        self::assertCount(2, $snapshot->items[0]->payload['translations']);
        self::assertSame('footer-menu-en', $snapshot->items[0]->payload['translations'][0]['contentLogicalKey']);
    }

    public function testExportIncludesMenuTranslationsWhenGetTermIsLanguageFiltered(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_menus', 'translation_management'],
        ]);
        update_option('icl_sitepress_settings', [
            'taxonomies_sync_option' => [
                'nav_menu' => '1',
            ],
        ]);

        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        $menuFrId = (int) wp_create_nav_menu('Footer menu FR');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'footer-menu-fr']);
        $GLOBALS['pushpull_test_terms']['nav_menu'][$menuEnId]->term_taxonomy_id = 101;
        $GLOBALS['pushpull_test_terms']['nav_menu'][$menuFrId]->term_taxonomy_id = 102;
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'tax_nav_menu',
                'element_id' => 101,
                'trid' => 500,
                'language_code' => 'en',
                'source_language_code' => 'fr',
            ],
            [
                'translation_id' => 2,
                'element_type' => 'tax_nav_menu',
                'element_id' => 102,
                'trid' => 500,
                'language_code' => 'fr',
                'source_language_code' => null,
            ],
        ];
        $GLOBALS['pushpull_test_wpml_current_language'] = 'fr';
        $GLOBALS['pushpull_test_wpml_filter_get_term'] = true;

        $snapshot = (new WpmlTranslationManagementAdapter(new SettingsRepository()))->exportSnapshot();

        self::assertCount(1, $snapshot->items);
        self::assertCount(2, $snapshot->items[0]->payload['translations']);
        self::assertSame('footer-menu-en', $snapshot->items[0]->payload['translations'][0]['contentLogicalKey']);
    }
}
