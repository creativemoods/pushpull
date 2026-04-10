<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

final class GenericWordPressCustomTaxonomyAdapter extends AbstractWordPressTaxonomyAdapter
{
    public function __construct(
        private readonly string $customTaxonomy,
        private readonly string $label
    ) {
    }

    public static function managedSetKeyForTaxonomy(string $taxonomy): string
    {
        return 'custom_taxonomy_' . sanitize_key($taxonomy);
    }

    protected function managedSetKey(): string
    {
        return self::managedSetKeyForTaxonomy($this->customTaxonomy);
    }

    protected function managedSetLabel(): string
    {
        return $this->label;
    }

    protected function contentType(): string
    {
        return 'custom_taxonomy_' . sanitize_key($this->customTaxonomy);
    }

    protected function manifestType(): string
    {
        return 'custom_taxonomy_manifest_' . sanitize_key($this->customTaxonomy);
    }

    protected function repositoryPathPrefix(): string
    {
        return 'custom/taxonomies/' . sanitize_key($this->customTaxonomy);
    }

    protected function commitMessage(): string
    {
        return sprintf('Commit live custom taxonomy %s', $this->customTaxonomy);
    }

    protected function taxonomy(): string
    {
        return $this->customTaxonomy;
    }
}
