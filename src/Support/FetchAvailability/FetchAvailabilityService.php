<?php

declare(strict_types=1);

namespace PushPull\Support\FetchAvailability;

use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Settings\PushPullSettings;
use PushPull\Settings\SettingsRepository;
use Throwable;

final class FetchAvailabilityService
{
    public const OPTION_KEY = 'pushpull_fetch_availability';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_UP_TO_DATE = 'up_to_date';
    public const STATUS_UPDATES_AVAILABLE = 'updates_available';
    public const STATUS_ERROR = 'error';

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly GitProviderFactoryInterface $providerFactory,
        private readonly LocalRepositoryInterface $localRepository
    ) {
    }

    public function checkAndStore(): void
    {
        $settings = $this->settingsRepository->get();

        if (! $this->canCheck($settings)) {
            delete_option(self::OPTION_KEY);
            return;
        }

        try {
            $remoteConfig = GitRemoteConfig::fromSettings($settings);
            $provider = $this->providerFactory->make($settings->providerKey);
            $remoteRef = $provider->getRef($remoteConfig, 'refs/heads/' . $settings->branch);
            $trackingRef = $this->localRepository->getRef('refs/remotes/origin/' . $settings->branch);

            if ($remoteRef === null) {
                $this->storeState($settings, self::STATUS_UNKNOWN, false, null, $trackingRef?->commitHash, null);
                return;
            }

            $updatesAvailable = $remoteRef->commitHash !== ($trackingRef?->commitHash ?? null);
            $status = $updatesAvailable ? self::STATUS_UPDATES_AVAILABLE : self::STATUS_UP_TO_DATE;

            $this->storeState(
                $settings,
                $status,
                $updatesAvailable,
                $remoteRef->commitHash,
                $trackingRef?->commitHash,
                null
            );
        } catch (Throwable $throwable) {
            $this->storeState($settings, self::STATUS_ERROR, false, null, null, $throwable->getMessage());
        }
    }

    /**
     * @return array{
     *   status: string,
     *   updatesAvailable: bool,
     *   checkedAt: ?int,
     *   remoteCommitHash: ?string,
     *   trackingCommitHash: ?string
     * }
     */
    public function getCachedState(PushPullSettings $settings): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (! is_array($stored)) {
            return $this->unknownState();
        }

        if (($stored['fingerprint'] ?? '') !== $this->fingerprint($settings)) {
            return $this->unknownState();
        }

        $status = (string) ($stored['status'] ?? self::STATUS_UNKNOWN);
        $allowedStatuses = [
            self::STATUS_UNKNOWN,
            self::STATUS_UP_TO_DATE,
            self::STATUS_UPDATES_AVAILABLE,
            self::STATUS_ERROR,
        ];

        if (! in_array($status, $allowedStatuses, true)) {
            $status = self::STATUS_UNKNOWN;
        }

        return [
            'status' => $status,
            'updatesAvailable' => ! empty($stored['updates_available']),
            'checkedAt' => isset($stored['checked_at']) ? (int) $stored['checked_at'] : null,
            'remoteCommitHash' => isset($stored['remote_commit_hash']) ? (string) $stored['remote_commit_hash'] : null,
            'trackingCommitHash' => isset($stored['tracking_commit_hash']) ? (string) $stored['tracking_commit_hash'] : null,
        ];
    }

    private function canCheck(PushPullSettings $settings): bool
    {
        return $settings->providerKey !== ''
            && $settings->ownerOrWorkspace !== ''
            && $settings->repository !== ''
            && $settings->branch !== '';
    }

    private function fingerprint(PushPullSettings $settings): string
    {
        return md5(implode('|', [
            $settings->providerKey,
            $settings->ownerOrWorkspace,
            $settings->repository,
            $settings->branch,
            $settings->baseUrl,
        ]));
    }

    private function storeState(
        PushPullSettings $settings,
        string $status,
        bool $updatesAvailable,
        ?string $remoteCommitHash,
        ?string $trackingCommitHash,
        ?string $errorMessage
    ): void {
        update_option(self::OPTION_KEY, [
            'fingerprint' => $this->fingerprint($settings),
            'status' => $status,
            'updates_available' => $updatesAvailable,
            'checked_at' => time(),
            'remote_commit_hash' => $remoteCommitHash,
            'tracking_commit_hash' => $trackingCommitHash,
            'error_message' => $errorMessage !== null ? sanitize_text_field($errorMessage) : '',
        ], false);
    }

    /**
     * @return array{
     *   status: string,
     *   updatesAvailable: bool,
     *   checkedAt: ?int,
     *   remoteCommitHash: ?string,
     *   trackingCommitHash: ?string
     * }
     */
    private function unknownState(): array
    {
        return [
            'status' => self::STATUS_UNKNOWN,
            'updatesAvailable' => false,
            'checkedAt' => null,
            'remoteCommitHash' => null,
            'trackingCommitHash' => null,
        ];
    }
}
