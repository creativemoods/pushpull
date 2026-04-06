<?php

declare(strict_types=1);

namespace PushPull\Support\FetchAvailability;

use PushPull\Settings\SettingsRepository;

final class FetchAvailabilityScheduler
{
    public const CRON_HOOK = 'pushpull_check_fetch_availability';
    private const SCHEDULED_INTERVAL_OPTION_KEY = 'pushpull_fetch_availability_scheduled_interval_minutes';

    public function __construct(private readonly SettingsRepository $settingsRepository)
    {
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function registerSchedule(array $schedules): array
    {
        $intervalMinutes = $this->settingsRepository->get()->fetchAvailabilityCheckIntervalMinutes;

        $schedules[$this->recurrenceName($intervalMinutes)] = [
            'interval' => $intervalMinutes * 60,
            /* translators: %d: recurring fetch-availability check interval in minutes. */
            'display' => sprintf(__('Every %d minutes', 'pushpull'), $intervalMinutes),
        ];

        return $schedules;
    }

    public function ensureScheduled(): void
    {
        $intervalMinutes = $this->settingsRepository->get()->fetchAvailabilityCheckIntervalMinutes;
        $scheduledIntervalMinutes = (int) get_option(self::SCHEDULED_INTERVAL_OPTION_KEY, 0);
        $nextRun = wp_next_scheduled(self::CRON_HOOK);

        if ($nextRun !== false && $scheduledIntervalMinutes === $intervalMinutes) {
            return;
        }

        if ($nextRun !== false) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        wp_schedule_event(time() + 60, $this->recurrenceName($intervalMinutes), self::CRON_HOOK);
        update_option(self::SCHEDULED_INTERVAL_OPTION_KEY, $intervalMinutes, false);
    }

    private function recurrenceName(int $intervalMinutes): string
    {
        return 'pushpull_fetch_availability_every_' . $intervalMinutes . '_minutes';
    }
}
