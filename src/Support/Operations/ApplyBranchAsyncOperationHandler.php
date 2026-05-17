<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationRecord;

final class ApplyBranchAsyncOperationHandler implements BranchAsyncOperationHandlerInterface
{
    public function __construct(private readonly BranchAsyncOperationContextInterface $context)
    {
    }

    public function supports(string $operationType): bool
    {
        return $operationType === 'apply';
    }

    public function initialize(OperationRecord $record, array $baseState, string $branch): array
    {
        $applyService = $this->context->requireApplyService($record->managedSetKey);
        $prepared = $applyService->prepareApply($this->context->settings());
        $orderedLogicalKeys = $prepared['orderedLogicalKeys'];

        return $baseState + [
            'phase' => 'apply_items',
            'sourceCommitHash' => $prepared['commitHash'],
            'orderedLogicalKeys' => $orderedLogicalKeys,
            'applyIndex' => 0,
            'createdCount' => 0,
            'updatedCount' => 0,
            'appliedWpObjectIds' => [],
            'desiredLogicalKeys' => [],
            'deletedLogicalKeys' => [],
            'progressMode' => 'determinate',
            'progressCurrent' => 0,
            'progressTotal' => max(1, count($orderedLogicalKeys) + 1),
            'progressMessage' => sprintf(
                'Prepared apply plan for %s: %d item(s) from local branch %s.',
                $record->managedSetKey,
                count($orderedLogicalKeys),
                $branch
            ),
        ];
    }

    public function continue(OperationRecord $record, array $state): array
    {
        $applyService = $this->context->requireApplyService($record->managedSetKey);
        $settings = $this->context->settings();
        $budget = $this->context->chunkNodeLimit();

        while ($budget > 0) {
            if ((int) $state['applyIndex'] < count($state['orderedLogicalKeys'])) {
                $logicalKey = (string) $state['orderedLogicalKeys'][(int) $state['applyIndex']];
                $menuOrder = (int) $state['applyIndex'];
                $result = $applyService->applyLogicalKey($settings, $logicalKey, $menuOrder);

                if ($result['created']) {
                    $state['createdCount']++;
                } else {
                    $state['updatedCount']++;
                }

                $state['appliedWpObjectIds'][] = $result['postId'];
                $state['desiredLogicalKeys'][$logicalKey] = true;
                $state['applyIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = sprintf(
                    'Applied %s to WordPress. Processed %d of %d item(s).',
                    $logicalKey,
                    $state['applyIndex'],
                    count($state['orderedLogicalKeys'])
                );
                $budget--;
                continue;
            }

            $applyService->applyManifestState($settings);
            $state['deletedLogicalKeys'] = $applyService->deleteMissingLogicalKeys($state['desiredLogicalKeys']);
            $state['progressCurrent'] = (int) $state['progressTotal'];
            $state['progressMessage'] = sprintf(
                'Applied %d item(s) from local branch %s to WordPress.',
                count($state['orderedLogicalKeys']),
                $state['branch']
            );

            return [
                'done' => true,
                'finalResult' => [
                    'summaryType' => 'success',
                    'summaryMessage' => sprintf(
                        'Applied repository commit %s to WordPress. Created %d item(s), updated %d item(s), and deleted %d missing item(s).',
                        $state['sourceCommitHash'],
                        $state['createdCount'],
                        $state['updatedCount'],
                        count($state['deletedLogicalKeys'])
                    ),
                    'progressMode' => 'determinate',
                    'progressCurrent' => (int) $state['progressTotal'],
                    'progressTotal' => (int) $state['progressTotal'],
                    'createdCount' => $state['createdCount'],
                    'updatedCount' => $state['updatedCount'],
                    'appliedWpObjectIds' => $state['appliedWpObjectIds'],
                    'deletedLogicalKeys' => $state['deletedLogicalKeys'],
                    'redirectManagedSetKey' => $record->managedSetKey,
                ],
            ];
        }

        return [
            'done' => false,
            'state' => $state,
        ];
    }
}
