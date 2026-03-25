<?php

declare(strict_types=1);

namespace PushPull\Provider\Http;

final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?array $json = null
    ) {
    }
}
