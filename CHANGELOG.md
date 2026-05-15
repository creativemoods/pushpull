# Changelog

## 0.0.30

1. Fixed WordPress menu export on WPML-filtered sites so translated menus such as `footer-menu-en` and `main-menu` no longer disappear from local exports when the current admin language hides part of the `nav_menu` term set.
2. Hardened WPML translation-management menu export to recover the full translated menu set from WPML translation rows when the normal menu term query is incomplete, keeping menu manifests and translation groups aligned.
3. Fixed WPML configuration apply so it now mirrors repository state instead of patching existing settings in place, removing stale post type translation modes and stale post type slug translation records that were previously left behind after apply.
4. Hardened branch push safety so PushPull no longer rewrites local refs backward when a provider reports a successful push but leaves the remote branch head unchanged; that case now surfaces as a push failure instead of silently discarding the local head.
5. Expanded regression coverage and test-harness fidelity around WPML language-filtered menu queries, multilingual export/apply flows, and WPML configuration cache behavior.

## 0.0.29

1. Added the new `Sync Status` branch-centric admin screen, including branch action buttons, branch summaries, common-ancestor and divergence visibility, and a graphical commit view that separates shared history from local-only and remote-tracking-only commits.
2. Improved commit workflow clarity by moving commit-stack visibility out of `Managed Content` into `Sync Status`, keeping `Managed Content` focused on domain actions while `Sync Status` surfaces ahead/behind state and pending branch commits.
3. Fixed async branch-action flow around the new screen, including keeping users on the originating page after actions complete and refining the in-plugin commit-message prompt used by branch and domain commit actions.
4. Fixed multilingual WordPress page apply so language-suffixed logical keys like `blog--en` and `questions-frequentes--fr` no longer overwrite the wrong live page or collapse back into the wrong language during the same apply pass, with immediate WPML language stamping after insert or update.
5. Fixed WordPress block pattern round-trip churn by limiting managed pattern taxonomies to the owned pattern collections and language terms, ignoring extra unmanaged terms such as WPML `translation_priority`, and added focused regression and linting follow-up coverage for the new sync and multilingual paths.

## 0.0.28

1. Added a dedicated `Sync Status` admin screen with branch-level action buttons, commit-stack summary cards, and a graphical branch view that highlights shared history, common ancestry, and local-versus-remote divergence.
2. Improved commit visibility and authoring flows by surfacing outgoing and incoming branch commits in the admin UI and by prompting for editable commit messages for both `Commit + Push All` and individual domain `Commit` actions.
3. Changed bulk `Commit + Push All` to create one combined branch commit per operation instead of one commit per managed domain, while keeping a configurable default bulk commit message in Settings.
4. Added a repository-operations monitor to the Audit Log, including operation status summaries, payload and result inspection, and best-effort cancellation for resumable async branch actions.
5. Fixed several branch-state and workflow edge cases, including false `WordPress attachments` absence warnings, async action redirects that jumped back to `Managed Content` instead of staying on the originating screen, and improved admin handling around the new sync-status flows.

## 0.0.27

1. Added a Settings-page performance diagnostics panel that reports whether `ZipArchive` is available, with a non-blocking warning when the GitLab cold-fetch archive optimization cannot be used.
2. Improved GitLab cold fetch performance by hydrating only the head snapshot tree during initial imports, while still importing full commit ancestry without recursively scanning every historical tree.
3. Added GitLab archive-preload fallback reporting in fetch progress and completion messages so the UI now states explicitly when archive preload succeeds, is reused, or falls back to standard object fetches.
4. Hardened GitLab archive preload handling around rate limits and temporary file cleanup, including graceful fallback when archive downloads are throttled or unavailable.
5. Fixed WPML translation-management apply so destination post lookups ignore current-language filtering, preventing translated page mappings from disappearing during full-suite and multilingual apply flows.

## 0.0.26

