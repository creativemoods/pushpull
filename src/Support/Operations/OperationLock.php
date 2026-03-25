<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

final class OperationLock
{
    public function __construct(
        public readonly string $optionKey,
        public readonly string $token
    ) {
    }
}
