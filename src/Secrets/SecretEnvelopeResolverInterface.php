<?php

declare(strict_types=1);

namespace PushPull\Secrets;

interface SecretEnvelopeResolverInterface
{
    /**
     * @param array<string, mixed> $envelope
     */
    public function supports(array $envelope): bool;

    /**
     * @param array<string, mixed> $envelope
     */
    public function resolve(array $envelope): string;
}
