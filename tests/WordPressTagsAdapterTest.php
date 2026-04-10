<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressTagsAdapter;

final class WordPressTagsAdapterTest extends TestCase
{
    public function testSerializationCapturesTagFieldsAndMeta(): void
    {
        $adapter = new WordPressTagsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 20,
            'slug' => 'featured',
            'name' => 'Featured',
            'description' => 'Featured content',
            'parentSlug' => '',
            'termMeta' => [
                ['meta_key' => 'visibility', 'meta_value' => 'public'],
            ],
        ]);
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "wordpress_tag"', $json);
        self::assertStringContainsString('"name": "Featured"', $json);
        self::assertStringContainsString('"visibility"', $json);
    }
}
