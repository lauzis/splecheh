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

### --- 0.2.0 ---
- Settings page (via Carbon Fields) lists all public post types as checkboxes.
- Posts and Pages are enabled for spellchecking by default on plugin activation.
- `splecheh_get_enabled_post_types()` helper returns the active post type selection.

### --- 0.1.0 ---
- Initial plugin scaffold with admin menu (Spell Check, Help, Settings subpages).

> This project is maintained with the assistance of [Claude Code](https://claude.ai/code) and [CodeRabbit](https://coderabbit.ai).
