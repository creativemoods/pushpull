<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
    }

    public function testBlankTokenPreservesExistingToken(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'api_token' => 'existing-secret',
            'provider_key' => 'github',
        ]);

        $repository = new SettingsRepository();
        $settings = $repository->sanitize([
            'provider_key' => 'gitlab',
            'api_token' => '   ',
        ]);

        self::assertSame('existing-secret', $settings->apiToken);
        self::assertSame('gitlab', $settings->providerKey);
    }

    public function testInvalidProviderFallsBackToGithub(): void
    {
        $repository = new SettingsRepository();
        $settings = $repository->sanitize([
            'provider_key' => 'not-a-provider',
        ]);

        self::assertSame('github', $settings->providerKey);
    }

    public function testBooleanFieldsNormalizeConsistently(): void
    {
        $repository = new SettingsRepository();
        $settings = $repository->sanitize([
            'manage_generateblocks_global_styles' => '1',
            'auto_apply_enabled' => '',
        ]);

        self::assertTrue($settings->manageGenerateBlocksGlobalStyles);
        self::assertFalse($settings->autoApplyEnabled);
        self::assertTrue($settings->diagnosticsEnabled);

        $settingsWithoutDiagnostics = $repository->sanitize([
            'diagnostics_enabled' => '',
        ]);

        self::assertFalse($settingsWithoutDiagnostics->diagnosticsEnabled);
    }

    public function testTextAndEmailFieldsAreSanitized(): void
    {
        $repository = new SettingsRepository();
        $settings = $repository->sanitize([
            'owner_or_workspace' => '  <b>team</b>  ',
            'repository' => " repo\tname ",
            'branch' => " feature/main \n",
            'author_name' => ' <script>Jane</script> Doe ',
            'author_email' => 'Jane Doe <jane@example.com>',
            'base_url' => 'https://gitlab.example.com/group/repo?x=<bad>',
        ]);

        self::assertSame('team', $settings->ownerOrWorkspace);
        self::assertSame('repo name', $settings->repository);
        self::assertSame('feature/main', $settings->branch);
        self::assertSame('Jane Doe', $settings->authorName);
        self::assertSame('JaneDoejane@example.com', $settings->authorEmail);
        self::assertStringStartsWith('https://gitlab.example.com/', $settings->baseUrl);
    }
}
