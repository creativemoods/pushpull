<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\WordPress\WordPressCategoriesAdapter;

final class WordPressCategoriesAdapterTest extends TestCase
{
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

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new WordPressCategoriesAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey(['slug' => '', 'name' => '']);
    }
}
