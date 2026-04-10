<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

final class WordPressTagsAdapter extends AbstractWordPressTaxonomyAdapter
{
    protected function managedSetKey(): string
    {
        return 'wordpress_tags';
    }

    protected function managedSetLabel(): string
    {
        return 'WordPress tags';
    }

    protected function contentType(): string
    {
        return 'wordpress_tag';
    }

    protected function manifestType(): string
    {
        return 'wordpress_tags_manifest';
    }

    protected function repositoryPathPrefix(): string
    {
        return 'wordpress/tags';
    }

    protected function commitMessage(): string
    {
        return 'Commit live WordPress tags';
    }

    protected function taxonomy(): string
    {
        return 'post_tag';
    }
}
