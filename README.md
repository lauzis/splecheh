# Splecheh — WordPress Spellcheck Plugin

## What is it?
A WordPress plugin that runs spell checks across all articles and post types to surface spelling errors in a single view.

## Who's it for?
Editors and content managers who need to maintain writing quality across a WordPress site without reviewing each post individually.

## What it does
- Scans all posts and custom post types for spelling errors.
- Lists all spellcheck issues in a central admin view.
- Admin menu accessible to editors (and above).
- Optional Interpunction Check: uses an LLM to fix punctuation and capitalization, sentence by sentence (see below).

## Interpunction Check
Interpunction Check is a separate, opt-in feature (Settings > Interpunction Check, disabled by default) that reviews punctuation and capitalization using an LLM instead of a dictionary — sentence by sentence, with the same Run Now/Re-run, report/status tracking, and Details page (Fix / Ignore in post / Ignore always / Mark Complete) as Spell Check.

Settings:
- **Enable Interpunction Check** — shows the "Interpunction Check" page in the admin menu.
- **Require Spell Check First** — enabled by default; skips Interpunction Check for a post (Run Now, bulk runs, background check) until its Spell Check is up to date with zero unresolved issues.
- **Type** — how the request is made: `Commandline - Local model`, `OpenAI`, `Claude`, or `Gemini`.
- **Commandline Command** — shown only for the Commandline type (see contract below); defaults to the bundled `tools/llm-wrapper.php`, which calls the `claude` CLI unless the Local Model dropdown below selects an Ollama model.
- **Local Model (via wrapper)** — shown only for the Commandline type; picks an Ollama model (Qwen 2.5 3B/7B/14B/32B) to append to the Commandline Command as `--provider ollama --model <selection>`. Left on its default, the command runs as typed (`claude`). See [`tools/README.md`](tools/README.md) for setup.
- **Endpoint** — optional override of the default API URL; shown only for OpenAI/Claude/Gemini.
- **Access Key** — API token for OpenAI/Claude/Gemini; not needed (or stored) for Commandline.
- **Prompt** — instruction sent to the LLM; defaults to `You are a professional {language} editor. Your only task is to fix the punctuation and capitalization of the provided text. Keep the original text content exactly as is. Output only the corrected text.` — `{language}` is replaced with the post's language.
- **Sentence Chunk Size** — how many sentences are sent per LLM call (default 5, 0 disables chunking); see below for why this exists. Also filterable via `splecheh_interpunction_chunk_size`, which takes precedence over this field.
- **Background Interpunction Check** — Enable, Schedule Interval (default every 10 minutes), and Batch Size (default 1 post per run) for an automatic WP-Cron check of outdated posts, mirroring Background Spell Check.

A post's sentences are sent to the provider in chunks, not all in one call — a real post can have far more sentences than the Settings page "Test" button's small sample, and a single call for dozens of sentences can need much longer than any reasonable timeout, especially for a CLI/local model. Chunking keeps each call's timeout meaningful and means one slow/failing chunk doesn't necessarily require redoing the whole post; the trade-off is that a large post now takes several sequential calls (and correspondingly longer in total) instead of one — see `tools/README.md` for measured per-call timings you can use to size the chunk value for your setup.

### Commandline contract
For the Commandline type, Splecheh runs the configured shell command with a single, shell-escaped argument: a JSON object `{"language": "...", "prompt": "...", "sentences": ["...", ...]}` — one chunk's worth of sentences per call, not necessarily the whole post (see chunking above). This keeps API keys out of WordPress — the script owns its own credentials (e.g. to call a locally-hosted model).

The Commandline Command field is the command itself (e.g. `claude -p`) — it does **not** support `{prompt}`-style placeholders. The JSON payload above (including the prompt) is always appended as a single shell-escaped trailing argument, never interpolated into the command string.

The script must print a JSON array to stdout, one item per input sentence and in the same order:

```
[{"original": "...", "fixed": "...", "explanation": "..."}, ...]
```

