<?php

/**
 * GitProviderFactory.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

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
/*            case 'bitbucket':
                return new BitbucketProvider();*/
            default:
                throw new \Exception("Unsupported Git provider: $provider");
        }
    }
}
