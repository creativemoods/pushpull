<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Integration\Wpml\WpmlSiteKeyActivationService;
use RuntimeException;

final class WpmlSiteKeyActivationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_wpml_site_keys'] = [];
        $GLOBALS['pushpull_test_wpml_save_site_key_calls'] = [];
        $GLOBALS['pushpull_test_wpml_save_site_key_error'] = '';
        $GLOBALS['pushpull_test_actions'] = [];
    }

    public function testActivateSiteKeyValidatesAndPersistsSiteKeyThroughInstaller(): void
    {
        $service = new WpmlSiteKeyActivationService();

        $result = $service->activateSiteKey('AbC-123');

        self::assertSame('wpml', $result->integrationKey);
        self::assertSame('AbC123', $result->siteKey);
        self::assertSame('AbC123', $result->storedSiteKey);
        self::assertSame('nonce:save_site_key_wpml', $GLOBALS['pushpull_test_wpml_save_site_key_calls'][0]['nonce']);
        self::assertSame('check_posthog_should_record', $GLOBALS['pushpull_test_actions'][0]['hook']);
    }

    public function testActivateSiteKeyThrowsWhenInstallerReturnsError(): void
    {
        $service = new WpmlSiteKeyActivationService();
        $GLOBALS['pushpull_test_wpml_save_site_key_error'] = 'Invalid site key for the current site.';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid site key for the current site.');

        $service->activateSiteKey('AbC-123');
    }
}
