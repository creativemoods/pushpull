<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Discovery\WordPressDomainDiscovery;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\WordPress\WordPressPagesAdapter;

final class ManagedSetRegistryDynamicDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_post_types'] = [
            'page' => new \WP_Post_Type('page', 'Pages', true, true, true),
            'le_event' => new \WP_Post_Type('le_event', 'Events', false, true, false),
        ];
        $GLOBALS['pushpull_test_taxonomies'] = [
            'le_region' => new \WP_Taxonomy('le_region', 'Regions', true, true, false, ['le_event']),
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pushpull_test_post_types'], $GLOBALS['pushpull_test_taxonomies']);
    }

    public function testRegistryIncludesRuntimeDiscoveredCustomAdapters(): void
    {
        $discovery = new WordPressDomainDiscovery();
        $registry = new ManagedSetRegistry(
            [new WordPressPagesAdapter()],
            [
                static fn () => array_merge(
                    $discovery->discoverCustomPostTypeAdapters(),
                    $discovery->discoverCustomTaxonomyAdapters()
                ),
            ]
        );

        self::assertTrue($registry->has('custom_post_type_le_event'));
        self::assertTrue($registry->has('custom_taxonomy_le_region'));
    }
}
