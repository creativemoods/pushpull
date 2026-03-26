=== PushPull ===
Contributors: jeromesteunenberg
Tags: git, github, generateblocks, content sync, devops
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.0.5
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Git-based content sync for WordPress.

== Description ==

PushPull stores selected WordPress content in a Git repository using a canonical JSON representation instead of raw database dumps.

=== Beta notice ===

This is a beta plugin. It is still under active development, has limited functionality, and currently supports only a narrow subset of the intended PushPull feature set.

The current release focuses on one managed domain:

1. GenerateBlocks Global Styles (`gblocks_styles`)

PushPull keeps a local Git-like repository inside WordPress database tables and supports the following workflow directly from WordPress admin:

1. Test the remote GitHub connection
2. Commit live managed content into the local repository
3. Fetch remote commits into a local tracking ref
4. Diff live, local, and remote states
5. Merge remote changes into the local branch
6. Resolve conflicts when needed
7. Apply repository content back into WordPress
8. Push local commits to GitHub

The plugin also includes:

1. An operations history screen
2. Local repository reset tooling
3. Remote branch reset tooling that creates one commit removing all tracked files from the branch

== Current scope ==

This is an early, focused release. At the moment, PushPull is intentionally limited to:

1. GitHub as the implemented remote provider
2. GenerateBlocks Global Styles as the implemented managed content domain
3. Canonical JSON files stored in a repository layout under `generateblocks/global-styles/`

It does not yet manage general posts, pages, menus, forms, or arbitrary plugin data.

== Installation ==

= Uploading in WordPress Dashboard =

1. Download the plugin ZIP.
2. In WordPress, go to Plugins > Add New Plugin.
3. Click Upload Plugin.
4. Select the ZIP file.
5. Install and activate the plugin.

= Installing from source =

1. Clone this repository into `wp-content/plugins/pushpull`.
2. Run `composer install`.
3. Activate PushPull in WordPress.

== Configuration ==

PushPull currently supports GitHub repositories through the GitHub Git Database REST API.

Create a fine-grained personal access token or installation token with access to the target repository. The token should allow:

1. Repository metadata read access
2. Repository contents read and write access

In PushPull > Settings:

1. Select `GitHub` as the provider
2. Enter the repository owner and repository name
3. Enter the target branch
4. Enter the API token
5. Enable `GenerateBlocks Global Styles` in the managed content settings
6. Click `Test connection`
7. Save the settings

= Empty repositories =

If the configured GitHub repository exists but has no commits yet, `Test connection` will report that the repository is reachable but empty.

In that case, click `Initialize remote repository`. PushPull will:

1. create the first commit on the configured branch
2. fetch that initial commit into the local remote-tracking ref
3. make the repository ready for normal commit, fetch, merge, apply, and push workflows

You do not need to create the first commit manually on GitHub before using PushPull.

== External services ==

PushPull connects to the GitHub API for the repository you configure in the plugin settings.

The plugin uses GitHub's REST API to:

1. Read repository metadata and the default branch
2. Read and update branch refs
3. Read and create Git blobs, trees, and commits
4. Test repository access before sync operations

PushPull sends the following information to GitHub over HTTPS:

1. The repository owner, repository name, branch, and API base URL
2. Your configured API token in the `Authorization` header
3. Canonical JSON representations of the managed content you choose to commit and push
4. Commit metadata such as commit messages and, if configured, author name and email

In the current release, the only managed content sent to GitHub is GenerateBlocks Global Styles when you commit and push them.

PushPull does not send your whole WordPress database to GitHub. It only sends the managed content represented by the enabled adapters.

GitHub terms of service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
GitHub privacy statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Changelog ==

= 0.0.1 =

Initial public release focused on GitHub-backed synchronization of GenerateBlocks Global Styles.
