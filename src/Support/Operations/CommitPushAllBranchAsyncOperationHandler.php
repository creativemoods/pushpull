<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationRecord;

final class CommitPushAllBranchAsyncOperationHandler implements BranchAsyncOperationHandlerInterface
{
    public function __construct(private readonly BranchAsyncOperationContextInterface $context)
    {
    }

    public function supports(string $operationType): bool
    {
        return $operationType === 'commit_push_all';
    }

    public function initialize(OperationRecord $record, array $baseState, string $branch): array
    {
        $plan = $this->context->buildCommitPushAllPlan();
        $managedSetKeys = $plan['managedSetKeys'];
        $skippedManagedSets = $plan['skippedManagedSets'];

        if ($managedSetKeys === [] && $skippedManagedSets === []) {
            $message = __('No enabled available domains need to be committed or pushed.', 'pushpull');

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

        return $baseState + [
            'phase' => 'commit_branch',
            'managedSetKeys' => $managedSetKeys,
            'skippedManagedSets' => $skippedManagedSets,
            'commitMessage' => trim((string) ($record->payload['commitMessage'] ?? $this->context->settings()->defaultCommitMessage)),
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
            'progressMessage' => sprintf(
                'Preparing one branch commit for %d enabled available domain(s) on branch %s.',
                count($managedSetKeys),
                $branch
            ),
        ];
    }

    public function continue(OperationRecord $record, array $state): array
    {
        if (($state['phase'] ?? '') === 'commit_branch') {
            $settings = $this->context->settings();
            $commitMessage = trim((string) ($state['commitMessage'] ?? $settings->defaultCommitMessage));
            $result = $this->context->commitManagedSets((array) ($state['managedSetKeys'] ?? []), new \PushPull\Domain\Sync\CommitManagedSetRequest(
                $settings->branch,
                $commitMessage !== '' ? $commitMessage : $settings->defaultCommitMessage,
                $settings->authorName !== '' ? $settings->authorName : wp_get_current_user()->display_name,
                $settings->authorEmail !== '' ? $settings->authorEmail : (wp_get_current_user()->user_email ?? '')
            ));
            $pushState = $this->context->pushService()->initializePushState($this->context->settings());
            $pushState += [
                'asyncType' => (string) ($state['asyncType'] ?? ''),
                'operationId' => (int) ($state['operationId'] ?? 0),
                'operationType' => (string) ($state['operationType'] ?? ''),
                'managedSetKey' => (string) ($state['managedSetKey'] ?? ''),
                'branch' => (string) ($state['branch'] ?? ''),
                'lockToken' => (string) ($state['lockToken'] ?? ''),
            ];
            $pushState['createdCommitCount'] = $result->createdNewCommit ? 1 : 0;
            $pushState['committedDomainCount'] = $result->createdNewCommit ? count((array) ($state['managedSetKeys'] ?? [])) : 0;
            $pushState['committedFileCount'] = $result->changedPathCount;
            $pushState['skippedManagedSets'] = $state['skippedManagedSets'] ?? [];
            $pushState['commitMessage'] = $commitMessage;

            if (($pushState['phase'] ?? '') === 'complete') {
                $summaryMessage = __('Nothing to commit or push. Live content and the remote branch are already up to date.', 'pushpull');

                if (($state['skippedManagedSets'] ?? []) !== []) {
                    $summaryMessage .= ' ' . $this->context->skippedManagedSetsSummary((array) $state['skippedManagedSets']);
                }

                return [
                    'done' => true,
                    'finalResult' => [
                        'summaryType' => 'success',
                        'summaryMessage' => $summaryMessage,
                        'progressMode' => 'indeterminate',
                        'progressCurrent' => 0,
                        'progressTotal' => 0,
                    ],
                ];
            }

            $pushState['progressMessage'] = sprintf(
                'Prepared push plan after creating one branch commit for %d domain(s) across %d changed file(s).',
                $pushState['committedDomainCount'],
                $pushState['committedFileCount']
            );

            return [
                'done' => false,
                'state' => $pushState,
            ];
        }

        $state = $this->context->pushService()->continuePushState($this->context->settings(), $state, $this->context->chunkNodeLimit());

        if (($state['phase'] ?? '') !== 'complete') {
            return [
                'done' => false,
                'state' => $state,
            ];
        }

        $summaryMessage = (int) ($state['createdCommitCount'] ?? 0) > 0
            ? sprintf(
                'Committed %1$d changed domain(s) in one branch commit touching %2$d file(s) and pushed branch %3$s to remote commit %4$s.',
                (int) ($state['committedDomainCount'] ?? 0),
                (int) ($state['committedFileCount'] ?? 0),
                (string) ($state['branch'] ?? ''),
                (string) ($state['remoteCommitHash'] ?? '')
            )
            : sprintf(
                'No new bulk commit was created. Pushed the existing local branch %1$s to remote commit %2$s.',
                (string) ($state['branch'] ?? ''),
                (string) ($state['remoteCommitHash'] ?? '')
            );

        if (($state['skippedManagedSets'] ?? []) !== []) {
            $summaryMessage .= ' ' . $this->context->skippedManagedSetsSummary((array) $state['skippedManagedSets']);
        }

        return [
            'done' => true,
            'finalResult' => [
                'summaryType' => 'success',
                'summaryMessage' => $summaryMessage,
                'operationType' => 'commit_push_all',
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