1. Fixed repeated fetch performance so PushPull stops redownloading already imported commits, trees, and blobs, making subsequent fetches complete against only the true remote delta.
2. Improved push performance, including GitLab push planning, by reusing locally materialized tracked-remote and staged commit file maps instead of rereading the full remote tree state during small pushes.
3. Refactored fetch and push so synchronous and asynchronous flows now share the same core traversal and push-state implementations, eliminating duplicated logic between the normal and chunked execution paths.
4. Reworked the async architecture into a generic `AsyncOperationEngine` with per-operation handlers, then split branch actions into dedicated handlers for fetch/pull, push, apply, reset remote branch, `Commit + Push All`, and `Pull + Apply All`.
5. Renamed the remaining branch-specific async wrapper to `BranchAsyncOperationCoordinator` so the codebase now distinguishes clearly between the generic async engine and the branch-operation coordination layer built on top of it.

## 0.0.25

1. Fixed WordPress menu and WPML translation-management exports so translated menus are exported consistently even when WPML filters the admin request to a single language, preventing partial menu manifests and one-sided translation groups after `Commit + Push All`.
2. Updated the custom PushPull admin footer on plugin screens so `PushPull` links to the WordPress.org plugin page, `Creative Moods` links to the project site, and the five-star review prompt remains available directly from the footer.

## 0.0.24

1. Added site sync modes for `both`, `push_only`, and `pull_only` so environments can explicitly block live-content apply operations or remote push operations while still allowing safe fetch and pull alignment flows.
2. Improved Managed Content branch-action behavior and status wording, including clearer `live`, `local`, `remote`, and `diverged` drift summaries plus plain `Pull` availability when bootstrapping an empty local branch from fetched remote history.
3. Improved diff and bulk-operation safety by surfacing real managed-set export errors in the UI and skipping erroring domains during `Commit + Push All` instead of aborting the whole operation.
4. Added translation-aware logical keys for generic post-type managed domains when `translation_management` is enabled, allowing translated items with the same human-facing name to export cleanly while keeping duplicate-key failures explicit otherwise.
5. Moved release history and maintainer release process into dedicated documentation files, with packaging and public-repository export updated to include the new docs layout.

## 0.0.23

1. Fixed the Managed Content branch-action gating so `Pull` is available after `Fetch` when the local branch is still empty and only the remote-tracking branch exists.
2. Improved managed-set status summaries to report state drift as `live`, `local`, and `remote` counts instead of the more ambiguous previous shorthand.
3. Clarified focused Managed Content detail summaries so they show the state-drift breakdown alongside the existing pairwise `Live vs local` and `Local vs remote` diff counts.

## 0.0.22

1. Refreshed PushPull branding across the project and plugin assets, including new banner and logo artwork plus updated WordPress.org icons and banners.
2. Updated the WordPress admin screens with branded page headers and improved styling so Settings, Domains, Managed Content, and Audit Log share a more polished visual identity.
3. Hardened remote-availability state tracking so fetch status is marked up to date after fetch, pull, push, and remote-initialize/reset flows, avoiding stale `Fetch` prompts after successful sync operations.
4. Fixed remote repository initialization so the local branch and `HEAD` are seeded from the first fetched remote commit when starting from an empty local repository.

## 0.0.21

1. Replaced the WordPress admin menu Dashicon with a custom SVG icon bundled in the plugin assets.
2. Normalized the menu SVG markup for WordPress admin sizing so the icon renders at the expected menu scale.

## 0.0.20

1. Added a new `wpml_configuration` config domain that exports and applies core WPML setup state, including default language, active languages, URL format, post type translation modes, and translated post type slugs.
2. Refactored WPML activation logic into the integration layer and added a reusable site-key activation service instead of leaving the registration spike in ad hoc CLI-only code.
3. Improved WPML setup and translation-management availability handling on bare installs so WPML domains are selectable earlier in the bootstrap flow.
4. Hardened WPML slug-translation apply and export behavior, including persistence of translated slug values and more resilient handling of cache and table state during tests and apply operations.

## 0.0.19

