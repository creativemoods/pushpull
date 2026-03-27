# PushPull

Contributors: jeromesteunenberg
Tags: git, github, generateblocks, content sync, devops
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.0.9
License: GPLv2
License URI: [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Git-backed content workflows for selected WordPress content domains.

> This is a beta plugin. It is still under active development, has limited functionality, and currently supports only a narrow subset of the intended PushPull feature set.

## Description

PushPull stores selected WordPress content in a Git repository using a canonical JSON representation instead of raw database dumps.

The current release supports these managed content domains:

1. GenerateBlocks Global Styles (`gblocks_styles`)
2. GenerateBlocks Conditions (`gblocks_condition`)
3. WordPress Block Patterns (`wp_block`)
4. WordPress Pages (`page`)
5. WordPress Custom CSS (`custom_css`)
6. WordPress Attachments (`attachment`, explicit opt-in only)

PushPull keeps a local Git-like repository inside WordPress database tables, lets you compare live WordPress content against local and remote snapshots, and supports the full workflow from WordPress:

1. Test the remote GitHub connection
2. Commit live managed content into the local repository
3. Initialize an empty remote repository
4. Fetch remote commits into a local tracking ref
5. Diff live, local, and remote states
6. Pull remote changes through fetch + merge
7. Merge remote changes into the local branch
8. Resolve conflicts when needed
9. Apply repository content back into WordPress
10. Push local commits to GitHub

The plugin also includes:

1. A dedicated audit log screen
2. Local repository reset tooling
3. Remote branch reset tooling that creates one commit removing all tracked files from the branch
4. Global and per-domain managed-content views in the admin UI

## Current scope

This is an early, focused release. At the moment, PushPull is intentionally limited to:

1. GitHub as the implemented remote provider
2. Six managed content domains:
   `generateblocks/global-styles/`
   `generateblocks/conditions/`
   `wordpress/block-patterns/`
   `wordpress/pages/`
   `wordpress/custom-css/`
   `wordpress/attachments/`
3. Canonical JSON storage with one file per managed item for manifest-backed sets, plus directory-backed storage for attachments using `attachment.json` and the binary file
4. Explicit opt-in attachment sync through a media-library checkbox

It does not yet manage general posts, menus, forms, `wp_options`, or arbitrary plugin data.

## How PushPull represents content

PushPull does not use WordPress post IDs as repository identity.

For the currently supported managed sets it stores:

1. One canonical JSON file per managed item
2. One separate `manifest.json` file for manifest-backed sets that preserve logical ordering
3. One directory per attachment for the attachments set, containing `attachment.json` and the binary file
4. Stable logical keys instead of environment-specific database IDs
5. Recursive placeholder normalization for current-site absolute URLs in post-type-backed content

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
5. Enable one or more managed content domains in the managed content settings
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

1. `Commit` to snapshot the current live managed-set content into the local repository
2. `Fetch` to import the current remote branch into `refs/remotes/origin/<branch>`
3. Inspect the live/local and local/remote diff views if needed
4. `Pull` for the common fetch + merge flow, or `Merge` manually after fetch when you want review first
5. `Apply repo to WordPress` when you want the local branch state written back into WordPress
6. `Push` when you want local commits published to GitHub

If both local and remote changed, PushPull can persist conflicts, let you resolve them in the admin UI, and then finalize a merge commit.

## Release checklist

1. Make sure `main` is green in GitLab: PHPUnit, PHPCS, PHPStan, package, and PCP.
2. Choose the next semantic version, for example `0.2.0`.
3. Run `composer bump-version -- 0.2.0`.
4. Update the changelog in `README.md` and `readme.txt` for that version.
5. Review the changes in `pushpull.php`, `README.md`, and `readme.txt`.
6. Commit the version bump.
7. Create and push a Git tag in the form `v0.2.0` on that commit.
8. Let GitLab run the tag pipeline.
9. The package job will build `build/pushpull-0.2.0.zip` from that tagged commit.
10. Download the ZIP artifact from the tag pipeline and upload it in WordPress.
11. Verify after upload that WordPress shows the new plugin version correctly.

## Changelog

### 0.0.9

1. Added asynchronous branch actions in the `All Managed Sets` overview so `Fetch`, `Pull`, and `Push` no longer rely on a blocking full-page POST flow.
2. Added modal-based operation progress UI, with indeterminate progress for fetch and determinate progress for push.
3. Added a first-commit guard so PushPull now requires `Fetch` before creating the first local commit when the remote branch already has history.
4. Fixed push planning so unchanged remote objects are reused in the normal linear-history case instead of being counted and uploaded again.

### 0.0.8

1. Added new managed content domains for WordPress custom CSS and WordPress pages.
2. Added a dedicated WordPress attachments domain with directory-backed repository storage using `attachment.json` plus the binary file.
3. Added explicit opt-in attachment sync through a `Sync with PushPull` checkbox in the media library, so only marked attachments are managed.
4. Added `wp_pattern_sync_status` to the owned WordPress block pattern meta allowlist.
5. Refactored the sync engine so managed sets can supply authoritative repository files directly, allowing non-manifest adapter families like attachments.

### 0.0.7

1. Fixed WordPress block pattern apply/export escaping so `\\u002d` sequences survive correctly in both `post_content` and pattern meta.
2. Removed creation and modification timestamps from generic post-type canonical items to avoid false diffs across environments.
3. Changed the `All Managed Sets` overview so each managed set starts collapsed by default.

### 0.0.6

1. Fixed branch commits so committing one managed set no longer removes previously committed managed-set content from the same branch.
2. Reorganized the Managed Content admin UI so branch actions (`Pull`, `Fetch`, `Push`) appear only in the all-managed-sets overview, while per-managed-set views keep only managed-set actions.
3. Moved remote branch reset into Settings alongside the local repository reset controls.
4. Added transparent current-site URL placeholder normalization for post-type-backed managed content so environment-local absolute URLs can round-trip across sites.
5. Split plugin runtime assets from WordPress.org listing assets, with packaging and SVN deploy updated to use the correct directories.

### 0.0.5

1. Added GitHub-backed remote repository support using GitHub's Git Database API.
2. Added end-to-end commit, fetch, pull, merge, conflict resolution, apply, and push workflows in WordPress admin.
3. Added local and remote repository reset actions, audit logging, and operation locking.
4. Added support for multiple managed content domains, including GenerateBlocks conditions and WordPress block patterns.
5. Added release automation for packaging, Plugin Check, WordPress.org SVN deploy, and public GitHub sync.

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

In the current release, the managed content sent to GitHub is limited to the enabled supported domains: GenerateBlocks Global Styles, GenerateBlocks Conditions, WordPress Block Patterns, WordPress Pages, WordPress Custom CSS, and explicitly opted-in WordPress Attachments.

PushPull does not send your whole WordPress database to GitHub. It only sends the managed content represented by the enabled adapters.

GitHub terms of service: [https://docs.github.com/en/site-policy/github-terms/github-terms-of-service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service)

GitHub privacy statement: [https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)
