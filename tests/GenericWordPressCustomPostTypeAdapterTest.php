<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\GenericWordPressCustomPostTypeAdapter;
use PushPull\Settings\SettingsRepository;

final class GenericWordPressCustomPostTypeAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_post_types'] = [
            'le_event' => new \WP_Post_Type('le_event', 'Events', false, true, false),
        ];
        $GLOBALS['pushpull_test_taxonomies'] = [
            'le_event_type' => new \WP_Taxonomy('le_event_type', 'Event Types', false, true, false, ['le_event']),
        ];
        $GLOBALS['pushpull_test_wpml_translations'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pushpull_test_post_types'], $GLOBALS['pushpull_test_taxonomies']);
    }

    public function testSerializationCapturesGenericPostTypeMetaAndTerms(): void
    {
        $adapter = new GenericWordPressCustomPostTypeAdapter('le_event', 'Events');
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 17,
            'post_title' => 'Festival',
            'post_name' => 'festival',
            'post_status' => 'publish',
            'post_content' => '<p>Hello</p>',
            'post_meta' => [
                ['meta_key' => 'startdate', 'meta_value' => '2026-04-10'],
            ],
            'terms' => [
                [
                    'taxonomy' => 'le_event_type',
                    'slug' => 'music',
                    'name' => 'Music',
                    'description' => '',
                    'parentSlug' => '',
                    'termMeta' => [],
                ],
            ],
        ]);

        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "custom_post_type_le_event"', $json);
        self::assertStringContainsString('"startdate"', $json);
        self::assertStringContainsString('"le_event_type"', $json);
    }

    public function testSerializationDropsWpmlDerivedWordCountMeta(): void
    {
        $adapter = new GenericWordPressCustomPostTypeAdapter('le_event', 'Events');
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 17,
            'post_title' => 'Festival',
            'post_name' => 'festival',
            'post_status' => 'publish',
            'post_content' => '<p>Hello</p>',
            'post_meta' => [
                ['meta_key' => '_wpml_word_count', 'meta_value' => '44'],
                ['meta_key' => 'startdate', 'meta_value' => '2026-04-10'],
            ],
            'terms' => [],
        ]);

        $json = $adapter->serialize($item);

        self::assertStringContainsString('"startdate"', $json);
        self::assertStringNotContainsString('"_wpml_word_count"', $json);
    }

    public function testSerializationIncludesWpmlLanguageInRestorationMetadata(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'identifier_managed_sets' => ['custom_post_type_le_event'],
        ]);

        $adapter = new GenericWordPressCustomPostTypeAdapter('le_event', 'Events');
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 17,
            'pushpull_identifier' => 'festival-fr',
            'post_title' => 'Festival',
            'post_name' => 'festival',
            'post_status' => 'publish',
            'post_content' => '<p>Hello</p>',
            'wpml_language' => 'fr',
            'post_meta' => [],
            'terms' => [],
        ]);

        $json = $adapter->serialize($item);

        self::assertStringContainsString('"wpmlLanguage": "fr"', $json);
    }
}