1. Fixed the `wp pushpull config enable-domain` and `disable-domain` subcommands so domain enablement can be managed reliably from WP-CLI.
2. Fixed the Domains page so available plugin overlay domains such as WPML translation management and Real Media Library media organization are checkable again.
3. Improved the Domains page by collapsing integration groups that currently have nothing checkable, reducing placeholder noise while keeping the long-term structure visible.

## 0.0.18

1. Added requirements for packagist.org

## 0.0.17

1. Added new primary domains for WordPress comments, categories, and tags.
2. Added generic managed-domain support for discovered custom post types and custom taxonomies, with opt-in enablement from a dedicated Domains screen.
3. Moved domain selection out of Settings into a dedicated Domains page organized by WordPress core, installed plugin integrations, and custom content.
4. Added bulk `Commit + Push All` and `Pull + Apply All` workflows for full-site bootstrap and deployment scenarios.
5. Added a `wp pushpull` WP-CLI interface covering status, domains, configuration, sync operations, conflict resolution, and the new bulk workflows.

## 0.0.16

1. Added a lightweight recurring remote-head availability check so PushPull can cheaply detect when the remote branch likely has updates available for `Fetch`.
2. Added a configurable `Remote fetch check interval` setting with a default of `5` minutes.
3. Updated the Managed Content UI so the `Fetch` button stays enabled but is visually highlighted when the latest scheduled check detects a newer remote head.
4. Extended the action popover system so enabled actions like `Fetch` can surface contextual notices, not only disabled-state reasons.
5. Added focused scheduler and fetch-availability tests covering cached state, settings changes, and cron rescheduling behavior.

## 0.0.15

1. Added a new primary `wordpress_menus` domain with canonical JSON export and apply for WordPress menus.
2. Added v1 menu location support so theme menu assignments round-trip alongside menu structure.
3. Added canonical menu item references for pages, posts, taxonomies, post-type archives, and custom links, with hierarchy preserved through `parentItemKey`.
4. Added focused menu export/apply coverage and nav-menu bootstrap support in the test suite.
5. Updated the readme functionality description so WordPress menus are listed as a supported primary domain.

## 0.0.14

1. Added a new `media_organization` overlay domain with a first Real Media Library-backed adapter that stores canonical attachment-to-folder path assignments instead of plugin-specific folder IDs.
2. Expanded `WordPress core configuration` with `wordpress_permalink_settings` support for the site's permalink structure.
3. Added a PushPull status dropdown in the WordPress admin bar with high-level live vs local and local vs remote summaries plus quick links into Managed Content and the Audit Log.
4. Extended the WordPress pages and posts domains to own GeneratePress layout override meta, including legacy page-editor keys used for sidebar layout, footer widgets, full-width content, and disabled elements.
5. Hardened the admin and provider integration edges with safer admin-bar status fallback behavior and PHPStan bootstrap stubs for Real Media Library functions.

## 0.0.13

1. Added a first core config domain, `WordPress core configuration`, with `wordpress_reading_settings` support for `show_on_front`, `page_on_front`, and `page_for_posts`.
2. Added canonical page logical-key references for reading settings so front-page and posts-page options can round-trip across environments without leaking WordPress IDs.
3. Added a dedicated config-domain apply path so non-post WordPress configuration can be applied cleanly without pretending to be posts or overlays.
4. Introduced `Config domains` as a separate UI family alongside `Primary domains` and `Overlay domains` in settings and Managed Content.
5. Changed the Managed Content screen to show only enabled domains, so disabled managed sets no longer clutter the tabs or overview.

## 0.0.12

1. Added a new `translation_management` overlay domain with a first WPML-backed implementation that exports only in-scope translation groups for managed content.
2. Added generic overlay-domain support, including separate `Primary domains` and `Overlay domains` sections in settings and clearer visual separation in the Managed Content UI.
3. Added managed-set dependency ordering so hard domain dependencies can be declared explicitly, with GeneratePress elements now ordered after WordPress pages and posts.
4. Added canonical logical-key mapping for GeneratePress element page/post conditions so environment-specific object IDs no longer leak across sites.
5. Added the first overlay-specific apply path so non-post domains like translation management can be applied asynchronously without pretending to be WordPress posts.

