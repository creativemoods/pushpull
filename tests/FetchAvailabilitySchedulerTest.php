<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\FetchAvailability\FetchAvailabilityScheduler;

final class FetchAvailabilitySchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_cron_events'] = [];
    }

    public function testEnsureScheduledRegistersConfiguredRecurringCheck(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'fetch_availability_check_interval_minutes' => 5,
        ]);

        $scheduler = new FetchAvailabilityScheduler(new SettingsRepository());
        $scheduler->ensureScheduled();

        self::assertSame(
            'pushpull_fetch_availability_every_5_minutes',
            $GLOBALS['pushpull_test_cron_events'][FetchAvailabilityScheduler::CRON_HOOK]['recurrence'] ?? null
        );
    }

    public function testEnsureScheduledReschedulesWhenIntervalChanges(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'fetch_availability_check_interval_minutes' => 5,
        ]);

        $scheduler = new FetchAvailabilityScheduler(new SettingsRepository());
        $scheduler->ensureScheduled();

        update_option(SettingsRepository::OPTION_KEY, [
            'fetch_availability_check_interval_minutes' => 15,
        ]);

        $scheduler->ensureScheduled();

        self::assertSame(
            'pushpull_fetch_availability_every_15_minutes',
            $GLOBALS['pushpull_test_cron_events'][FetchAvailabilityScheduler::CRON_HOOK]['recurrence'] ?? null
        );
    }
}
