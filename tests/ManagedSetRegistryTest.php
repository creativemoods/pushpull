<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\Media\RmlMediaOrganizationAdapter;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Content\WordPress\WordPressCoreConfigurationAdapter;
use PushPull\Content\WordPress\WordPressCategoriesAdapter;
use PushPull\Content\WordPress\WordPressMenusAdapter;
use PushPull\Content\WordPress\WordPressPagesAdapter;
use PushPull\Content\WordPress\WordPressPostsAdapter;
use PushPull\Content\WordPress\WordPressTagsAdapter;

final class ManagedSetRegistryTest extends TestCase
{
    public function testDependencyOrderingPlacesGeneratePressElementsAfterPagesAndPosts(): void
    {
        $registry = new ManagedSetRegistry([
            new GeneratePressElementsAdapter(),
            new WordPressPostsAdapter(),
            new WordPressPagesAdapter(),
        ]);

        self::assertSame(
            ['wordpress_posts', 'wordpress_pages', 'generatepress_elements'],
            array_keys($registry->allInDependencyOrder())
        );
    }

    public function testDependencyOrderingCanSortSubsetOfManagedSetKeys(): void
    {
        $registry = new ManagedSetRegistry([
            new GeneratePressElementsAdapter(),
            new WordPressPostsAdapter(),
            new WordPressPagesAdapter(),
        ]);

        self::assertSame(
            ['wordpress_pages', 'generatepress_elements'],
            $registry->sortManagedSetKeysInDependencyOrder(['generatepress_elements', 'wordpress_pages'])
        );
    }

    public function testDependencyOrderingPlacesAttachmentsFirst(): void
    {
        $registry = new ManagedSetRegistry([
            new WordPressAttachmentsAdapter(),
            new WordPressCategoriesAdapter(),
            new RmlMediaOrganizationAdapter(new \PushPull\Settings\SettingsRepository()),
            new WordPressCoreConfigurationAdapter(),
            new GeneratePressElementsAdapter(),
            new WordPressMenusAdapter(),
            new WordPressPostsAdapter(),
            new WordPressPagesAdapter(),
            new WordPressTagsAdapter(),
        ]);

        self::assertSame(
            ['wordpress_attachments', 'wordpress_categories', 'media_organization', 'wordpress_posts', 'wordpress_pages', 'wordpress_core_configuration', 'generatepress_elements', 'wordpress_tags', 'wordpress_menus'],
            array_keys($registry->allInDependencyOrder())
        );
    }
}
