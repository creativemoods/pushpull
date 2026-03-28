<?php

declare(strict_types=1);

namespace PushPull\Domain\Apply;

use PushPull\Settings\PushPullSettings;

interface ManagedSetApplyServiceInterface
{
    public function apply(PushPullSettings $settings): ApplyManagedSetResult;

    /**
     * @return array{commitHash: string, orderedLogicalKeys: string[]}
     */
    public function prepareApply(PushPullSettings $settings): array;

    /**
     * @return array{created: bool, postId?: int|null}
     */
    public function applyLogicalKey(PushPullSettings $settings, string $logicalKey, int $menuOrder): array;

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    public function deleteMissingLogicalKeys(array $desiredLogicalKeys): array;
}
