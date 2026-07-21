/* global jQuery, splechehSplitTest */
(function ($) {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var button = document.getElementById('splecheh-split-test-button');
		if (!button) return;

		var spinner = button.nextElementSibling;
		var msg = document.getElementById('splecheh-split-test-message');
		var results = document.getElementById('splecheh-split-test-results');
		var textarea = document.getElementById('splecheh-split-test-content');
		var exampleSelect = document.getElementById('splecheh-split-test-example');
		var ignoreShortcodes = document.getElementById('splecheh-split-test-ignore-shortcodes');
		var postInput = document.getElementById('splecheh-split-test-post');
		var postIdInput = document.getElementById('splecheh-split-test-post-id');
		var clearPost = document.getElementById('splecheh-split-test-clear-post');

		var examples = {};
		var examplesEl = document.getElementById('splecheh-split-test-examples');
		if (examplesEl) {
			try {
				examples = JSON.parse(examplesEl.textContent);
			} catch (e) {
				examples = {};
			}
		}

		// Picking an example fills the textarea and clears any selected post.
		if (exampleSelect) {
			exampleSelect.addEventListener('change', function () {
				if (examples[exampleSelect.value] !== undefined) {
					textarea.value = examples[exampleSelect.value];
					clearSelectedPost();
				}
			});
		}

		function clearSelectedPost() {
			if (postIdInput) postIdInput.value = '';
			if (postInput) postInput.value = '';
		}

		if (clearPost) {
			clearPost.addEventListener('click', clearSelectedPost);
		}

		if (postInput && $.fn.autocomplete) {
			postInput.addEventListener('input', function () {
				if (postInput.value === '') postIdInput.value = '';
			});

			$(postInput).autocomplete({
				minLength: 2,
				delay: 300,
				source: function (request, response) {
					var params = new URLSearchParams({
						action: 'splecheh_split_test_search_posts',
						nonce: splechehSplitTest.nonce,
						s: request.term
					});
					fetch(splechehSplitTest.ajaxUrl + '?' + params.toString())
						.then(function (r) { return r.json(); })
						.then(function (data) {
							response(data.success ? data.data : []);
						})
						.catch(function () {
							response([]);
						});
				},
				select: function (event, ui) {
					postIdInput.value = ui.item.id;
					postInput.value = ui.item.label;
					return false;
				}
			});
		}

		function escapeHtml(text) {
			var div = document.createElement('div');
			div.textContent = text == null ? '' : String(text);
			return div.innerHTML;
		}

		function renderChunks(chunks) {
			if (!chunks.length) {
				results.innerHTML = '<p>' + escapeHtml(splechehSplitTest.i18n.noChunks) + '</p>';
				return;
			}

			var html = '<table class="widefat striped"><thead><tr>' +
				'<th style="width:40px;">#</th>' +
				'<th style="width:90px;">Tag</th>' +
				'<th>' + escapeHtml(splechehSplitTest.i18n.plainText) + '</th>' +
				'<th>' + escapeHtml(splechehSplitTest.i18n.sentences) + '</th>' +
				'<th>' + escapeHtml(splechehSplitTest.i18n.innerHtml) + '</th>' +
				'</tr></thead><tbody>';

			chunks.forEach(function (chunk, i) {
				var tagLabel = chunk.tag ? '&lt;' + escapeHtml(chunk.tag) + '&gt;' : escapeHtml(splechehSplitTest.i18n.looseText);
				var sentences = (chunk.sentences || []).map(function (s) {
					return '<li>' + escapeHtml(s) + '</li>';
				}).join('');

				html += '<tr>' +
					'<td>' + (i + 1) + '</td>' +
					'<td><code>' + tagLabel + '</code></td>' +
					'<td>' + escapeHtml(chunk.text) + '</td>' +
					'<td><ol style="margin:0 0 0 1.2em;">' + sentences + '</ol></td>' +
					'<td><code style="white-space:pre-wrap;word-break:break-word;">' + escapeHtml(chunk.html) + '</code></td>' +
					'</tr>';
			});

			html += '</tbody></table>';
			results.innerHTML = html;
		}

		button.addEventListener('click', function () {
			button.disabled = true;
			if (spinner) spinner.style.display = 'inline-block';
			if (msg) msg.style.display = 'none';

			var formData = new FormData();
			formData.append('action', 'splecheh_split_test');
			formData.append('nonce', splechehSplitTest.nonce);
			if (ignoreShortcodes && ignoreShortcodes.checked) {
				formData.append('ignore_shortcodes', '1');
			}
			if (postIdInput && postIdInput.value) {
				formData.append('post_id', postIdInput.value);
			} else {
				formData.append('content', textarea.value);
			}

			fetch(splechehSplitTest.ajaxUrl, { method: 'POST', body: formData })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					button.disabled = false;
					if (spinner) spinner.style.display = 'none';

					var payload = data.data || {};

					if (data.success) {
						renderChunks(payload.chunks || []);
						if (msg) {
							msg.className = 'notice notice-success';
							msg.querySelector('p').textContent =
								payload.chunk_count + ' ' + splechehSplitTest.i18n.chunksFound;
							msg.style.display = 'block';
						}
					} else {
						if (msg) {
							msg.className = 'notice notice-error';
							msg.querySelector('p').textContent =
								payload.message || splechehSplitTest.i18n.requestFailed;
							msg.style.display = 'block';
						}
					}
				})
				.catch(function () {
					button.disabled = false;
					if (spinner) spinner.style.display = 'none';
					if (msg) {
						msg.className = 'notice notice-error';
						msg.querySelector('p').textContent = splechehSplitTest.i18n.requestFailed;
						msg.style.display = 'block';
					}
				});
		});
	});
}(jQuery));
