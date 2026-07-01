# Splecheh — WordPress Spellcheck Plugin

## What is it?
A WordPress plugin that runs spell checks across all articles and post types to surface spelling errors in a single view.

## Who's it for?
Editors and content managers who need to maintain writing quality across a WordPress site without reviewing each post individually.

## What it does
- Scans all posts and custom post types for spelling errors.
- Lists all spellcheck issues in a central admin view.
- Admin menu accessible to editors (and above).

## Support
This is a free, open-source plugin. Support is limited and provided on a best-effort basis.

The plugin is built for specific project needs. There is no guarantee it will work on all configurations.

## Development
Built with the assistance of [CodeRabbit](https://coderabbit.ai) for code review and [Claude Code](https://claude.ai/code) for implementation.

## Change log

### --- 0.6.0 ---
- Fixed the Spell Check table's Actions column rendering empty: `column_status`, `column_report`, `column_details`, and `column_actions` were declared `private`, which broke `WP_List_Table`'s automatic `column_<name>` dispatch (fatal on PHP 8). Changed to `protected`.
- Actions column now shows a "Run Now" / "Re-run" button per row, plus — once a report exists — links to the report JSON and the Spell Check Details page; updates in place after an AJAX run completes.
- Added start/failure logging for the manual per-row spell check and the WP-Cron background batch (including skip reasons: already running, no post types enabled), to make silent no-ops diagnosable.

### --- 0.5.0 ---
- "Details (N)" button added to each row of the Spell Check table, linking (in a new tab) to a per-post Spell Check Details page; N is the count of unresolved issues from the post's report.
- Spell Check Details page lists each issue (word, sentence excerpt, editable replacement) with row actions: Fix (search-and-replace the specific occurrence), Ignore in post, and Ignore always — plus a bulk-action dropdown to apply any of the three to multiple selected issues at once.
- Fix/Ignore actions mark the issue resolved in the saved report JSON; no forced re-check, consistent with the existing outdated-detection / manual "Run Now" flow.
- New Settings > Ignore List page lists and removes globally-ignored words, scoped per language.
- `splecheh_get_language_code( $post_id )` helper added: resolves a post's language via Polylang, then WPML, then the plugin's Settings language / site locale fallback. Used for per-post spell check language and for scoping the global ignore list.
- Spell check now skips words ignored for the specific post (post meta) or globally ignored for the post's language.

### --- 0.4.0 ---
- Background spell check: enable/disable toggle, configurable schedule interval (1 min – 24 h), and configurable batch size (default 50) added to Settings page.
- Custom WP-Cron schedules registered for all supported intervals; cron event is automatically registered/deregistered when settings change.
- Cron callback processes only posts that are never checked or outdated (post edited since last check), up to the configured batch size, with a transient lock to prevent concurrent runs.
- Status bar on the Spell Check page shows last run time, issues found in the last batch, and posts still pending; includes a "Run Now" button for immediate on-demand execution.

### --- 0.3.0 ---
- Spell Check page: paginated table of posts from enabled post types, with filter by post type and text search.
- Per-row "Spell Check" button triggers an AJAX spell check via `tigitz/php-spellchecker` (aspell / pspell).
- Reports are saved as UUID-named JSON files in `wp-content/uploads/splecheh/` and linked via post meta.
- Report JSON includes: wrong word, up to 5 suggestions, and the sentence excerpt where the error occurs.
- Status column shows whether the report is current, outdated (post edited since last check), or never checked.
- Language setting added to Settings page; defaults to the WordPress site locale.

### --- 0.2.0 ---
- Settings page (via Carbon Fields) lists all public post types as checkboxes.
- Posts and Pages are enabled for spellchecking by default on plugin activation.
- `splecheh_get_enabled_post_types()` helper returns the active post type selection.

### --- 0.1.0 ---
- Initial plugin scaffold with admin menu (Spell Check, Help, Settings subpages).

> This project is maintained with the assistance of [Claude Code](https://claude.ai/code) and [CodeRabbit](https://coderabbit.ai).
