<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationRecord;

final class PushBranchAsyncOperationHandler implements BranchAsyncOperationHandlerInterface
{
    public function __construct(private readonly BranchAsyncOperationContextInterface $context)
    {
    }

    public function supports(string $operationType): bool
    {
        return $operationType === 'push';
    }

    public function initialize(OperationRecord $record, array $baseState, string $branch): array
    {
        $pushState = $this->context->pushService()->initializePushState($this->context->settings());

        if (($pushState['phase'] ?? '') === 'complete' && ($pushState['status'] ?? '') === 'already_up_to_date') {
            return $baseState + $pushState + [
                'phase' => 'complete',
                'summaryType' => 'success',
                'summaryMessage' => (string) $pushState['progressMessage'],
                'redirectUrl' => $this->context->noticeUrl('success', (string) $pushState['progressMessage']),
                'progressMode' => 'determinate',
                'progressCurrent' => 1,
                'progressTotal' => 1,
            ];
        }

        return $baseState + $pushState;
    }

    public function continue(OperationRecord $record, array $state): array
    {
        $state = $this->context->pushService()->continuePushState($this->context->settings(), $state, $this->context->chunkNodeLimit());

        if (($state['phase'] ?? '') !== 'complete') {
            return [
                'done' => false,
                'state' => $state,
            ];
        }

        return [
            'done' => true,
            'finalResult' => [
                'summaryType' => 'success',
                'summaryMessage' => (string) $state['progressMessage'],
                'operationType' => 'push',
                'managedSetKey' => $record->managedSetKey,
                'branch' => $state['branch'],
                'status' => (string) ($state['status'] ?? 'pushed'),
                'remoteCommitHash' => (string) $state['remoteCommitHash'],
                'pushedCommitHashes' => array_values(array_unique($state['pushedCommitHashes'])),
                'pushedTreeHashes' => array_values(array_unique($state['pushedTreeHashes'])),
                'pushedBlobHashes' => array_values(array_unique($state['pushedBlobHashes'])),
                'progressMode' => 'determinate',
                'progressCurrent' => (int) $state['progressCurrent'],
                'progressTotal' => (int) $state['progressTotal'],
            ],
        ];
    }
}
