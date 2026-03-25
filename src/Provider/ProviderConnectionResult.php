<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class ProviderConnectionResult
{
    /**
     * @param string[] $messages
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $repositoryPath,
        public readonly ?string $defaultBranch,
        public readonly ?string $resolvedBranch,
        public readonly bool $emptyRepository = false,
        public readonly array $messages = []
    ) {
    }
}
