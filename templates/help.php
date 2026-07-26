<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Help', 'splecheh' ); ?></h1>

	<h2><?php esc_html_e( 'Table of Contents', 'splecheh' ); ?></h2>
	<ul style="list-style:disc;padding-left:1.5em;">
		<li><a href="#spell-check"><?php esc_html_e( 'Spell Check', 'splecheh' ); ?></a></li>
		<li><a href="#spell-check-vs-interpunction-check"><?php esc_html_e( 'Spell Check vs Interpunction Check', 'splecheh' ); ?></a></li>
		<li>
			<a href="#interpunction-check"><?php esc_html_e( 'Interpunction Check', 'splecheh' ); ?></a>
			<ul style="list-style:circle;padding-left:1.5em;">
				<li><a href="#commandline-contract"><?php esc_html_e( 'Commandline Contract', 'splecheh' ); ?></a></li>
				<li><a href="#running-a-local-model"><?php esc_html_e( 'Running a Local Model', 'splecheh' ); ?></a></li>
			</ul>
		</li>
		<li>
			<a href="#setting-up-a-real-cron-job"><?php esc_html_e( 'Setting Up a Real Cron Job', 'splecheh' ); ?></a>
			<ul style="list-style:circle;padding-left:1.5em;">
				<li><a href="#step-1-disable-wp-cron"><?php esc_html_e( 'Step 1 — Disable WP-Cron', 'splecheh' ); ?></a></li>
				<li><a href="#step-2-add-a-system-cron-entry"><?php esc_html_e( 'Step 2 — Add a System Cron Entry', 'splecheh' ); ?></a></li>
				<li><a href="#why-real-cron"><?php esc_html_e( 'Why Real Cron?', 'splecheh' ); ?></a></li>
			</ul>
		</li>
	</ul>

	<h2 id="spell-check"><?php esc_html_e( 'Spell Check', 'splecheh' ); ?></h2>

	<p>
		<?php esc_html_e( 'Spell Check flags two kinds of issue, shown in the "Type" column on the Details page: "spelling" for a word Aspell does not recognise, and "whitespace" for a run of two or more spaces or tabs between two words.', 'splecheh' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'A double space is invisible to readers — HTML collapses whitespace when the page is rendered — but it is still noise in the source, and it breaks Interpunction Check\'s sentence matching, because sentences are stored with their whitespace normalized. For that reason the run is shown as ␣␣ in the table, since it would otherwise look exactly like a correct single space. The Replacement field is pre-filled with the collapsed text, so fixing one is a single click.', 'splecheh' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'Never flagged as whitespace issues: spacing inside <pre> and <code> blocks (where it is deliberate), shortcodes, HTML comments, tag attributes, and the indentation and line breaks of the block markup itself. "Fix everywhere" and "Ignore always" are not offered for these rows either — a stray double space is noise in one post, not a word worth adding to a language-wide list. "Ignore in post" works as usual.', 'splecheh' ); ?>
	</p>

	<h2 id="spell-check-vs-interpunction-check"><?php esc_html_e( 'Spell Check vs Interpunction Check', 'splecheh' ); ?></h2>

	<p>
		<?php esc_html_e( 'Spell Check is done purely in code — Aspell, a local dictionary lookup — so it is effectively free: no API calls, no LLM, negligible compute per post. Interpunction Check calls an LLM (a local model or a paid API) for every batch of sentences, which is comparatively slow and, for paid providers, has a real cost per request.', 'splecheh' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'This is why Interpunction Check has a "Require Spell Check First" setting, on by default: since Spell Check is basically free to run, it makes sense to make sure a post\'s spelling is already clean before spending the much more expensive Interpunction Check on it.', 'splecheh' ); ?>
	</p>

	<h2 id="interpunction-check"><?php esc_html_e( 'Interpunction Check', 'splecheh' ); ?></h2>

	<p>
		<?php esc_html_e( 'Interpunction Check uses an LLM to fix punctuation and capitalization, sentence by sentence, separately from the aspell-based Spell Check. It is off by default — enable it under Settings > Interpunction Check.', 'splecheh' ); ?>
	</p>

	<p><?php esc_html_e( 'Settings:', 'splecheh' ); ?></p>
	<ul style="list-style:disc;padding-left:1.5em;">
		<li><?php esc_html_e( 'Enable Interpunction Check — shows the "Interpunction Check" page in the menu.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Require Spell Check First — on by default; skips a post for Interpunction Check until its Spell Check is up to date with zero unresolved issues. Applying an interpunction fix re-runs Spell Check for that post automatically, so a fix never blocks the post by making its own Spell Check outdated — and a spelling error introduced by the fix is caught right away.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Type — how the request is made: Commandline (local model/script), OpenAI, Claude, or Gemini.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Commandline Command — shown only for the Commandline type.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Endpoint — optional override of the default API URL; shown only for OpenAI/Claude/Gemini.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Access Key — API token for OpenAI/Claude/Gemini; not needed for Commandline.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Prompt — instruction sent to the LLM; use {language} as a placeholder for the post\'s language.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Command Timeout (seconds) — Commandline type only; how long one call may take before it is killed and reported as an error (default 60). Raise it if a chunk legitimately needs longer, but remember a browser-triggered Run Now is also bound by the server\'s own limits (PHP max_execution_time, PHP-FPM request_terminate_timeout, the web server\'s proxy/FastCGI read timeout). Lowering the Sentence Chunk Size is usually the better fix for timeouts.', 'splecheh' ); ?></li>
	</ul>

	<h3 id="commandline-contract"><?php esc_html_e( 'Commandline Contract', 'splecheh' ); ?></h3>

	<p>
		<?php esc_html_e( 'For the Commandline type, Splecheh runs the configured shell command with a single argument: a JSON object of the shape {language, prompt, sentences}. This keeps API keys out of WordPress — the script is responsible for its own credentials.', 'splecheh' ); ?>
	</p>

	<p><?php esc_html_e( 'The script must print a JSON array to stdout, one item per input sentence and in the same order:', 'splecheh' ); ?></p>

	<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">[{"original": "...", "fixed": "...", "explanation": "..."}, ...]</pre>

	<p>
		<?php esc_html_e( 'A non-zero exit code is treated as a failure, with stderr shown as the error message. See bin/interpunction-check.sh in the plugin folder for a working (dummy) reference implementation of this contract.', 'splecheh' ); ?>
	</p>

	<h3 id="running-a-local-model"><?php esc_html_e( 'Running a Local Model', 'splecheh' ); ?></h3>

	<p>
		<?php esc_html_e( 'The bundled tools/llm-wrapper.php Commandline script (the default) can call either the claude CLI or a local Ollama model instead of a paid API — useful if you\'d rather not send post content to a third party, or want to avoid API costs. To run a model locally:', 'splecheh' ); ?>
	</p>

	<ol style="padding-left:1.5em;">
		<li><?php esc_html_e( 'Install Ollama and pull a model, e.g.:', 'splecheh' ); ?>
			<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">curl -fsSL https://ollama.com/install.sh | sh
