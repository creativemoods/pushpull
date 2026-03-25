<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class ProviderValidationResult
{
    /**
     * @param string[] $messages
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $messages = []
    ) {
    }

    public function isValid(): bool
    {
        return $this->valid;
    }
}
