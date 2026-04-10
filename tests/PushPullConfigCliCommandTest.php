<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Cli\PushPullConfigCliCommand;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Settings\SettingsRepository;

final class PushPullConfigCliCommandTest extends TestCase
{
    protected function setUp(): void
    {
        \WP_CLI::$lines = [];
        \WP_CLI::$successes = [];
        \WP_CLI::$warnings = [];
        \WP_CLI::$errors = [];
        \WP_CLI::$commands = [];
    }

    public function testSetAndGetConfigurationValue(): void
    {
        $settingsRepository = new SettingsRepository();
        $command = new PushPullConfigCliCommand($settingsRepository, new ManagedSetRegistry([
            new GenerateBlocksGlobalStylesAdapter(),
        ]));

        $command->set(['branch', 'develop'], []);
        $command->get(['branch'], []);

        self::assertSame('Updated branch.', \WP_CLI::$successes[0]);
        self::assertSame('develop', \WP_CLI::$lines[0]);
    }

    public function testEnableAndDisableDomainUpdatesEnabledManagedSets(): void
    {
        $settingsRepository = new SettingsRepository();
        $registry = new ManagedSetRegistry([
            new GenerateBlocksGlobalStylesAdapter(),
        ]);
        $command = new PushPullConfigCliCommand($settingsRepository, $registry);

        $command->enableDomain(['generateblocks_global_styles'], []);
        self::assertTrue($settingsRepository->get()->isManagedSetEnabled('generateblocks_global_styles'));

        $command->disableDomain(['generateblocks_global_styles'], []);
        self::assertFalse($settingsRepository->get()->isManagedSetEnabled('generateblocks_global_styles'));
        self::assertSame('Disabled managed set generateblocks_global_styles.', \WP_CLI::$successes[1]);
    }
}
