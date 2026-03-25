<?php

declare(strict_types=1);

namespace PushPull\Content\GenerateBlocks;

final class GenerateBlocksCanonicalHasher
{
    public function hash(string $serializedDocument): string
    {
        return sha1($serializedDocument);
    }
}
