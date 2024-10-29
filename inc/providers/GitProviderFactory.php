<?php

/**
 * GitProviderFactory.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\providers;

/**
 * Class GitProviderFactory
 */
class GitProviderFactory {
    public static function createProvider(string $provider, $app): GitProviderInterface {
        switch (strtolower($provider)) {
            case 'github':
                return new GitHubProvider($app);
            case 'gitlab':
                return new GitLabProvider($app);
            case 'bitbucket':
                return new BitbucketProvider($app);
            default:
				/* translators: 1: provider slug */
                throw new \Exception(esc_html(sprintf(__( 'Unsupported Git provider: %1$s.', 'pushpull' ), $provider)));
        }
    }
}
