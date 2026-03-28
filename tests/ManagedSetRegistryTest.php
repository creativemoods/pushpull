<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Content\WordPress\WordPressPagesAdapter;
use PushPull\Content\WordPress\WordPressPostsAdapter;

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
            new GeneratePressElementsAdapter(),
            new WordPressPostsAdapter(),
            new WordPressPagesAdapter(),
        ]);

        self::assertSame(
            ['wordpress_attachments', 'wordpress_posts', 'wordpress_pages', 'generatepress_elements'],
            array_keys($registry->allInDependencyOrder())
        );
    }
}
