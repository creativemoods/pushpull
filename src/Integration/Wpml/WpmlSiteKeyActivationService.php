<?php

declare(strict_types=1);

namespace PushPull\Integration\Wpml;

use PushPull\Integration\Contracts\SiteKeyActivationResult;
use PushPull\Integration\Contracts\SiteKeyActivationServiceInterface;
use RuntimeException;

final class WpmlSiteKeyActivationService implements SiteKeyActivationServiceInterface
{
    private const INTEGRATION_KEY = 'wpml';

    public function isAvailable(): bool
    {
        return class_exists('WP_Installer') && is_callable(['WP_Installer', 'instance']);
    }

    public function activateSiteKey(string $siteKey): SiteKeyActivationResult
    {
        $normalizedSiteKey = preg_replace('/[^A-Za-z0-9]/', '', $siteKey) ?? '';

        if ($normalizedSiteKey === '') {
            throw new RuntimeException('WPML site key is empty after sanitization.');
        }

        if (! $this->isAvailable()) {
            throw new RuntimeException('WPML installer is not available on this site.');
        }

        if (! function_exists('wp_create_nonce')) {
            throw new RuntimeException('wp_create_nonce() is not available.');
        }

        $installer = call_user_func(['WP_Installer', 'instance']);

        if (! is_object($installer) || ! method_exists($installer, 'save_site_key')) {
            throw new RuntimeException('WPML installer does not expose save_site_key().');
        }

        /** @var mixed $response */
        $response = $installer->save_site_key([
            'repository_id' => self::INTEGRATION_KEY,
            'site_key' => $normalizedSiteKey,
            'nonce' => wp_create_nonce('save_site_key_' . self::INTEGRATION_KEY),
            'return' => true,
        ]);

        if (! is_array($response)) {
            throw new RuntimeException('WPML installer returned an unexpected response while saving the site key.');
        }

        $error = wp_strip_all_tags((string) ($response['error'] ?? ''), true);

        if ($error !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            throw new RuntimeException($error);
        }

        if (! method_exists($installer, 'get_repository_site_key')) {
            throw new RuntimeException('WPML installer does not expose get_repository_site_key().');
        }

        /** @var mixed $storedSiteKey */
        $storedSiteKey = $installer->get_repository_site_key(self::INTEGRATION_KEY);

        if (! is_string($storedSiteKey) || $storedSiteKey === '') {
            throw new RuntimeException('WPML installer reported success but did not persist the site key.');
        }

        return new SiteKeyActivationResult(
            self::INTEGRATION_KEY,
            $normalizedSiteKey,
            $storedSiteKey,
            $response
        );
    }
}
