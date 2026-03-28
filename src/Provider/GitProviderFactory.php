<?php

declare(strict_types=1);

namespace PushPull\Provider;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Provider\Exception\UnsupportedProviderException;
use PushPull\Provider\GitLab\GitLabProvider;
use PushPull\Provider\GitHub\GitHubProvider;
use PushPull\Provider\Http\WordPressHttpTransport;

final class GitProviderFactory implements GitProviderFactoryInterface
{
    public function make(string $providerKey): GitProviderInterface
    {
        return match ($providerKey) {
            'github' => new GitHubProvider(new WordPressHttpTransport()),
            'gitlab' => new GitLabProvider(new WordPressHttpTransport()),
            'bitbucket' => throw new UnsupportedProviderException(
                sprintf('Provider "%s" is selectable in settings but not implemented yet.', $providerKey)
            ),
            default => throw new UnsupportedProviderException(
                sprintf('Provider "%s" is not supported.', $providerKey)
            ),
        };
    }
}
