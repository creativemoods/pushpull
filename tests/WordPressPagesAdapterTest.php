<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\WordPress\WordPressPagesAdapter;

final class WordPressPagesAdapterTest extends TestCase
{
    public function testSlugProducesLogicalKey(): void
    {
        $adapter = new WordPressPagesAdapter();

        self::assertSame('about-us', $adapter->computeLogicalKey(['post_name' => 'about-us']));
    }

    public function testSerializationCapturesPageContentWithoutRawMetaNoise(): void
    {
        $adapter = new WordPressPagesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "wordpress_page"', $json);
        self::assertStringContainsString('"postContent"', $json);
        self::assertStringContainsString('Welcome to Lisbon', $json);
        self::assertStringContainsString('"restoration"', $json);
        self::assertStringNotContainsString('"postMeta"', $json);
        self::assertStringNotContainsString('"terms"', $json);
    }

    public function testManifestAndItemPathsAreDeterministic(): void
    {
        $adapter = new WordPressPagesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());

        self::assertSame('wordpress/pages/about-us.json', $adapter->getRepositoryPath($item));
        self::assertSame('wordpress/pages/manifest.json', $adapter->getManifestPath());
    }

    public function testDeserializationRoundTripsCanonicalItem(): void
    {
        $adapter = new WordPressPagesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $deserialized = $adapter->deserialize($adapter->getRepositoryPath($item), $adapter->serialize($item));

        self::assertSame($adapter->serialize($item), $adapter->serialize($deserialized));
    }

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new WordPressPagesAdapter();

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
            'post_title' => 'About Us',
            'post_name' => 'about-us',
            'post_status' => 'publish',
            'post_content' => "<!-- wp:paragraph --><p>Welcome to Lisbon.</p><!-- /wp:paragraph -->",
            'post_meta' => [
                ['meta_key' => '_edit_lock', 'meta_value' => '1'],
            ],
            'terms' => [],
        ];
    }
}
