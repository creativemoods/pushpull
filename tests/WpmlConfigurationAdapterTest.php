<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Integration\Wpml\WpmlConfigurationAdapter;
use PushPull\Integration\Wpml\WpmlConfigurationApplier;

final class WpmlConfigurationAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        global $pushpull_test_options;
        global $wpdb;

        $pushpull_test_options = [];
        $GLOBALS['sitepress'] = new \PushPull_Test_SitePress();
        $wpdb = new \wpdb();
        $GLOBALS['pushpull_test_post_types'] = [
            'attachment' => new \WP_Post_Type('attachment', 'Media', false, true, true, [], ['slug' => 'attachment']),
            'custom_css' => new \WP_Post_Type('custom_css', 'Custom CSS', false, true, true, [], ['slug' => 'custom_css']),
            'gp_elements' => new \WP_Post_Type('gp_elements', 'Elements', false, true, false, [], ['slug' => 'gp_elements']),
            'page' => new \WP_Post_Type('page', 'Pages', true, true, true, [], ['slug' => 'page']),
            'post' => new \WP_Post_Type('post', 'Posts', false, true, true, [], ['slug' => 'post']),
        ];
    }

    public function testExportSnapshotNormalizesWpmlConfiguration(): void
    {
        update_option('icl_sitepress_settings', [
            'default_language' => 'fr',
            'active_languages' => ['en', 'fr', 'pt'],
            'language_negotiation_type' => 3,
            'custom_posts_sync_option' => [
                'gp_elements' => 2,
                'page' => 1,
                'post' => 0,
            ],
            'posts_slug_translation' => [
                'types' => [
                    'gp_elements' => 1,
                ],
                'on' => 1,
            ],
            'setup_complete' => 1,
        ]);
        update_option('wpml_base_slug_translation', 1);
        global $wpdb;
        $wpdb->insert('wp_icl_strings', [
            'id' => 32,
            'language' => 'fr',
            'context' => 'WordPress',
            'name' => 'URL slug: gp_elements',
            'value' => 'elements',
        ]);
        $wpdb->insert('wp_icl_string_translations', [
            'id' => 13,
            'string_id' => 32,
            'language' => 'en',
            'value' => 'xyz1234',
            'status' => 10,
        ]);

        $adapter = new WpmlConfigurationAdapter(new WpmlConfigurationApplier());
        $snapshot = $adapter->exportSnapshot();

        self::assertSame(['wpml-settings'], $snapshot->orderedLogicalKeys);
        self::assertCount(1, $snapshot->items);
        self::assertSame('wpml_configuration_settings', $snapshot->items[0]->contentType);
        self::assertSame('fr', $snapshot->items[0]->payload['defaultLanguage']);
        self::assertSame(['fr', 'en', 'pt'], $snapshot->items[0]->payload['activeLanguages']);
        self::assertSame('parameter', $snapshot->items[0]->payload['urlFormat']);
        self::assertSame([
            'gp_elements' => 'translatable_fallback',
            'page' => 'translatable_only',
            'post' => 'not_translatable',
        ], $snapshot->items[0]->payload['postTypeTranslationModes']);
        self::assertSame([
            'gp_elements' => [
                'enabled' => true,
                'originalLanguage' => 'fr',
                'values' => [
                    'en' => 'xyz1234',
                    'fr' => 'gp_elements',
                ],
            ],
        ], $snapshot->items[0]->payload['postTypeSlugTranslations']);
        self::assertTrue($snapshot->items[0]->payload['setupFinished']);
    }

    public function testExportSnapshotFallsBackWhenSlugTranslationProbeMethodIsNotPublic(): void
    {
        update_option('icl_sitepress_settings', [
            'default_language' => 'fr',
            'active_languages' => ['fr', 'en'],
            'posts_slug_translation' => [
                'types' => [
                    'gp_elements' => 1,
                ],
                'on' => 1,
            ],
            'setup_complete' => 1,
        ]);
        update_option('wpml_base_slug_translation', 1);

        $GLOBALS['sitepress'] = new class () {
            private function cpt_slug_translation_turned_on(string $postType): bool
            {
                return $postType === 'gp_elements';
            }
        };

        $adapter = new WpmlConfigurationAdapter(new WpmlConfigurationApplier());
        $snapshot = $adapter->exportSnapshot();

        self::assertSame([
            'gp_elements' => [
                'enabled' => true,
                'originalLanguage' => 'fr',
                'values' => [
                    'en' => 'xyz1234',
                    'fr' => 'gp_elements',
                ],
            ],
        ], $snapshot->items[0]->payload['postTypeSlugTranslations']);
    }

    public function testExportSnapshotRecognizesLegacySlugTranslationFlagWhenGlobalOptionIsMissing(): void
    {
        update_option('icl_sitepress_settings', [
            'default_language' => 'fr',
            'active_languages' => ['fr', 'en'],
            'posts_slug_translation' => [
                'types' => [
                    'gp_elements' => 1,
                ],
                'on' => 1,
            ],
            'setup_complete' => 1,
        ]);
        $GLOBALS['sitepress'] = new \stdClass();

        $adapter = new WpmlConfigurationAdapter(new WpmlConfigurationApplier());
        $snapshot = $adapter->exportSnapshot();

        self::assertSame([
            'gp_elements' => [
                'enabled' => true,
                'originalLanguage' => 'fr',
                'values' => [
                    'en' => 'xyz1234',
                    'fr' => 'gp_elements',
                ],
            ],
        ], $snapshot->items[0]->payload['postTypeSlugTranslations']);
    }

    public function testExportSnapshotRecognizesRegisteredSlugTranslationRecord(): void
    {
        global $wpdb;

        update_option('icl_sitepress_settings', [
            'default_language' => 'fr',
            'active_languages' => ['fr', 'en'],
            'setup_complete' => 1,
        ]);
        $GLOBALS['sitepress'] = new \stdClass();

        $wpdb->insert('wp_icl_strings', [
            'id' => 32,
            'language' => 'fr',
            'context' => 'WordPress',
            'name' => 'URL slug: gp_elements',
            'value' => 'elements',
        ]);

        $adapter = new WpmlConfigurationAdapter(new WpmlConfigurationApplier());
        $snapshot = $adapter->exportSnapshot();

        self::assertSame([
            'gp_elements' => [
                'enabled' => true,
                'originalLanguage' => 'fr',
                'values' => [
                    'en' => 'xyz1234',
                    'fr' => 'gp_elements',
                ],
            ],
        ], $snapshot->items[0]->payload['postTypeSlugTranslations']);
    }

    public function testExportSnapshotPreservesTranslatedSlugWhenOriginalLanguageDiffersFromSiteDefault(): void
    {
        global $wpdb;

        $GLOBALS['pushpull_test_post_types']['le_camp'] = new \WP_Post_Type('le_camp', 'Camps', true, true, true, [], ['slug' => 'camps']);

        update_option('icl_sitepress_settings', [
            'default_language' => 'fr',
            'active_languages' => ['fr', 'en'],
            'posts_slug_translation' => [
                'types' => [
                    'le_camp' => 1,
                ],
                'on' => 1,
            ],
            'setup_complete' => 1,
        ]);
        update_option('wpml_base_slug_translation', 1);

        $wpdb->insert('wp_icl_strings', [
            'id' => 40,
            'language' => 'en',
            'context' => 'WordPress',
            'name' => 'URL slug: le_camp',
            'value' => 'camps',
        ]);
        $wpdb->insert('wp_icl_string_translations', [
            'id' => 41,
            'string_id' => 40,
            'language' => 'fr',
            'value' => 'colos',
            'status' => 10,
        ]);

        $adapter = new WpmlConfigurationAdapter(new WpmlConfigurationApplier());
        $snapshot = $adapter->exportSnapshot();

        self::assertArrayHasKey('le_camp', $snapshot->items[0]->payload['postTypeSlugTranslations']);
        self::assertSame([
            'enabled' => true,
            'originalLanguage' => 'en',
            'values' => [
                'en' => 'camps',
                'fr' => 'colos',
            ],
        ], $snapshot->items[0]->payload['postTypeSlugTranslations']['le_camp']);
    }
}
