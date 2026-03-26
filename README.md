# PushPull

Contributors: jeromesteunenberg
Tags: git, github, generateblocks, content sync, devops
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.0.5
License: GPLv2
License URI: [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Git-backed content workflows for selected WordPress content domains.

> This is a beta plugin. It is still under active development, has limited functionality, and currently supports only a narrow subset of the intended PushPull feature set.

## Description

PushPull stores selected WordPress content in a Git repository using a canonical JSON representation instead of raw database dumps.

The current release focuses on one managed domain:

1. GenerateBlocks Global Styles (`gblocks_styles`)

PushPull keeps a local Git-like repository inside WordPress database tables, lets you compare live WordPress content against local and remote snapshots, and supports the full workflow from WordPress:

1. Test the remote GitHub connection
2. Commit live managed content into the local repository
3. Fetch remote commits into a local tracking ref
4. Diff live, local, and remote states
5. Merge remote changes into the local branch
6. Resolve conflicts when needed
7. Apply repository content back into WordPress
8. Push local commits to GitHub

The plugin also includes:

1. A dedicated operations history screen
2. Local repository reset tooling
3. Remote branch reset tooling that creates one commit removing all tracked files from the branch

## Current scope

This is an early, focused release. At the moment, PushPull is intentionally limited to:

1. GitHub as the implemented remote provider
2. GenerateBlocks Global Styles as the implemented managed content domain
3. Canonical JSON files stored in a repository layout under `generateblocks/global-styles/`

It does not yet manage general posts, pages, menus, forms, or arbitrary plugin data.

## How PushPull represents content

PushPull does not use WordPress post IDs as repository identity.

For GenerateBlocks Global Styles it stores:

1. One canonical JSON file per style
2. One separate `manifest.json` file that preserves style order and load priority

That design keeps content stable across environments and makes reorder-only changes isolated and easy to review.

## Installation

### Uploading in WordPress Dashboard

1. Download the packaged plugin ZIP.
2. In WordPress, go to Plugins > Add New Plugin.
3. Click Upload Plugin.
4. Select the ZIP file.
5. Install and activate the plugin.

### Installing from source

1. Clone this repository into `wp-content/plugins/pushpull`.
2. Run `composer install`.
3. Activate PushPull in WordPress.

## Configuration

PushPull currently supports GitHub repositories through the GitHub Git Database REST API.

### GitHub token

Create a fine-grained personal access token or installation token with access to the target repository. The token should allow:

1. Repository metadata read access
2. Repository contents read and write access

### Plugin settings

In PushPull > Settings:

1. Select `GitHub` as the provider
2. Enter the repository owner and repository name
3. Enter the target branch
4. Enter the API token
5. Enable `GenerateBlocks Global Styles` in the managed content settings
6. Click `Test connection`
7. Save the settings

### Empty repositories

If the configured GitHub repository exists but has no commits yet, `Test connection` will report that the repository is reachable but empty.

In that case, click `Initialize remote repository`. PushPull will:

1. create the first commit on the configured branch
2. fetch that initial commit into the local remote-tracking ref
3. make the repository ready for normal commit, fetch, merge, apply, and push workflows

You no longer need to create the first commit manually on GitHub before using PushPull.

## Workflow

The normal workflow is:

1. `Commit` to snapshot the current live GenerateBlocks global styles into the local repository
2. `Fetch` to import the current remote branch into `refs/remotes/origin/<branch>`
3. `Merge` to bring fetched remote history into the local branch
4. `Apply repo to WordPress` when you want the local branch state written back into WordPress
5. `Push` when you want local commits published to GitHub

If both local and remote changed, PushPull can persist conflicts, let you resolve them in the admin UI, and then finalize a merge commit.

## Release checklist

1. Make sure `main` is green in GitLab: PHPUnit, PHPCS, PHPStan, package, and PCP.
2. Choose the next semantic version, for example `0.2.0`.
3. Run `composer bump-version -- 0.2.0`.
4. Review the changes in `pushpull.php` and `README.md`.
5. Commit the version bump.
6. Create and push a Git tag in the form `v0.2.0` on that commit.
7. Let GitLab run the tag pipeline.
8. The package job will build `build/pushpull-0.2.0.zip` from that tagged commit.
9. Download the ZIP artifact from the tag pipeline and upload it in WordPress.
10. Verify after upload that WordPress shows the new plugin version correctly.

## External services

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

GitHub terms of service: [https://docs.github.com/en/site-policy/github-terms/github-terms-of-service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service)

GitHub privacy statement: [https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)
