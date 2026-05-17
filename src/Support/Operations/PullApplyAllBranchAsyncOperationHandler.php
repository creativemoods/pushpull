<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationRecord;

final class PullApplyAllBranchAsyncOperationHandler implements BranchAsyncOperationHandlerInterface
{
    public function __construct(private readonly BranchAsyncOperationContextInterface $context)
    {
    }

    public function supports(string $operationType): bool
    {
        return $operationType === 'pull_apply_all';
    }

    public function initialize(OperationRecord $record, array $baseState, string $branch): array
    {
        $managedSetKeys = $this->context->enabledAvailableManagedSetKeys();

        if ($managedSetKeys === []) {
            $message = __('No enabled available domains need to be pulled or applied.', 'pushpull');

            return $baseState + [
                'phase' => 'complete',
                'summaryType' => 'success',
                'summaryMessage' => $message,
                'redirectUrl' => $this->context->noticeUrl('success', $message, is_string($record->payload['sourcePage'] ?? null) ? (string) $record->payload['sourcePage'] : null),
                'progressMode' => 'indeterminate',
                'progressCurrent' => 0,
                'progressTotal' => 0,
            ];
        }

        [$remoteConfig, $remoteRef] = $this->context->loadRemoteRef($branch);
        $fetchState = $this->context->initialFetchState($remoteConfig, $remoteRef->commitHash);

        return $baseState + [
            'phase' => 'pull_all_fetch',
            'applyManagedSetKeys' => $managedSetKeys,
            'applyManagedSetIndex' => 0,
            'currentApplyIndex' => 0,
            'currentDesiredLogicalKeys' => [],
            'currentDeletedLogicalKeys' => [],
            'currentApplyPlan' => null,
            'applyPlans' => [],
            'createdCount' => 0,
            'updatedCount' => 0,
            'deletedCount' => 0,
            'appliedDomainCount' => 0,
            'remoteRefName' => 'refs/remotes/origin/' . $branch,
            'remoteCommitHash' => $remoteRef->commitHash,
            'providerBranchRefName' => 'refs/heads/' . $remoteConfig->branch,
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
            'progressMessage' => sprintf('Starting pull of remote commit %s for branch %s.', $remoteRef->commitHash, $branch),
        ] + $fetchState;
    }

    public function continue(OperationRecord $record, array $state): array
    {
        if (($state['phase'] ?? '') === 'pull_all_fetch') {
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
            $state['phase'] = 'pull_all_merge';
            $state['progressMessage'] = sprintf('Fetched remote commit %s into %s. Preparing merge.', $state['remoteCommitHash'], $trackingRefName);

            return [
                'done' => false,
                'state' => $state,
            ];
        }

        if (($state['phase'] ?? '') === 'pull_all_merge') {
            $mergeResult = $this->context->syncService()->merge($record->managedSetKey);
            $state['pullSummaryMessage'] = $this->context->finalPullResult($record, $state, $mergeResult)['summaryMessage'];

            if ($mergeResult->hasConflicts()) {
                return [
                    'done' => true,
                    'finalResult' => [
                        'summaryType' => 'error',
                        'summaryMessage' => sprintf(
                            '%s Apply was not started because merge conflict resolution is required.',
                            $state['pullSummaryMessage']
                        ),
                        'progressMode' => 'indeterminate',
                        'progressCurrent' => 0,
                        'progressTotal' => 0,
                    ],
                ];
            }

            $state = $this->prepareBulkApplyPlans($state);

            return [
                'done' => false,
                'state' => $state,
            ];
        }

        return $this->continueBulkApply($state);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function prepareBulkApplyPlans(array $state): array
    {
        $applyPlans = [];
        $totalSteps = 0;

        foreach ($state['applyManagedSetKeys'] as $managedSetKey) {
            if (! is_string($managedSetKey) || $managedSetKey === '') {
                continue;
            }

            $prepared = $this->context->requireApplyService($managedSetKey)->prepareApply($this->context->settings());
            $applyPlans[] = [
                'managedSetKey' => $managedSetKey,
                'sourceCommitHash' => $prepared['commitHash'],
                'orderedLogicalKeys' => $prepared['orderedLogicalKeys'],
            ];
            $totalSteps += count($prepared['orderedLogicalKeys']) + 1;
        }

        $state['phase'] = 'pull_all_apply';
        $state['applyPlans'] = $applyPlans;
        $state['progressMode'] = 'determinate';
        $state['progressCurrent'] = 0;
        $state['progressTotal'] = max(1, $totalSteps);
        $state['progressMessage'] = sprintf(
            'Prepared apply plan for %d enabled available domain(s).',
            count($applyPlans)
        );

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    private function continueBulkApply(array $state): array
    {
        $settings = $this->context->settings();
        $budget = $this->context->chunkNodeLimit();

        while ($budget > 0) {
            if ((int) $state['applyManagedSetIndex'] >= count($state['applyPlans'])) {
                return [
                    'done' => true,
                    'finalResult' => [
                        'summaryType' => 'success',
                        'summaryMessage' => sprintf(
                            '%s Applied %d managed domain(s) to WordPress. Created %d item(s), updated %d item(s), and deleted %d missing item(s).',
                            (string) ($state['pullSummaryMessage'] ?? ''),
                            (int) $state['appliedDomainCount'],
                            (int) $state['createdCount'],
                            (int) $state['updatedCount'],
                            (int) $state['deletedCount']
                        ),
                        'progressMode' => 'determinate',
                        'progressCurrent' => (int) $state['progressTotal'],
                        'progressTotal' => (int) $state['progressTotal'],
                    ],
                ];
            }

            /** @var array{managedSetKey: string, sourceCommitHash: string, orderedLogicalKeys: array<int, string>} $currentPlan */
            $currentPlan = $state['applyPlans'][(int) $state['applyManagedSetIndex']];
            $applyService = $this->context->requireApplyService($currentPlan['managedSetKey']);

            if ((int) $state['currentApplyIndex'] < count($currentPlan['orderedLogicalKeys'])) {
                $logicalKey = (string) $currentPlan['orderedLogicalKeys'][(int) $state['currentApplyIndex']];
                $menuOrder = (int) $state['currentApplyIndex'];
                $result = $applyService->applyLogicalKey($settings, $logicalKey, $menuOrder);

                if ($result['created']) {
                    $state['createdCount']++;
                } else {
                    $state['updatedCount']++;
                }

                $state['currentDesiredLogicalKeys'][$logicalKey] = true;
                $state['currentApplyIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = sprintf(
                    'Applied %s from %s to WordPress.',
                    $logicalKey,
                    $currentPlan['managedSetKey']
                );
                $budget--;
                continue;
            }

            $applyService->applyManifestState($settings);
            $deletedLogicalKeys = $applyService->deleteMissingLogicalKeys($state['currentDesiredLogicalKeys']);
            $state['deletedCount'] += count($deletedLogicalKeys);
            $state['appliedDomainCount']++;
            $state['applyManagedSetIndex']++;
            $state['currentApplyIndex'] = 0;
            $state['currentDesiredLogicalKeys'] = [];
            $state['progressCurrent']++;
            $state['progressMessage'] = sprintf(
                'Completed apply for %s.',
                $currentPlan['managedSetKey']
            );
            $budget--;
        }

        return [
            'done' => false,
            'state' => $state,
        ];
    }
}
