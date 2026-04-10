<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

final class WordPressCategoriesAdapter extends AbstractWordPressTaxonomyAdapter
{
    protected function managedSetKey(): string
    {
        return 'wordpress_categories';
    }

    protected function managedSetLabel(): string
    {
        return 'WordPress categories';
    }

    protected function contentType(): string
    {
        return 'wordpress_category';
    }

    protected function manifestType(): string
    {
        return 'wordpress_categories_manifest';
    }

    protected function repositoryPathPrefix(): string
    {
        return 'wordpress/categories';
    }

    protected function commitMessage(): string
    {
        return 'Commit live WordPress categories';
    }

    protected function taxonomy(): string
    {
        return 'category';
    }
}
