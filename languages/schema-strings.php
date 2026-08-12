<?php
/**
 * Translation manifest — GENERATED, do not edit.
 *
 * Produced by bin/schema-i18n from the settings schema JSON. Exists so
 * `wp i18n make-pot` can see strings that live in JSON rather than in PHP.
 * Never loaded at runtime.
 *
 * Regenerate with:
 *   bin/schema-i18n --domain=splecheh --out=languages/schema-strings.php config/settings.json
 */

return;

__( '@callback:splecheh_interpunction_test_field', 'splecheh' );
__( 'A post\'s sentences are sent to the provider this many at a time per call, not all at once — a real post can have far more sentences than a quick test, and a single call for dozens of sentences can take too long to finish before it times out (especially for a local model or a long post). Lower this if calls still time out; raise it to use fewer, larger calls if your provider handles it comfortably. Set to 0 to disable chunking (send everything in one call). Default: 5.', 'splecheh' );
__( 'API token used to authenticate with the selected provider. Not needed (or stored) for the Commandline type.', 'splecheh' );
__( 'Access Key', 'splecheh' );
__( 'Background Interpunction Check', 'splecheh' );
__( 'Background Spell Check', 'splecheh' );
__( 'Batch Size', 'splecheh' );
__( 'Claude', 'splecheh' );
__( 'Command Timeout (seconds)', 'splecheh' );
__( 'Commandline - Local model', 'splecheh' );
__( 'Commandline Command', 'splecheh' );
__( 'Don\'t check', 'splecheh' );
__( 'Double Spaces', 'splecheh' );
__( 'Enable Background Interpunction Check', 'splecheh' );
__( 'Enable Background Spell Check', 'splecheh' );
__( 'Enable Interpunction Check', 'splecheh' );
__( 'Endpoint', 'splecheh' );
__( 'Every 1 hour', 'splecheh' );
__( 'Every 1 minute', 'splecheh' );
__( 'Every 10 minutes', 'splecheh' );
__( 'Every 12 hours', 'splecheh' );
__( 'Every 15 minutes', 'splecheh' );
__( 'Every 2 hours', 'splecheh' );
__( 'Every 24 hours', 'splecheh' );
__( 'Every 30 minutes', 'splecheh' );
__( 'Every 4 hours', 'splecheh' );
__( 'Every 5 minutes', 'splecheh' );
__( 'Every 8 hours', 'splecheh' );
__( 'Fix automatically on every run', 'splecheh' );
__( 'Gemini', 'splecheh' );
__( 'How long a single Commandline call may take before it is killed and reported as an error. Raise this if a chunk legitimately needs longer than the default — but note that a browser-triggered "Run Now" is also bound by the server\'s own limits (PHP max_execution_time, PHP-FPM request_terminate_timeout, the web server\'s proxy/FastCGI read timeout), which must all exceed this value for a longer timeout to have any effect. Lowering the Sentence Chunk Size above is usually the better fix for timeouts. When the command runs the bundled tools/llm-wrapper.php, the wrapper is automatically given this value minus 5 seconds unless the command already sets its own --timeout. Default: 60.', 'splecheh' );
__( 'How often the background interpunction check should run.', 'splecheh' );
__( 'How often the background spell check should run.', 'splecheh' );
__( 'How the interpunction check request is made.', 'splecheh' );
__( 'Ignore Shortcodes', 'splecheh' );
__( 'Instruction sent to the LLM. Use {language} as a placeholder for the post\'s language. Must tell the LLM to output only a JSON array of {original, fixed, explanation} objects, one per input sentence and in the same order — see the default value for the expected wording.', 'splecheh' );
__( 'Interpunction Check', 'splecheh' );
__( 'Invalidate Spell Check on Plugin Version Change', 'splecheh' );
__( 'Language code for the spell checker (e.g. "en", "fr", "de"). Defaults to the WordPress site language (%s).', 'splecheh' );
__( 'Local Model (via wrapper)', 'splecheh' );
__( 'Number of posts to check per background run. Default: 1 — each post is a full LLM request, so this is deliberately smaller than the Spell Check batch size, especially for local/slower models.', 'splecheh' );
__( 'Number of posts to check per background run. Default: 50.', 'splecheh' );
__( 'Only applies when the Commandline Command above calls tools/llm-wrapper.php: appends "--provider ollama --model <selection>" to it automatically. Requires the model pulled (ollama pull) and tools/local-model.sh start running — see tools/README.md. Leave on the first option to use the command as typed (e.g. the claude CLI). Note: these Qwen models are more proof-of-concept than production-ready — testing so far has focused on speed using English example sentences; quality on real content in smaller/less-common languages hasn\'t been specifically verified and could be worse than with claude or a hosted API.', 'splecheh' );
__( 'OpenAI', 'splecheh' );
__( 'Optional: override the default API endpoint for the selected provider.', 'splecheh' );
__( 'Post Types to Spellcheck', 'splecheh' );
__( 'Prompt', 'splecheh' );
__( 'Qwen 2.5 14B — slower, better quality', 'splecheh' );
__( 'Qwen 2.5 32B — very slow without a GPU', 'splecheh' );
__( 'Qwen 2.5 3B — fastest, rougher fixes', 'splecheh' );
__( 'Qwen 2.5 7B — recommended balance', 'splecheh' );
__( 'Report as issues', 'splecheh' );
__( 'Require Spell Check First', 'splecheh' );
__( 'Schedule Interval', 'splecheh' );
__( 'Select which post types should be checked for spelling errors. Posts and Pages are enabled by default.', 'splecheh' );
__( 'Sentence Chunk Size', 'splecheh' );
__( 'Shell command to run for the "Commandline - Local model" type, e.g. "claude -p" or the bundled tools/llm-wrapper.php. The JSON parameter — {language, prompt, sentences} — is always appended as a single shell-escaped trailing argument; the command string itself does not support {prompt}-style placeholders. Must print a JSON array of {original, fixed, explanation} on stdout. See bin/interpunction-check.sh for the reference contract, or tools/README.md for the bundled wrapper. Keeps API keys out of WordPress: the script owns its own credentials.', 'splecheh' );
__( 'Spell Check', 'splecheh' );
__( 'Spell Check Language', 'splecheh' );
__( 'Type', 'splecheh' );
__( 'What to do about runs of two or more spaces or tabs between two words. "Report as issues" lists them on the Details page for review, like spelling errors. "Fix automatically" collapses them during every spell check run without asking — the equivalent of "Fix everywhere" — and records each run in the auto-apply log; safe, since a browser collapses them anyway. "Don\'t check" ignores them entirely. Spacing inside <pre>/<code> blocks, shortcodes, HTML comments and tag attributes is never touched in any mode.', 'splecheh' );
__( 'When enabled (default), Interpunction Check is skipped for a post until its Spell Check is up to date with zero unresolved issues — so punctuation/capitalization isn\'t "fixed" on text that still has known spelling errors. Applies to Run Now, bulk runs, and the background check; the Settings page "Test" button is unaffected.', 'splecheh' );
__( 'When enabled, a report is also considered outdated if it was generated by a different plugin version than the one currently active, in addition to the existing "post edited since last check" rule.', 'splecheh' );
__( 'When enabled, an "Interpunction Check" page is added to the menu that uses an LLM to check punctuation and capitalization, sentence by sentence.', 'splecheh' );
__( 'When enabled, interpunction check runs automatically in the background according to the schedule below. Each run calls the configured LLM provider, so keep the batch size small for slower providers (e.g. a local model without a GPU).', 'splecheh' );
__( 'When enabled, shortcode literals (e.g. "[shortcode attr=\\"value\\"]") are excluded from spell checking.', 'splecheh' );
__( 'When enabled, spell check runs automatically in the background according to the schedule below.', 'splecheh' );
__( '— Use Commandline Command as-is (e.g. Claude CLI) —', 'splecheh' );
