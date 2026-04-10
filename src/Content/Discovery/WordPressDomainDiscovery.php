<?php

declare(strict_types=1);

namespace PushPull\Content\Discovery;

use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Content\WordPress\GenericWordPressCustomPostTypeAdapter;
use PushPull\Content\WordPress\GenericWordPressCustomTaxonomyAdapter;

final class WordPressDomainDiscovery
{
    /**
     * @var array<string, true>
     */
    private const CURATED_POST_TYPES = [
        'attachment' => true,
        'custom_css' => true,
        'gp_elements' => true,
        'gblocks_condition' => true,
        'gblocks_styles' => true,
        'nav_menu_item' => true,
        'page' => true,
        'post' => true,
        'wp_block' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const EXCLUDED_POST_TYPES = [
        'customize_changeset' => true,
        'oembed_cache' => true,
        'revision' => true,
        'user_request' => true,
        'wp_font_face' => true,
        'wp_font_family' => true,
        'wp_global_styles' => true,
        'wp_navigation' => true,
        'wp_template' => true,
        'wp_template_part' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const CURATED_TAXONOMIES = [
        'category' => true,
        'gblocks_condition_cat' => true,
        'gblocks_pattern_collections' => true,
        'language' => true,
        'nav_menu' => true,
        'post_tag' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const EXCLUDED_TAXONOMIES = [
        'link_category' => true,
        'nav_menu_item' => true,
        'post_format' => true,
        'wp_theme' => true,
    ];

    /**
     * @return array<int, array{slug: string, label: string, hierarchical: bool}>
     */
    public function discoverCustomPostTypes(): array
    {
        if (! function_exists('get_post_types')) {
            return [];
        }

        $objects = get_post_types(['show_ui' => true], 'objects');

        if (! is_array($objects)) {
            return [];
        }

        $discovered = [];

        foreach ($objects as $slug => $object) {
            $slug = sanitize_key((string) $slug);

            if (
                $slug === ''
                || isset(self::CURATED_POST_TYPES[$slug])
                || isset(self::EXCLUDED_POST_TYPES[$slug])
            ) {
                continue;
            }

            $label = trim((string) ($object->label ?? $object->labels->name ?? $slug));

            $discovered[] = [
                'slug' => $slug,
                'label' => $label !== '' ? $label : $slug,
                'hierarchical' => ! empty($object->hierarchical),
            ];
        }

        usort($discovered, static fn (array $left, array $right): int => [$left['label'], $left['slug']] <=> [$right['label'], $right['slug']]);

        return $discovered;
    }

    /**
     * @return array<int, array{slug: string, label: string, hierarchical: bool, objectTypes: array<int, string>}>
     */
    public function discoverCustomTaxonomies(): array
    {
        if (! function_exists('get_taxonomies')) {
            return [];
        }

        $objects = get_taxonomies(['show_ui' => true], 'objects');

        if (! is_array($objects)) {
            return [];
        }

        $discovered = [];

        foreach ($objects as $slug => $object) {
            $slug = sanitize_key((string) $slug);

            if (
                $slug === ''
                || isset(self::CURATED_TAXONOMIES[$slug])
                || isset(self::EXCLUDED_TAXONOMIES[$slug])
            ) {
                continue;
            }

            $label = trim((string) ($object->label ?? $object->labels->name ?? $slug));
            $objectTypes = isset($object->object_type) && is_array($object->object_type)
                ? array_values(array_filter(array_map('strval', $object->object_type)))
                : [];
            sort($objectTypes);

            $discovered[] = [
                'slug' => $slug,
                'label' => $label !== '' ? $label : $slug,
                'hierarchical' => ! empty($object->hierarchical),
                'objectTypes' => $objectTypes,
            ];
        }

        usort($discovered, static fn (array $left, array $right): int => [$left['label'], $left['slug']] <=> [$right['label'], $right['slug']]);

        return $discovered;
    }

    /**
     * @return ManifestManagedContentAdapterInterface[]
     */
    public function discoverCustomPostTypeAdapters(): array
    {
        return array_map(
            static fn (array $postType): ManifestManagedContentAdapterInterface => new GenericWordPressCustomPostTypeAdapter(
                $postType['slug'],
                $postType['label']
            ),
            $this->discoverCustomPostTypes()
        );
    }

    /**
     * @return ManifestManagedContentAdapterInterface[]
     */
    public function discoverCustomTaxonomyAdapters(): array
    {
        return array_map(
            static fn (array $taxonomy): ManifestManagedContentAdapterInterface => new GenericWordPressCustomTaxonomyAdapter(
                $taxonomy['slug'],
                $taxonomy['label']
            ),
            $this->discoverCustomTaxonomies()
        );
    }
}
