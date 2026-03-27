<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\WordPress\WordPressCustomCssAdapter;

final class WordPressCustomCssAdapterTest extends TestCase
{
    public function testSlugProducesLogicalKey(): void
    {
        $adapter = new WordPressCustomCssAdapter();

        self::assertSame('newlisboaevents', $adapter->computeLogicalKey(['post_name' => 'newlisboaevents']));
    }

    public function testSerializationCapturesCustomCssContentWithoutRawMetaNoise(): void
    {
        $adapter = new WordPressCustomCssAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "wordpress_custom_css"', $json);
        self::assertStringContainsString('"postContent"', $json);
        self::assertStringContainsString('.sticky-sidebar', $json);
        self::assertStringContainsString('"restoration"', $json);
        self::assertStringNotContainsString('"postMeta"', $json);
        self::assertStringNotContainsString('"terms"', $json);
    }

    public function testManifestAndItemPathsAreDeterministic(): void
    {
        $adapter = new WordPressCustomCssAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());

        self::assertSame('wordpress/custom-css/newlisboaevents.json', $adapter->getRepositoryPath($item));
        self::assertSame('wordpress/custom-css/manifest.json', $adapter->getManifestPath());
    }

    public function testDeserializationRoundTripsCanonicalItem(): void
    {
        $adapter = new WordPressCustomCssAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $deserialized = $adapter->deserialize($adapter->getRepositoryPath($item), $adapter->serialize($item));

        self::assertSame($adapter->serialize($item), $adapter->serialize($deserialized));
    }

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new WordPressCustomCssAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey(['post_name' => '']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeRecord(): array
    {
        return [
            'wp_object_id' => 14744,
            'post_title' => 'newlisboaevents',
            'post_name' => 'newlisboaevents',
            'post_status' => 'publish',
            'post_content' => ".sticky-sidebar {\n  position: sticky;\n  top: 100px;\n}\n.promo-bar .promo-close {\n  cursor: pointer;\n}\n",
            'post_meta' => [
                ['meta_key' => '_edit_lock', 'meta_value' => '1'],
            ],
            'terms' => [],
        ];
    }
}
