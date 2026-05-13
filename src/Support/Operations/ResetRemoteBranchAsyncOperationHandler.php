<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationRecord;

final class ResetRemoteBranchAsyncOperationHandler implements BranchAsyncOperationHandlerInterface
{
    public function __construct(private readonly BranchAsyncOperationContextInterface $context)
    {
    }

    public function supports(string $operationType): bool
    {
        return $operationType === 'reset_remote_branch';
    }

    public function initialize(OperationRecord $record, array $baseState, string $branch): array
    {
        return $baseState + [
            'phase' => 'reset_remote_branch',
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
            'progressMessage' => sprintf('Queued reset of remote branch %s.', $branch),
        ];
    }

    public function continue(OperationRecord $record, array $state): array
    {
        $result = $this->context->syncService()->resetRemote($record->managedSetKey);

        return [
            'done' => true,
            'finalResult' => [
                'summaryType' => 'success',
                'summaryMessage' => sprintf(
                    'Reset remote branch %s to commit %s. The local tracking ref %s was updated.',
                    $result->branch,
                    $result->remoteCommitHash,
                    'refs/remotes/origin/' . $result->branch
                ),
                'operationType' => 'reset_remote_branch',
                'branch' => $result->branch,
                'remoteCommitHash' => $result->remoteCommitHash,
                'progressMode' => 'indeterminate',
                'progressCurrent' => 0,
                'progressTotal' => 0,
            ],
        ];
    }
}
