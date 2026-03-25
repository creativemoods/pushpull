<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class CreateRemoteCommitRequest
{
    /**
     * @param string[] $parentHashes
     */
    public function __construct(
        public readonly string $treeHash,
        public readonly array $parentHashes,
        public readonly string $message,
        public readonly string $authorName,
        public readonly string $authorEmail
    ) {
    }
}
