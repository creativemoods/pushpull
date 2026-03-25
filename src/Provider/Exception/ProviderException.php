<?php

declare(strict_types=1);

namespace PushPull\Provider\Exception;

use RuntimeException;

final class ProviderException extends RuntimeException
{
    public const AUTHENTICATION = 'authentication';
    public const AUTHORIZATION = 'authorization';
    public const REPOSITORY_NOT_FOUND = 'repository_not_found';
    public const REF_NOT_FOUND = 'ref_not_found';
    public const VALIDATION = 'validation';
    public const CONFLICT = 'conflict';
    public const EMPTY_REPOSITORY = 'empty_repository';
    public const RATE_LIMIT = 'rate_limit';
    public const SERVICE_UNAVAILABLE = 'service_unavailable';
    public const UNSUPPORTED_RESPONSE = 'unsupported_response';
    public const TRANSPORT = 'transport';

    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $operation = null,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }

    public function debugSummary(): string
    {
        $details = ['category=' . $this->category];

        if ($this->statusCode !== null) {
            $details[] = 'status=' . $this->statusCode;
        }

        if ($this->operation !== null && $this->operation !== '') {
            $details[] = 'operation=' . $this->operation;
        }

        foreach ($this->context as $key => $value) {
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $details[] = $key . '=' . (string) $value;
        }

        return $this->getMessage() . ' [' . implode(', ', $details) . ']';
    }
}
