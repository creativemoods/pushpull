<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressMenusAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressMenusApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressMenusAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

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

        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressMenusAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new ContentMapRepository($this->wpdb),
            new WorkingStateRepository($this->wpdb)
        );
    }

    public function testApplyRestoresMenuItemsAndLocationsUsingDestinationPageIds(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Blog', 'blog', 'publish', 0, 'page'),
        ];

        $menuId = (int) wp_create_nav_menu('Header Navigation');
        wp_update_term($menuId, 'nav_menu', ['slug' => 'header-navigation']);
        set_theme_mod('nav_menu_locations', ['primary' => $menuId]);

        $homeItemId = wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => 10,
            'menu-item-position' => 1,
            'menu-item-status' => 'publish',
        ]);

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Blog',
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => 11,
            'menu-item-parent-id' => $homeItemId,
            'menu-item-position' => 2,
            'menu-item-status' => 'publish',
        ]);

        $snapshot = $this->adapter->exportSnapshot();
        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(92, 'Blog', 'blog', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_terms']['nav_menu'] = [];
        $GLOBALS['pushpull_test_theme_mods'] = [];
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new \WP_Post(201, 'Legacy', 'legacy', 'publish', 0, 'nav_menu_item');
        $GLOBALS['pushpull_test_generateblocks_meta'][201]['_menu_item_menu_term_id'] = 999;

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
            ['wordpress_pages', 'wordpress_menus']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertSame(0, $result->updatedCount);

        $menus = wp_get_nav_menus();
        self::assertCount(1, $menus);
        self::assertSame('header-navigation', $menus[0]->slug);
        self::assertSame(['primary' => (int) $menus[0]->term_id], get_theme_mod('nav_menu_locations'));

        $items = wp_get_nav_menu_items((int) $menus[0]->term_id);
        self::assertCount(2, $items);
        self::assertSame(91, $items[0]->object_id);
        self::assertSame(92, $items[1]->object_id);
        self::assertSame((int) $items[0]->ID, (int) $items[1]->menu_item_parent);
    }

    public function testApplyOverwritesExistingMenuWithSameName(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
        ];

        $sourceMenuId = (int) wp_create_nav_menu('Header Navigation');
        wp_update_term($sourceMenuId, 'nav_menu', ['slug' => 'header-navigation']);
        wp_update_nav_menu_item($sourceMenuId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => 10,
            'menu-item-position' => 1,
            'menu-item-status' => 'publish',
        ]);

        $snapshot = $this->adapter->exportSnapshot();
        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Home', 'home', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_terms']['nav_menu'] = [];
        $GLOBALS['pushpull_test_theme_mods'] = [];
        $existingMenuId = (int) wp_create_nav_menu('Header Navigation');
        wp_update_term($existingMenuId, 'nav_menu', ['slug' => 'legacy-header-navigation']);
        wp_update_nav_menu_item($existingMenuId, 0, [
            'menu-item-title' => 'Old link',
            'menu-item-type' => 'custom',
            'menu-item-url' => 'https://example.com/old',
            'menu-item-position' => 1,
            'menu-item-status' => 'publish',
        ]);

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
            ['wordpress_pages', 'wordpress_menus']
        ));

        self::assertSame(0, $result->createdCount);
        self::assertSame(1, $result->updatedCount);

        $menus = wp_get_nav_menus();
        self::assertCount(1, $menus);
        self::assertSame($existingMenuId, (int) $menus[0]->term_id);
        self::assertSame('header-navigation', $menus[0]->slug);

        $items = wp_get_nav_menu_items($existingMenuId);
        self::assertCount(1, $items);
        self::assertSame(91, $items[0]->object_id);
        self::assertSame('Home', $items[0]->title);
    }
}
