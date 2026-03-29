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
        self::assertSame('page', $snapshot->items[0]->payload['items'][0]['reference']['objectRef']['postType']);
        self::assertSame('home', $snapshot->items[0]->payload['items'][0]['reference']['objectRef']['logicalKey']);
        self::assertSame('{{pushpull.home_url}}/about', $snapshot->items[0]->payload['items'][1]['url']);
    }
}
