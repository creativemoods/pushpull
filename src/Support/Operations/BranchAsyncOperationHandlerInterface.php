<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationRecord;

interface BranchAsyncOperationHandlerInterface
{
    public function supports(string $operationType): bool;

    /**
     * @param array<string, mixed> $baseState
     * @return array<string, mixed>
     */
    public function initialize(OperationRecord $record, array $baseState, string $branch): array;

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    public function continue(OperationRecord $record, array $state): array;
}
