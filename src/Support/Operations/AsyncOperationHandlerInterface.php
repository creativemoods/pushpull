<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationRecord;

interface AsyncOperationHandlerInterface
{
    public function supportsAsyncOperation(string $operationType): bool;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function initializeAsyncOperation(OperationRecord $record, array $context, string $lockToken): array;

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    public function continueAsyncOperation(OperationRecord $record, array $state): array;

    /**
     * @param array<string, mixed> $finalResult
     */
    public function finalizeAsyncOperation(array $finalResult): void;
}
