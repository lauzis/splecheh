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
