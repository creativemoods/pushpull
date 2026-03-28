=== PushPull ===
Contributors: jeromesteunenberg
Tags: git, github, generateblocks, content sync, devops
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.0.12
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Git-based content sync for WordPress.

== Description ==

PushPull stores selected WordPress content in a Git repository using a canonical JSON representation instead of raw database dumps.

=== Beta notice ===

This is a beta plugin. It is still under active development, has limited functionality, and currently supports only a narrow subset of the intended PushPull feature set.

The current release supports these managed content domains:

1. GenerateBlocks Global Styles (`gblocks_styles`)
2. GenerateBlocks Conditions (`gblocks_condition`)
3. WordPress Block Patterns (`wp_block`)
4. WordPress Pages (`page`)
5. WordPress Custom CSS (`custom_css`)
6. WordPress Attachments (`attachment`, explicit opt-in only)

PushPull keeps a local Git-like repository inside WordPress database tables and supports the following workflow directly from WordPress admin:

1. Test the remote GitHub or GitLab connection
2. Commit live managed content into the local repository
3. Initialize an empty remote repository
4. Fetch remote commits into a local tracking ref
5. Diff live, local, and remote states
6. Pull remote changes through fetch + merge
7. Merge remote changes into the local branch
8. Resolve conflicts when needed
9. Apply repository content back into WordPress
10. Push local commits to GitHub or GitLab

The plugin also includes:

1. An audit log screen
2. Local repository reset tooling
3. Remote branch reset tooling that creates one commit removing all tracked files from the branch
4. Global and per-domain managed-content views in the admin UI

== Current scope ==

This is an early, focused release. At the moment, PushPull is intentionally limited to:

1. GitHub and GitLab as implemented remote providers
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

== How PushPull represents content ==

PushPull does not use WordPress post IDs as repository identity.

For the currently supported managed sets it stores:

1. One canonical JSON file per managed item
2. One separate `manifest.json` file for manifest-backed sets that preserves logical ordering
3. One directory per attachment for the attachments set, containing `attachment.json` and the binary file
4. Stable logical keys instead of environment-specific database IDs
5. Recursive placeholder normalization for current-site absolute URLs in post-type-backed content

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

PushPull currently supports GitHub and GitLab repositories.

For GitHub, grant:

1. Repository metadata read access
2. Repository contents read and write access

For GitLab fine-grained personal access tokens, grant:

1. `Project: Read`
2. `Branch: Read`
3. `Commit: Read`
4. `Commit: Create`
5. `Repository: Read`

In PushPull > Settings:

1. Select `GitHub` or `GitLab` as the provider
2. Enter the repository owner and repository name
3. Enter the target branch
4. Enter the API token
5. Enable one or more managed content domains in the managed content settings
6. Click `Test connection`
7. Save the settings

= Workflow =

The normal workflow is:

1. `Commit` to snapshot the current live managed-set content into the local repository
2. `Fetch` to import the current remote branch into `refs/remotes/origin/<branch>`
3. Inspect the live/local and local/remote diff views if needed
4. `Pull` for the common fetch + merge flow, or `Merge` manually after fetch when you want review first
5. `Apply repo to WordPress` when you want the local branch state written back into WordPress
6. `Push` when you want local commits published to GitHub or GitLab

If both local and remote changed, PushPull can persist conflicts, let you resolve them in the admin UI, and then finalize a merge commit.

When pushing to GitLab, PushPull currently linearizes local merge results into a normal commit on the remote branch instead of preserving merge topology. The merged tree content is preserved; only the remote Git history shape is flattened.

= Empty repositories =

If the configured GitHub or GitLab repository exists but has no commits yet, `Test connection` will report that the repository is reachable but empty.

In that case, click `Initialize remote repository`. PushPull will:

1. create the first commit on the configured branch
2. fetch that initial commit into the local remote-tracking ref
3. make the repository ready for normal commit, fetch, merge, apply, and push workflows

You do not need to create the first commit manually on the provider before using PushPull.

== External services ==

PushPull connects to the GitHub or GitLab API for the repository you configure in the plugin settings.

The plugin uses the provider REST API to:

1. Read repository metadata and the default branch
2. Read and update branch refs
3. Read and create Git objects or provider-equivalent commit actions
4. Test repository access before sync operations

PushPull sends the following information to the configured provider over HTTPS:

1. The repository owner, repository name, branch, and API base URL
2. Your configured API token in the provider-specific authentication header
3. Canonical JSON representations of the managed content you choose to commit and push
4. Commit metadata such as commit messages and, if configured, author name and email

In the current release, the managed content sent to the provider is limited to the enabled supported domains: GenerateBlocks Global Styles, GenerateBlocks Conditions, WordPress Block Patterns, WordPress Pages, WordPress Custom CSS, and explicitly opted-in WordPress Attachments.

