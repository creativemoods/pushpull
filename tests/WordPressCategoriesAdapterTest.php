<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\WordPress\WordPressCategoriesAdapter;

final class WordPressCategoriesAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_terms']['category'] = [];
        $GLOBALS['pushpull_test_next_term_id'] = 1;
        $GLOBALS['pushpull_test_wpml_translations'] = [];
        unset($GLOBALS['pushpull_test_wpml_current_language']);
        unset($GLOBALS['pushpull_test_wpml_filter_terms_taxonomies']);
    }

    public function testSerializationCapturesCategoryFieldsAndMeta(): void
    {
        $adapter = new WordPressCategoriesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 10,
            'slug' => 'news',
            'name' => 'News',
            'description' => 'Latest updates',
            'parentSlug' => '',
            'termMeta' => [
                ['meta_key' => 'color', 'meta_value' => 'blue'],
            ],
        ]);
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "wordpress_category"', $json);
        self::assertStringContainsString('"name": "News"', $json);
        self::assertStringContainsString('"description": "Latest updates"', $json);
        self::assertStringContainsString('"termMeta"', $json);
        self::assertStringContainsString('"color"', $json);
    }

    public function testManifestAndItemPathsAreDeterministic(): void
    {
        $adapter = new WordPressCategoriesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord([
            'slug' => 'news',
            'name' => 'News',
            'description' => '',
            'parentSlug' => '',
            'termMeta' => [],
        ]);

        self::assertSame('wordpress/categories/news.json', $adapter->getRepositoryPath($item));
        self::assertSame('wordpress/categories/manifest.json', $adapter->getManifestPath());
    }

    public function testManifestIncludesDefaultCategoryLogicalKey(): void
    {
        update_option('default_category', 12);

        $manifest = (new WordPressCategoriesAdapter())->buildManifest([
            [
                'wp_object_id' => 11,
                'slug' => 'news',
                'name' => 'News',
                'description' => '',
                'parentSlug' => '',
                'termMeta' => [],
            ],
            [
                'wp_object_id' => 12,
                'slug' => 'events',
                'name' => 'Events',
                'description' => '',
                'parentSlug' => '',
                'termMeta' => [],
            ],
        ]);

        self::assertSame('events', $manifest->extra['defaultLogicalKey'] ?? null);
    }

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new WordPressCategoriesAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey(['slug' => '', 'name' => '']);
    }

    public function testExportSnapshotIncludesTranslatedCategoriesMissingFromFilteredTermQuery(): void
    {
        $categoryEnId = (int) wp_insert_term('Uncategorized', 'category', ['slug' => 'uncategorized'])['term_id'];
        $categoryFrId = (int) wp_insert_term('Non catégorisé', 'category', ['slug' => 'uncategorized-fr'])['term_id'];

        $GLOBALS['pushpull_test_terms']['category'][$categoryEnId]->term_taxonomy_id = 201;
        $GLOBALS['pushpull_test_terms']['category'][$categoryFrId]->term_taxonomy_id = 202;
        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'tax_category',
                'element_id' => 201,
                'trid' => 700,
                'language_code' => 'en',
                'source_language_code' => 'fr',
            ],
            [
                'translation_id' => 2,
                'element_type' => 'tax_category',
                'element_id' => 202,
                'trid' => 700,
                'language_code' => 'fr',
                'source_language_code' => null,
            ],
        ];
        $GLOBALS['pushpull_test_wpml_current_language'] = 'fr';
        $GLOBALS['pushpull_test_wpml_filter_terms_taxonomies'] = ['category'];

        $snapshot = (new WordPressCategoriesAdapter())->exportSnapshot();

        self::assertSame(['uncategorized', 'uncategorized-fr'], $snapshot->orderedLogicalKeys);
    }
}
