<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\WordPress\WordPressPostsAdapter;

final class WordPressPostsAdapterTest extends TestCase
{
    public function testSlugProducesLogicalKey(): void
    {
        $adapter = new WordPressPostsAdapter();

        self::assertSame('hello-world', $adapter->computeLogicalKey(['post_name' => 'hello-world']));
    }

    public function testSerializationCapturesPostContentWithoutRawMetaNoise(): void
    {
        $adapter = new WordPressPostsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "wordpress_post"', $json);
        self::assertStringContainsString('"postContent"', $json);
        self::assertStringContainsString('Hello Lisbon', $json);
        self::assertStringContainsString('"restoration"', $json);
        self::assertStringNotContainsString('"postMeta"', $json);
        self::assertStringNotContainsString('"terms"', $json);
    }

    public function testManifestAndItemPathsAreDeterministic(): void
    {
        $adapter = new WordPressPostsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());

        self::assertSame('wordpress/posts/hello-world.json', $adapter->getRepositoryPath($item));
        self::assertSame('wordpress/posts/manifest.json', $adapter->getManifestPath());
    }

    public function testDeserializationRoundTripsCanonicalItem(): void
    {
        $adapter = new WordPressPostsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $deserialized = $adapter->deserialize($adapter->getRepositoryPath($item), $adapter->serialize($item));

        self::assertSame($adapter->serialize($item), $adapter->serialize($deserialized));
    }

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new WordPressPostsAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey(['post_name' => '']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeRecord(): array
    {
        return [
            'wp_object_id' => 42,
            'post_title' => 'Hello World',
            'post_name' => 'hello-world',
            'post_status' => 'publish',
            'post_content' => "<!-- wp:paragraph --><p>Hello Lisbon.</p><!-- /wp:paragraph -->",
            'post_meta' => [
                ['meta_key' => '_edit_lock', 'meta_value' => '1'],
            ],
            'terms' => [],
        ];
    }
}
