<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class RemoteCommit
{
    /**
     * @param string[] $parents
     * @param array<string, string>|null $author
     * @param array<string, string>|null $committer
     */
    public function __construct(
        public readonly string $hash,
        public readonly string $treeHash,
        public readonly array $parents,
        public readonly string $message,
        public readonly ?array $author = null,
        public readonly ?array $committer = null
    ) {
    }
}