The process is run with a timeout (60 seconds by default, filterable via `splecheh_interpunction_command_timeout`); a command that hangs or exceeds the timeout fails with a clear error instead of hanging the request.

A non-zero exit code is treated as a failure, with stderr shown as the error message. See [`bin/interpunction-check.sh`](bin/interpunction-check.sh) for a working (dummy, pass-through) reference implementation of this contract, or [`tools/llm-wrapper.php`](tools/llm-wrapper.php) for a real one that calls the `claude` CLI or a local Ollama model — see [`tools/README.md`](tools/README.md) for setup, including `tools/local-model.sh` to start/stop a local Ollama server.

OpenAI/Claude/Gemini are called directly via `wp_remote_post` (no composer SDK) using each provider's default chat/completion endpoint.

## Aspell dependency
Spell checking is performed by [`tigitz/php-spellchecker`](https://github.com/tigitz/php-spellchecker), which shells out to the system `aspell` command (or uses the PHP `pspell` extension, when installed, which is itself backed by Aspell). Each language needs its own Aspell wordlist package installed on the server — it is **not** bundled with the plugin or with Aspell itself.

If a post's language has no wordlist installed, spell checking fails with an error like:

```
No word lists can be found for the language "lv".
```

Splecheh detects this before running the check and shows a message naming the missing language and the command to fix it, instead of a raw error. To install a wordlist (replace `lv` with the language code shown in the message):

```
sudo apt-get update
sudo apt-get install aspell-<language-code>
```

For example, `sudo apt-get install aspell-lv` installs the Latvian dictionary. Available packages vary by OS; on Debian/Ubuntu, search with `apt-cache search aspell-`.

## Support
This is a free, open-source plugin. Support is limited and provided on a best-effort basis.

The plugin is built for specific project needs. There is no guarantee it will work on all configurations.

## Development
Built with the assistance of [CodeRabbit](https://coderabbit.ai) for code review and [Claude Code](https://claude.ai/code) for implementation.

Run `composer install` to pull in dev dependencies (PHPUnit), then `composer test` to run the test suite. The committed `vendor/` folder is production-only (no dev dependencies), so the plugin works as-is without running Composer.

## Change log

### --- 0.22.0 ---
- Added a "Require Spell Check First" Interpunction Check setting (enabled by default): a post is skipped for Interpunction Check — Run Now, bulk runs, and the background check — until its Spell Check is up to date with zero unresolved issues, so punctuation/capitalization isn't "fixed" on text that still has known spelling errors. Backed by `Splecheh_SpellCheckReport::is_clean()`. The background check's post-selection query filters these posts out at the SQL level (not just inside the check itself) — with the default batch size of 1, filtering only after selection would let the cron get stuck retrying the same ineligible post forever instead of moving on. The Settings page "Test" button is unaffected. Verified live: blocked instantly (no LLM call) for a post with unresolved spelling issues, proceeded normally for a clean one.
- Added a "Spell Check vs Interpunction Check" section to the Help page explaining why the above setting defaults to on: Spell Check runs purely in code (Aspell, a local dictionary lookup) so it's effectively free, while Interpunction Check calls an LLM per batch of sentences — comparatively slow and, for paid providers, a real cost per request.
- Moved the Settings page's "Logs" tab to the end (after Background Interpunction Check) — it's the least frequently visited section.
- The Settings page's active tab title is now bold, so it's clearer at a glance which section is showing.

### --- 0.21.1 ---
- Removed the "Report" column from the Spell Check table too (already done for Interpunction Check in 0.21.0) — it duplicated the "View Report" link already in the Actions column.
- Added a Table of Contents to the Help page, and a "Running a Local Model" section under Interpunction Check summarizing how to run the Commandline type against a local Ollama model via `tools/llm-wrapper.php`/`tools/local-model.sh`, with a pointer to `tools/README.md` for full setup and benchmarks.

### --- 0.21.0 ---
- Bumped the plugin version (used as the cache-busting query param on enqueued JS/CSS) — several JS files changed across the 0.20.0 work without a version bump, so a browser with a cached copy of e.g. `interpunction-details.js` from before the "Mark Complete" button existed would show the button but it wouldn't do anything (no click handler in the stale script). The backend itself was never broken — confirmed working end to end in a fresh browser session.
- Added a "Diff" column to the Interpunction Check Details page, right after "Original": shows the fixed sentence with only the changed word(s) in `<strong>`, so you can see at a glance what an LLM actually changed instead of re-reading the whole sentence. Word-level diff (`Splecheh_InterpunctionReport::diff_highlight()`, LCS-based) included in both the initial page render and the `format_issues_for_details()` payload used by re-run/fix/ignore AJAX responses.

### --- 0.20.0 ---
- `tools/llm-wrapper.php` now supports `--provider claude|ollama` and `--model <name>` flags (defaulting to `claude`, unchanged from before), so the Commandline Interpunction Check can call a local Ollama model instead of the `claude` CLI.
- Added `tools/local-model.sh` to start/stop a local Ollama server outside of systemd, with PID tracking (`tools/.run/ollama.pid`) and a `warm` command to pre-load a model into memory so the first real check isn't slowed by a cold load. It reuses (and won't kill) an already-running Ollama instance, e.g. one managed by systemd.
- Added `tools/README.md` documenting both the `claude` CLI and local-Ollama setup paths, including the env vars (`SPLECHEH_OLLAMA_HOST`, `SPLECHEH_OLLAMA_MODEL`, `SPLECHEH_OLLAMA_KEEP_ALIVE`, `SPLECHEH_OLLAMA_TIMEOUT`), the need to raise `splecheh_interpunction_command_timeout` for local models, and measured generation speed per model size (CPU-only, no GPU offload) to help pick a model that's actually fast enough for this feature — `qwen2.5:7b` is the default.
- Added `tools/benchmark.sh` to compare `claude` vs one or more local Ollama models side by side (timing + output) against the same canned sentences used by the Settings page "Test" button; warms each Ollama model before timing it so results reflect generation speed, not model-load time.
- `llm-wrapper.php`'s `claude` timeout is now overridable via `SPLECHEH_CLAUDE_TIMEOUT` (was a hardcoded 55s) — needed for real posts with many sentences, which can easily exceed it; see the new "Timeouts for real posts, not just test batches" section in `tools/README.md`.
- The Settings page "Test Interpunction Check" button now has a "Command override (this test run only, not saved)" field (Commandline type only), so you can try a different provider/model (e.g. a local Ollama model vs `claude`) without changing the saved Commandline Command.
- Each saved Interpunction Check report now records which provider/model produced it (`provider`/`model` fields), shown on the Details page as "Checked with: …". `Splecheh_InterpunctionBackend::get_model_label()` resolves this from the saved settings (the API model filter for OpenAI/Claude/Gemini, or a `--model` flag parsed out of the Commandline Command).
- Settings > Interpunction Check now defaults the Commandline Command to the bundled `tools/llm-wrapper.php`, and adds a "Local Model (via wrapper)" dropdown (Qwen 2.5 3B/7B/14B/32B) that appends `--provider ollama --model <selection>` to the command automatically; left on its default option, the command runs as typed (`claude` via the wrapper).
- Added Background Interpunction Check: a new Settings section (Enable, Schedule Interval — default every 10 minutes, Batch Size — default 1 post per run, since each post is a full LLM request) and matching WP-Cron integration (`Splecheh_InterpunctionCron`), mirroring Background Spell Check but checking only posts outdated per `_splecheh_interpunction_checked_at`. The Interpunction Check page now shows the same status bar (last run, issues, pending, Run Now) as the Spell Check page.
- Fixed real posts with many sentences reliably failing on "Run Now"/background checks with "exceeded the timeout" even after raising the timeout: a single call for a whole post's sentences could need far longer than any reasonable timeout (a real post's sentences take much longer per-sentence via `claude`/local models than the Settings page "Test" button's small sample). `Splecheh_InterpunctionBackend::check()` now sends a post's sentences in chunks (5 per call by default) across multiple calls instead of one, merging the results in order.
- Added a "Sentence Chunk Size" Settings field for the above (default 5, 0 disables chunking), alongside the existing `splecheh_interpunction_chunk_size` filter which takes precedence over it.
- If a chunk fails partway through a check, the chunks that already succeeded are now saved as a partial report (`chunks_processed`/`chunks_total` fields) instead of discarding that work — the failure is still reported to the caller, but a re-run isn't starting from nothing. The Interpunction Check table has a new "Chunks" column (e.g. "6/11") showing this, flagged when incomplete; missing for reports saved before this field existed or posts with no sentences to check.
- Added a "Mark Complete" button to both the Spell Check and Interpunction Check Details pages: resolves every remaining issue on that post (same as manually ignoring each one) and pushes the checked-at timestamp an hour into the future, so the post reads as up to date even if it's resaved again shortly after. Doesn't touch post content — for when an editor has reviewed the flagged words/sentences and decided none need fixing, without dismissing each one individually. Backed by `Splecheh_SpellCheckReport::mark_complete()` / `Splecheh_InterpunctionReport::mark_complete()`.
- The Settings page is now split into tabs (Spell Check, Background Spell Check, Logs, Interpunction Check, Background Interpunction Check) instead of one long scrolling list of fields with separators, so each section is visually distinct and easier to navigate to.
- Interpunction Check reports now record how long the check took (`duration_seconds`), shown on the Details page next to "Checked with" (e.g. "Checked with: qwen2.5:7b (took 14.6s)"). The Settings page "Test" button's success/failure message shows the same for that run.
- Interpunction Check reports now also record the total sentence count (`sentence_count`), shown on the Details page (e.g. "took 14.6s for 52 sentence(s)").
- Removed the "Report" column from the Interpunction Check table — it duplicated the "View Report" link already in the Actions column.

### --- 0.19.1 ---
- Fixed the Commandline Interpunction Check (and its Settings page "Test" button) hanging indefinitely — and returning an empty response with nothing in the Logs — for commands that block on stdin or fill a pipe buffer. `check_commandline()` now runs the command via Symfony `Process` with an explicit timeout (60s by default, filterable via `splecheh_interpunction_command_timeout`), and both the commandline call and the Test button now write start/failure entries to Logs.
- Clarified the Commandline Command help text and README: the command string does not support `{prompt}`-style placeholders — the JSON payload (including the prompt) is always appended as a single shell-escaped trailing argument.

### --- 0.19.0 ---
- Added a "Test Interpunction Check" button to the Settings page: runs the configured provider against a fixed set of canned example sentences (using the currently saved Type/Prompt/language settings) and shows the outcome inline, with expandable "Example request" and "Result" sections; on failure, the error message returned by the call is shown instead of a generic failure.
- Updated the default Interpunction Check Prompt to explicitly document the required `{original, fixed, explanation}` JSON array output format, matching the instructions already sent to OpenAI/Claude/Gemini — so Commandline-type scripts receive the same format guidance in the prompt text.

### --- 0.18.0 ---
- Added Interpunction Check: an opt-in, LLM-based punctuation/capitalization check that runs sentence by sentence, separate from the aspell-based Spell Check. New Settings section (Enable, Type — Commandline/OpenAI/Claude/Gemini, Commandline Command, Endpoint, Access Key, Prompt), a standalone "Interpunction Check" admin page (paginated table with post type/search filters, Run Now/Re-run, status/issues columns), and a Details page per post listing each flagged sentence with Fix / Ignore in post / Ignore always actions and bulk actions.
- The Commandline type calls a locally-configured shell command with the sentences/prompt as a JSON parameter and expects a JSON array of `{original, fixed, explanation}` on stdout, keeping LLM API keys out of WordPress; see `bin/interpunction-check.sh` for the reference contract. OpenAI/Claude/Gemini are called directly via `wp_remote_post`.
- Reports are saved as JSON under `wp-content/uploads/splecheh-interpunction/`, with per-post meta (issue count, checked-at, plugin version) and outdated detection mirroring Spell Check.

### --- 0.17.0 ---
- Added a "Show also posts with 0 spellcheck issues" checkbox filter to the Spell Check table, unchecked by default. By default the table now only shows posts that are outdated/never checked or have at least one unresolved issue; ticking the checkbox shows all posts from the enabled post types, as before. The filter state persists across pagination and sorting like the post type/search filters.

### --- 0.16.2 ---
- Fixed the "Re-run Spell Check" bulk action doing nothing on Apply: the post type/search filter bar rendered its own nested `<form>` inside the Spell Check table's form, which the browser's HTML parser resolved by closing the outer form early — leaving the bulk action select, checkboxes, and Apply button outside of it in the DOM, so the JS click handler never saw the click. The filter bar now reuses the table's form instead of nesting a second one.

### --- 0.16.1 ---
- Removed the redundant "Details" column from the Spell Check table; the Details link is still available in the Actions column.

### --- 0.16.0 ---
- Added an "Issues" column to the Spell Check table showing each post's unresolved issue count, sortable so posts can be ordered by how many issues they have.
- The unresolved issue count is now stored in a `_splecheh_issue_count` post meta field, kept in sync whenever a report is written (manual/bulk/Details re-run, background cron) or an issue is resolved via Fix/Ignore. Posts checked before this field existed show "—" until rechecked, the same as posts that have never been checked.

### --- 0.15.0 ---
- Added a "Re-run Spell Check" bulk action to the Spell Check table: select rows via the new checkbox column and re-run the check for all of them at once, with each row's status/report/details links updating in place when done.
- Bulk runs are logged (start/completion, including failure counts) consistent with existing logging for manual and cron-triggered checks.

### --- 0.14.0 ---
- Each spell check report now also saves the plugin version it was generated with, in a `_splecheh_version` post meta field.
- Added an "Invalidate Spell Check on Plugin Version Change" setting (Settings page), disabled by default; when enabled, a report is also considered outdated if its stored plugin version differs from the current one, in addition to the existing post-edited-since-last-check rule. This affects the Spell Check table's status column and the background cron batch.

### --- 0.13.0 ---
- Added a "Splecheh Spell Check" widget to the WordPress admin Dashboard, showing the count of unresolved spelling errors, the count of articles with unresolved errors, and the count of ignored words (global + per-post), with a link to the Spell Check page.

### --- 0.12.0 ---
- Added a "Re-run Spell Check" button to the Spell Check Details page; it re-runs the check for that post and refreshes the issues list in place, without leaving the page.
- The Details page re-run reuses the same spell check logic and missing-Aspell-wordlist error handling as the Spell Check table's "Run Now"/"Re-run" button.

### --- 0.11.0 ---
- Fixed spell check false positives at paragraph/element boundaries: `prepare_text()` now replaces block-level closing tags (`</p>`, `</div>`, `</li>`, etc.) and `<br>` with a space before stripping HTML, so adjoining sentences (e.g. across `<p>` tags) are no longer merged into a single word.

### --- 0.10.0 ---
- Spell Check Details page now renders the flagged word in **bold** within the sentence excerpt, making it easier to spot at a glance.

### --- 0.9.0 ---
- Added an "Ignore Shortcodes" setting (Settings page), enabled by default; when on, shortcode literals (e.g. `[shortcode attr="value"]` or `[shortcode]content[/shortcode]`) are excluded from spell checking instead of being flagged as misspellings.

### --- 0.8.0 ---
- Spell check now detects a missing Aspell wordlist for the post's language before running, and returns a friendly error naming the language, the `aspell-<language-code>` install command, and a link to the new "Aspell dependency" README section, instead of a raw process exception.
- Added an "Aspell dependency" section to the README documenting that spell checking relies on system Aspell wordlists per language.

### --- 0.7.0 ---
- Added an "Enable Logs" setting (Settings page); disabling it stops new log entries from being written and hides the Logs submenu/page entirely.

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
