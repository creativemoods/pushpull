<?php

declare(strict_types=1);

namespace PushPull\Secrets;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use RuntimeException;

final class CompositeSecretEnvelopeResolver implements SecretEnvelopeResolverInterface
{
    /**
     * @param SecretEnvelopeResolverInterface[] $resolvers
     */
    public function __construct(private readonly array $resolvers)
    {
    }

    public function supports(array $envelope): bool
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($envelope)) {
                return true;
            }
        }

        return false;
    }

    public function resolve(array $envelope): string
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($envelope)) {
                return $resolver->resolve($envelope);
            }
        }

        $provider = is_string($envelope['provider'] ?? null) ? (string) $envelope['provider'] : 'unknown';

        throw new RuntimeException(sprintf('No secret resolver is available for provider "%s".', $provider));
    }
}
