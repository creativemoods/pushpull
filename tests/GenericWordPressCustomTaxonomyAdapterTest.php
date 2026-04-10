<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\GenericWordPressCustomTaxonomyAdapter;

final class GenericWordPressCustomTaxonomyAdapterTest extends TestCase
{
    public function testSerializationCapturesGenericTaxonomyFieldsAndMeta(): void
    {
        $adapter = new GenericWordPressCustomTaxonomyAdapter('le_region', 'Regions');
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 4,
            'slug' => 'vaud',
            'name' => 'Vaud',
            'description' => 'Swiss region',
            'parentSlug' => '',
            'termMeta' => [
                ['meta_key' => 'code', 'meta_value' => 'VD'],
            ],
        ]);

        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "custom_taxonomy_le_region"', $json);
        self::assertStringContainsString('"code"', $json);
    }
}
