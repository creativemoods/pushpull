<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressMenusAdapter;

final class WordPressMenusAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_theme_mods'] = [];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_terms']['nav_menu'] = [];
        $GLOBALS['pushpull_test_next_term_id'] = 1;
        $GLOBALS['pushpull_test_next_post_id'] = 1;
        unset($GLOBALS['pushpull_test_wpml_current_language']);
        unset($GLOBALS['pushpull_test_wpml_filter_get_term']);
    }

    public function testExportSnapshotNormalizesMenuRefsAndLocations(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Blog', 'blog', 'publish', 0, 'page'),
        ];

        $menuId = (int) wp_create_nav_menu('Header Navigation');
        wp_update_term($menuId, 'nav_menu', ['slug' => 'header-navigation']);
        set_theme_mod('nav_menu_locations', [
            'primary' => $menuId,
            'footer' => $menuId,
        ]);

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => 10,
            'menu-item-position' => 1,
            'menu-item-status' => 'publish',
        ]);

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'External',
            'menu-item-type' => 'custom',
            'menu-item-url' => 'https://source.example.test/about',
            'menu-item-position' => 2,
            'menu-item-status' => 'publish',
        ]);

        $adapter = new WordPressMenusAdapter();
        $snapshot = $adapter->exportSnapshot();

        self::assertSame(['header-navigation'], $snapshot->orderedLogicalKeys);
        self::assertCount(1, $snapshot->items);
        self::assertSame('wordpress_menu', $snapshot->items[0]->contentType);
        self::assertSame(['footer', 'primary'], $snapshot->items[0]->payload['locations']);
        self::assertSame('page:home', $snapshot->items[0]->payload['items'][0]['itemKey']);
        self::assertSame('page', $snapshot->items[0]->payload['items'][0]['reference']['objectRef']['postType']);
        self::assertSame('home', $snapshot->items[0]->payload['items'][0]['reference']['objectRef']['logicalKey']);
        self::assertSame('', $snapshot->items[0]->payload['items'][0]['url']);
        self::assertSame('{{pushpull.home_url}}/about', $snapshot->items[0]->payload['items'][1]['url']);
    }

    public function testExportSnapshotIncludesAllMenusWhenWpmlFiltersWpGetNavMenusByLanguage(): void
    {
        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        $menuFrId = (int) wp_create_nav_menu('Footer menu FR');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'footer-menu-fr']);
        $menuOtherId = (int) wp_create_nav_menu('Premium menu');
        wp_update_term($menuOtherId, 'nav_menu', ['slug' => 'premium-menu']);

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

        $adapter = new WordPressMenusAdapter();
        $snapshot = $adapter->exportSnapshot();

        self::assertSame(['footer-menu-en', 'footer-menu-fr', 'premium-menu'], $snapshot->orderedLogicalKeys);
    }

    public function testExportSnapshotIncludesTranslatedMenusWhenWpmlRowsUseTermTaxonomyIds(): void
    {
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

        $snapshot = (new WordPressMenusAdapter())->exportSnapshot();

        self::assertSame(['footer-menu-en', 'footer-menu-fr'], $snapshot->orderedLogicalKeys);
    }

    public function testExportSnapshotIncludesTranslatedMenusWhenFallbackRowsOnlyContainElementIds(): void
    {
        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        $menuFrId = (int) wp_create_nav_menu('Footer menu FR');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'footer-menu-fr']);

        $GLOBALS['pushpull_test_terms']['nav_menu'][$menuEnId]->term_taxonomy_id = 101;
        $GLOBALS['pushpull_test_terms']['nav_menu'][$menuFrId]->term_taxonomy_id = 102;
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'element_id' => 101,
            ],
            [
                'element_id' => 102,
            ],
        ];
        $GLOBALS['pushpull_test_wpml_current_language'] = 'fr';

        $snapshot = (new WordPressMenusAdapter())->exportSnapshot();

        self::assertSame(['footer-menu-en', 'footer-menu-fr'], $snapshot->orderedLogicalKeys);
    }

    public function testExportSnapshotIncludesTranslatedMenusWhenGetTermIsLanguageFiltered(): void
    {
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

        $snapshot = (new WordPressMenusAdapter())->exportSnapshot();

        self::assertSame(['footer-menu-en', 'footer-menu-fr'], $snapshot->orderedLogicalKeys);
    }

    public function testExportSnapshotSharesLocationsAcrossTranslatedMenus(): void
    {
        update_option('icl_sitepress_settings', [
            'default_language' => 'fr',
            'active_languages' => ['fr', 'en'],
        ]);
        $menuEnId = (int) wp_create_nav_menu('Main menu');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'main-menu']);
        $menuFrId = (int) wp_create_nav_menu('Menu principal');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'menu-principal']);
        set_theme_mod('nav_menu_locations', [
            'primary' => $menuFrId,
        ]);
        $GLOBALS['pushpull_test_wpml_translations'] = [
            ['element_type' => 'tax_nav_menu', 'element_id' => $menuEnId, 'trid' => 500, 'language_code' => 'en', 'source_language_code' => 'fr'],
            ['element_type' => 'tax_nav_menu', 'element_id' => $menuFrId, 'trid' => 500, 'language_code' => 'fr', 'source_language_code' => null],
        ];
        $GLOBALS['pushpull_test_wpml_current_language'] = 'en';

        $snapshot = (new WordPressMenusAdapter())->exportSnapshot();
        $locationsByKey = [];

        foreach ($snapshot->items as $item) {
            $locationsByKey[$item->logicalKey] = $item->payload['locations'];
        }

        self::assertSame(['primary'], $locationsByKey['main-menu']);
        self::assertSame(['primary'], $locationsByKey['menu-principal']);
    }

    public function testExportSnapshotKeepsTranslatedPageReferencesWhenCurrentLanguageDiffers(): void
    {
        update_option(\PushPull\Settings\SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_pages', 'wordpress_menus', 'translation_management'],
        ]);

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Accueil', 'accueil', 'publish', 0, 'page'),
        ];

        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        wp_update_nav_menu_item($menuEnId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => 10,
            'menu-item-position' => 1,
            'menu-item-status' => 'publish',
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
            [
                'translation_id' => 2,
                'element_type' => 'post_page',
                'element_id' => 11,
                'trid' => 100,
                'language_code' => 'fr',
                'source_language_code' => 'en',
            ],
        ];
        $GLOBALS['pushpull_test_wpml_current_language'] = 'fr';

        $snapshot = (new WordPressMenusAdapter())->exportSnapshot();

        self::assertSame('page:home', $snapshot->items[0]->payload['items'][0]['itemKey']);
        self::assertSame('home', $snapshot->items[0]->payload['items'][0]['reference']['objectRef']['logicalKey']);
    }
}
