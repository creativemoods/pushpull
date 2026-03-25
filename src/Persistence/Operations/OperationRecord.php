<?php

declare(strict_types=1);

namespace PushPull\Persistence\Operations;

final class OperationRecord
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     */
    public function __construct(
        public readonly int $id,
        public readonly string $managedSetKey,
        public readonly string $operationType,
        public readonly string $status,
        public readonly array $payload,
        public readonly array $result,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly ?string $finishedAt
    ) {
    }
}
