<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Discovery\WordPressDomainDiscovery;

final class WordPressDomainDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_post_types'] = [
            'attachment' => new \WP_Post_Type('attachment', 'Media', false, true, true),
            'custom_css' => new \WP_Post_Type('custom_css', 'Custom CSS', false, true, true),
            'gblocks_condition' => new \WP_Post_Type('gblocks_condition', 'Conditions', false, true, false),
            'gblocks_styles' => new \WP_Post_Type('gblocks_styles', 'Global Styles', false, true, false),
            'gp_elements' => new \WP_Post_Type('gp_elements', 'Elements', false, true, false),
            'nav_menu_item' => new \WP_Post_Type('nav_menu_item', 'Menu Item', false, false, true),
            'post' => new \WP_Post_Type('post', 'Posts', false, true, true),
            'page' => new \WP_Post_Type('page', 'Pages', true, true, true),
            'le_event' => new \WP_Post_Type('le_event', 'Events', false, true, false),
            'le_location' => new \WP_Post_Type('le_location', 'Locations', true, true, false),
            'internal_hidden' => new \WP_Post_Type('internal_hidden', 'Internal Hidden', false, false, false),
            'wp_block' => new \WP_Post_Type('wp_block', 'Patterns', false, true, true),
        ];
        $GLOBALS['pushpull_test_taxonomies'] = [
            'category' => new \WP_Taxonomy('category', 'Categories', true, true, true, ['post']),
            'gblocks_condition_cat' => new \WP_Taxonomy('gblocks_condition_cat', 'Condition Categories', false, true, false, ['gblocks_condition']),
            'gblocks_pattern_collections' => new \WP_Taxonomy('gblocks_pattern_collections', 'Pattern Collections', false, true, false, ['wp_block']),
            'language' => new \WP_Taxonomy('language', 'Languages', false, true, false, ['wp_block']),
            'nav_menu' => new \WP_Taxonomy('nav_menu', 'Menus', true, false, true, ['nav_menu_item']),
            'post_tag' => new \WP_Taxonomy('post_tag', 'Tags', false, true, true, ['post']),
            'le_event_type' => new \WP_Taxonomy('le_event_type', 'Event Types', false, true, false, ['le_event']),
            'le_region' => new \WP_Taxonomy('le_region', 'Regions', true, true, false, ['le_event', 'le_location']),
            'internal_hidden_tax' => new \WP_Taxonomy('internal_hidden_tax', 'Internal Hidden Tax', false, false, false, ['le_event']),
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pushpull_test_post_types'], $GLOBALS['pushpull_test_taxonomies']);
    }

    public function testDiscoverCustomPostTypesExcludesCuratedAndHiddenTypes(): void
    {
        $discovery = new WordPressDomainDiscovery();

        self::assertSame(
            [
                ['slug' => 'le_event', 'label' => 'Events', 'hierarchical' => false],
                ['slug' => 'le_location', 'label' => 'Locations', 'hierarchical' => true],
            ],
            $discovery->discoverCustomPostTypes()
        );
    }

    public function testDiscoverCustomTaxonomiesExcludesCuratedAndHiddenTaxonomies(): void
    {
        $discovery = new WordPressDomainDiscovery();

        self::assertSame(
            [
                ['slug' => 'le_event_type', 'label' => 'Event Types', 'hierarchical' => false, 'objectTypes' => ['le_event']],
                ['slug' => 'le_region', 'label' => 'Regions', 'hierarchical' => true, 'objectTypes' => ['le_event', 'le_location']],
            ],
            $discovery->discoverCustomTaxonomies()
        );
    }
}