PushPull does not send your whole WordPress database to the provider. It only sends the managed content represented by the enabled adapters.

GitHub terms of service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
GitHub privacy statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement
GitLab terms: https://about.gitlab.com/terms/
GitLab privacy statement: https://about.gitlab.com/privacy/

== Changelog ==

= 0.0.12 =

1. Added a new `translation_management` overlay domain with a first WPML-backed implementation that exports only in-scope translation groups for managed content.
2. Added generic overlay-domain support, including separate `Primary domains` and `Overlay domains` sections in settings and clearer visual separation in the Managed Content UI.
3. Added managed-set dependency ordering so hard domain dependencies can be declared explicitly, with GeneratePress elements now ordered after WordPress pages and posts.
4. Added canonical logical-key mapping for GeneratePress element page/post conditions so environment-specific object IDs no longer leak across sites.
5. Added the first overlay-specific apply path so non-post domains like translation management can be applied asynchronously without pretending to be WordPress posts.

= 0.0.11 =

1. Added new managed content domains for WordPress posts and GeneratePress elements.
2. Added asynchronous, chunked `Apply repo to WordPress` operations with modal progress so large apply actions no longer rely on one long blocking request.
3. Moved `Pull`, `Fetch`, and `Push` to the top Managed Content navigation row so branch actions stay available while working inside a specific managed set.
4. Fixed GitLab recursive tree fetching so repositories with more than one page of files no longer silently miss later entries during fetch.
5. Improved attachment apply so WordPress regenerates target-side attachment metadata and image sub-sizes instead of reusing stale source-site thumbnail metadata.

= 0.0.10 =

1. Added a GitLab provider with project, branch, commit, tree, and blob support plus linearized push support for merge results.
2. Added GitLab-specific settings and documentation, including fine-grained PAT permission guidance and a note that remote merge topology is flattened on push.
3. Fixed GitLab push ref tracking so follow-up commits and pushes no longer fail after the first successful push.
4. Fixed chunked GitLab fetch and pull so synthetic root trees survive across async requests.
5. Fixed chunked GitLab push so staged synthetic blobs, trees, and commits are restored correctly across async requests.

= 0.0.9 =

1. Added asynchronous branch actions in the `All Managed Sets` overview so `Fetch`, `Pull`, and `Push` no longer rely on a blocking full-page POST flow.
2. Added modal-based operation progress UI, with indeterminate progress for fetch and determinate progress for push.
3. Added a first-commit guard so PushPull now requires `Fetch` before creating the first local commit when the remote branch already has history.
4. Fixed push planning so unchanged remote objects are reused in the normal linear-history case instead of being counted and uploaded again.

= 0.0.8 =

1. Added new managed content domains for WordPress custom CSS and WordPress pages.
2. Added a dedicated WordPress attachments domain with directory-backed repository storage using `attachment.json` plus the binary file.
3. Added explicit opt-in attachment sync through a `Sync with PushPull` checkbox in the media library, so only marked attachments are managed.
4. Added `wp_pattern_sync_status` to the owned WordPress block pattern meta allowlist.
5. Refactored the sync engine so managed sets can supply authoritative repository files directly, allowing non-manifest adapter families like attachments.

= 0.0.7 =

1. Fixed WordPress block pattern apply/export escaping so `\\u002d` sequences survive correctly in both `post_content` and pattern meta.
2. Removed creation and modification timestamps from generic post-type canonical items to avoid false diffs across environments.
3. Changed the `All Managed Sets` overview so each managed set starts collapsed by default.

= 0.0.6 =

1. Fixed branch commits so committing one managed set no longer removes previously committed managed-set content from the same branch.
2. Reorganized the Managed Content admin UI so branch actions (`Pull`, `Fetch`, `Push`) appear only in the all-managed-sets overview, while per-managed-set views keep only managed-set actions.
3. Moved remote branch reset into Settings alongside the local repository reset controls.
4. Added transparent current-site URL placeholder normalization for post-type-backed managed content so environment-local absolute URLs can round-trip across sites.
5. Split plugin runtime assets from WordPress.org listing assets, with packaging and SVN deploy updated to use the correct directories.

= 0.0.5 =

1. Added GitHub-backed remote repository support using GitHub's Git Database API.
2. Added end-to-end commit, fetch, pull, merge, conflict resolution, apply, and push workflows in WordPress admin.
3. Added local and remote repository reset actions, audit logging, and operation locking.
4. Added support for multiple managed content domains, including GenerateBlocks conditions and WordPress block patterns.
5. Added release automation for packaging, Plugin Check, WordPress.org SVN deploy, and public GitHub sync.

= 0.0.1 =

Initial public release focused on GitHub-backed synchronization of GenerateBlocks Global Styles.
