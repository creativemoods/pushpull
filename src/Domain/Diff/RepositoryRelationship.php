<?php

declare(strict_types=1);

namespace PushPull\Domain\Diff;

final class RepositoryRelationship
{
    public const NO_COMMITS = 'no_commits';
    public const LOCAL_ONLY = 'local_only';
    public const REMOTE_ONLY = 'remote_only';
    public const IN_SYNC = 'in_sync';
    public const AHEAD = 'ahead';
    public const BEHIND = 'behind';
    public const DIVERGED = 'diverged';
    public const UNRELATED = 'unrelated';

    public function __construct(public readonly string $status)
    {
    }

    public function label(): string
    {
        return match ($this->status) {
            self::NO_COMMITS => 'No local or remote commits',
            self::LOCAL_ONLY => 'Local branch has no remote tracking commit',
            self::REMOTE_ONLY => 'Remote branch has not been imported locally',
            self::IN_SYNC => 'Local and remote are in sync',
            self::AHEAD => 'Local branch is ahead of remote',
            self::BEHIND => 'Local branch is behind remote',
            self::DIVERGED => 'Local and remote have diverged',
            default => 'Local and remote have no shared ancestry',
        };
    }
}