## 0.0.11

1. Added new managed content domains for WordPress posts and GeneratePress elements.
2. Added asynchronous, chunked `Apply repo to WordPress` operations with modal progress so large apply actions no longer rely on one long blocking request.
3. Moved `Pull`, `Fetch`, and `Push` to the top Managed Content navigation row so branch actions stay available while working inside a specific managed set.
4. Fixed GitLab recursive tree fetching so repositories with more than one page of files no longer silently miss later entries during fetch.
5. Improved attachment apply so WordPress regenerates target-side attachment metadata and image sub-sizes instead of reusing stale source-site thumbnail metadata.

## 0.0.10

1. Added a GitLab provider with project, branch, commit, tree, and blob support plus linearized push support for merge results.
2. Added GitLab-specific settings and documentation, including fine-grained PAT permission guidance and a note that remote merge topology is flattened on push.
3. Fixed GitLab push ref tracking so follow-up commits and pushes no longer fail after the first successful push.
4. Fixed chunked GitLab fetch and pull so synthetic root trees survive across async requests.
5. Fixed chunked GitLab push so staged synthetic blobs, trees, and commits are restored correctly across async requests.

## 0.0.9

1. Added asynchronous branch actions in the `All Managed Sets` overview so `Fetch`, `Pull`, and `Push` no longer rely on a blocking full-page POST flow.
2. Added modal-based operation progress UI, with indeterminate progress for fetch and determinate progress for push.
3. Added a first-commit guard so PushPull now requires `Fetch` before creating the first local commit when the remote branch already has history.
4. Fixed push planning so unchanged remote objects are reused in the normal linear-history case instead of being counted and uploaded again.

## 0.0.8

1. Added new managed content domains for WordPress custom CSS and WordPress pages.
2. Added a dedicated WordPress attachments domain with directory-backed repository storage using `attachment.json` plus the binary file.
3. Added explicit opt-in attachment sync through a `Sync with PushPull` checkbox in the media library, so only marked attachments are managed.
4. Added `wp_pattern_sync_status` to the owned WordPress block pattern meta allowlist.
5. Refactored the sync engine so managed sets can supply authoritative repository files directly, allowing non-manifest adapter families like attachments.

## 0.0.7

1. Fixed WordPress block pattern apply/export escaping so `\\u002d` sequences survive correctly in both `post_content` and pattern meta.
2. Removed creation and modification timestamps from generic post-type canonical items to avoid false diffs across environments.
3. Changed the `All Managed Sets` overview so each managed set starts collapsed by default.

## 0.0.6

1. Fixed branch commits so committing one managed set no longer removes previously committed managed-set content from the same branch.
2. Reorganized the Managed Content admin UI so branch actions (`Pull`, `Fetch`, `Push`) appear only in the all-managed-sets overview, while per-managed-set views keep only managed-set actions.
3. Moved remote branch reset into Settings alongside the local repository reset controls.
4. Added transparent current-site URL placeholder normalization for post-type-backed managed content so environment-local absolute URLs can round-trip across sites.
5. Split plugin runtime assets from WordPress.org listing assets, with packaging and SVN deploy updated to use the correct directories.

## 0.0.5

1. Added GitHub-backed remote repository support using GitHub's Git Database API.
2. Added end-to-end commit, fetch, pull, merge, conflict resolution, apply, and push workflows in WordPress admin.
3. Added local and remote repository reset actions, audit logging, and operation locking.
4. Added support for multiple managed content domains, including GenerateBlocks conditions and WordPress block patterns.
5. Added release automation for packaging, Plugin Check, WordPress.org SVN deploy, and public GitHub sync.

## 0.0.1

Initial public release focused on GitHub-backed synchronization of GenerateBlocks Global Styles.
