<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationRecord;

final class FetchPullBranchAsyncOperationHandler implements BranchAsyncOperationHandlerInterface
{
    public function __construct(private readonly BranchAsyncOperationContextInterface $context)
    {
    }

    public function supports(string $operationType): bool
    {
        return in_array($operationType, ['fetch', 'pull'], true);
    }

    public function initialize(OperationRecord $record, array $baseState, string $branch): array
    {
        [$remoteConfig, $remoteRef] = $this->context->loadRemoteRef($branch);
        $fetchState = $this->context->initialFetchState($remoteConfig, $remoteRef->commitHash);

        return $baseState + [
            'phase' => 'fetch',
            'remoteRefName' => 'refs/remotes/origin/' . $branch,
            'remoteCommitHash' => $remoteRef->commitHash,
            'providerBranchRefName' => 'refs/heads/' . $remoteConfig->branch,
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
            'progressMessage' => sprintf('Queued fetch of remote commit %s for branch %s.', $remoteRef->commitHash, $branch),
        ] + $fetchState;
    }

    public function continue(OperationRecord $record, array $state): array
    {
        $withMerge = $record->operationType === 'pull';

        if (($state['phase'] ?? 'fetch') === 'merge') {
            $mergeResult = $this->context->syncService()->merge($record->managedSetKey);

            return [
                'done' => true,
                'finalResult' => $this->context->finalPullResult($record, $state, $mergeResult),
            ];
        }

        [$provider, $remoteConfig] = $this->context->resolveProvider();
        $state = $this->context->processFetchChunk($state, $provider, $remoteConfig);

        if (! $this->context->isFetchComplete($state)) {
            return [
                'done' => false,
                'state' => $state,
            ];
        }

        $trackingRefName = 'refs/remotes/origin/' . $remoteConfig->branch;
        $this->context->updateTrackingRef($trackingRefName, (string) $state['remoteCommitHash']);
        $state['phase'] = $withMerge ? 'merge' : 'complete';
        $state['progressMessage'] = $withMerge
            ? sprintf('Fetched remote commit %s into %s. Preparing merge.', $state['remoteCommitHash'], $trackingRefName)
            : sprintf('Fetched remote commit %s into %s.', $state['remoteCommitHash'], $trackingRefName);

        if ($withMerge) {
            return [
                'done' => false,
                'state' => $state,
            ];
        }

        return [
            'done' => true,
            'finalResult' => $this->context->finalFetchResult($record, $state),
        ];
    }
}