ollama pull qwen2.5:7b</pre>
		</li>
		<li><?php esc_html_e( 'Start the model server with the bundled helper script (or rely on Ollama\'s own systemd service, if it installed one):', 'splecheh' ); ?>
			<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">wp-content/plugins/splecheh/tools/local-model.sh start --model qwen2.5:7b</pre>
		</li>
		<li><?php esc_html_e( 'In Settings > Interpunction Check, leave Commandline Command as the default tools/llm-wrapper.php, and pick your model from the "Local Model (via wrapper)" dropdown — it appends --provider ollama --model <selection> to the command automatically.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Raise the timeout for real posts (a single test sentence is fast, but a whole post needs much longer): set "Command Timeout (seconds)" above — it is passed through to the wrapper automatically, so there is no second value to keep in sync.', 'splecheh' ); ?></li>
	</ol>

	<p>
		<?php esc_html_e( 'Pick a model size that\'s actually fast enough for your hardware, not just one that fits in RAM — without a GPU with enough VRAM, larger models can be far too slow to be practical for this feature. See tools/README.md in the plugin folder for measured speeds per model size, the full env var reference, and tools/benchmark.sh to compare providers/models on your own server.', 'splecheh' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'Note: the Qwen models offered in the "Local Model" dropdown are more proof-of-concept than production-ready. Testing so far has focused on speed, using short English example sentences — quality on real content in smaller/less-common languages hasn\'t been specifically verified and could be worse than with claude or a hosted API. Test against your own posts (Settings > Interpunction Check > Test button) before relying on a local model for a non-English site.', 'splecheh' ); ?>
	</p>

	<h2 id="setting-up-a-real-cron-job"><?php esc_html_e( 'Setting Up a Real Cron Job', 'splecheh' ); ?></h2>

	<p>
		<?php esc_html_e( 'By default, WordPress uses a pseudo-cron ("WP-Cron") that only fires when someone visits your site. This means scheduled tasks are unreliable on low-traffic sites. A real system cron job is faster and guaranteed to run on time.', 'splecheh' ); ?>
	</p>

	<h3 id="step-1-disable-wp-cron"><?php esc_html_e( 'Step 1 — Disable WP-Cron', 'splecheh' ); ?></h3>

	<p><?php esc_html_e( 'Add this line to your wp-config.php (before the "That\'s all, stop editing!" comment):', 'splecheh' ); ?></p>

	<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">define( 'DISABLE_WP_CRON', true );</pre>

	<h3 id="step-2-add-a-system-cron-entry"><?php esc_html_e( 'Step 2 — Add a System Cron Entry', 'splecheh' ); ?></h3>

	<p><?php esc_html_e( 'Run crontab -e on your server and add one of the following entries. Replace the URL or path with your own site.', 'splecheh' ); ?></p>

	<p><strong><?php esc_html_e( 'Option A — wget (HTTP request):', 'splecheh' ); ?></strong></p>
	<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron &>/dev/null</pre>

	<p><strong><?php esc_html_e( 'Option B — WP-CLI (recommended when available):', 'splecheh' ); ?></strong></p>
	<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet</pre>

	<p>
		<?php esc_html_e( 'Both examples run every 5 minutes. Adjust the interval to match your needs.', 'splecheh' ); ?>
	</p>

	<h3 id="why-real-cron"><?php esc_html_e( 'Why Real Cron?', 'splecheh' ); ?></h3>

	<ul style="list-style:disc;padding-left:1.5em;">
		<li><?php esc_html_e( 'Reliability — tasks run even when no one is visiting the site.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Performance — WP-Cron adds overhead to every page request; a system cron does not.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Accuracy — tasks fire at the scheduled time, not whenever the next visitor happens to arrive.', 'splecheh' ); ?></li>
	</ul>
</div>
