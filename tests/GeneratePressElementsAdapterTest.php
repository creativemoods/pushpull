<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;

final class GeneratePressElementsAdapterTest extends TestCase
{
    public function testSerializationKeepsOwnedGeneratePressMetaAndDropsNoise(): void
    {
        $adapter = new GeneratePressElementsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "generatepress_element"', $json);
        self::assertStringContainsString('"_generate_element_type"', $json);
        self::assertStringContainsString('"_generate_element_display_conditions"', $json);
        self::assertStringContainsString('"_generate_block_type"', $json);
        self::assertStringNotContainsString('"_wpml_word_count"', $json);
        self::assertStringNotContainsString('"copied_media_ids"', $json);
        self::assertStringNotContainsString('"pmpro_default_level"', $json);
    }

    public function testManifestAndItemPathsAreDeterministic(): void
    {
        $adapter = new GeneratePressElementsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());

        self::assertSame('generatepress/elements/header-cta.json', $adapter->getRepositoryPath($item));
        self::assertSame('generatepress/elements/manifest.json', $adapter->getManifestPath());
    }

    public function testDeserializationRoundTripsCanonicalItem(): void
    {
        $adapter = new GeneratePressElementsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $deserialized = $adapter->deserialize($adapter->getRepositoryPath($item), $adapter->serialize($item));

        self::assertSame($adapter->serialize($item), $adapter->serialize($deserialized));
    }

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new GeneratePressElementsAdapter();

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
            'post_title' => 'Header CTA',
            'post_name' => 'header-cta',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>CTA</p><!-- /wp:paragraph -->',
            'post_meta' => [
                ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
                ['meta_key' => '_generate_hook', 'meta_value' => 'generate_after_header'],
                ['meta_key' => '_generate_element_display_conditions', 'meta_value' => [['location' => 'entire-site']]],
                ['meta_key' => '_generate_block_type', 'meta_value' => 'hook'],
                ['meta_key' => '_generateblocks_dynamic_css_version', 'meta_value' => '2.1.2'],
                ['meta_key' => '_wpml_word_count', 'meta_value' => '44'],
                ['meta_key' => 'copied_media_ids', 'meta_value' => []],
                ['meta_key' => 'pmpro_default_level', 'meta_value' => '2'],
            ],
            'terms' => [],
        ];
    }
}
