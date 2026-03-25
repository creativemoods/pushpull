<?php

declare(strict_types=1);

namespace PushPull\Persistence;

final class TableNames
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function repoBlobs(): string
    {
        return $this->prefix . 'pushpull_repo_blobs';
    }

    public function repoTrees(): string
    {
        return $this->prefix . 'pushpull_repo_trees';
    }

    public function repoCommits(): string
    {
        return $this->prefix . 'pushpull_repo_commits';
    }

    public function repoRefs(): string
    {
        return $this->prefix . 'pushpull_repo_refs';
    }

    public function repoWorkingState(): string
    {
        return $this->prefix . 'pushpull_repo_working_state';
    }

    public function repoOperations(): string
    {
        return $this->prefix . 'pushpull_repo_operations';
    }

    public function contentMap(): string
    {
        return $this->prefix . 'pushpull_content_map';
    }
}
