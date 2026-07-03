/* global jQuery, splechehInterpunctionTest */
(function ($) {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var button = document.getElementById('splecheh-interpunction-test-button');
		if (!button) return;

		var spinner = button.nextElementSibling;
		var msg = document.getElementById('splecheh-interpunction-test-message');
		var requestEl = document.getElementById('splecheh-interpunction-test-request');
		var resultEl = document.getElementById('splecheh-interpunction-test-result');
		var postInput = document.getElementById('splecheh-interpunction-test-post');
		var postIdInput = document.getElementById('splecheh-interpunction-test-post-id');

		if (postInput && $.fn.autocomplete) {
			// Clearing the field by hand (not via selecting a suggestion) drops the
			// post id, reverting the test to the built-in example sentences.
			postInput.addEventListener('input', function () {
				if (postInput.value === '') postIdInput.value = '';
			});

			$(postInput).autocomplete({
				minLength: 2,
				delay: 300,
				source: function (request, response) {
					var params = new URLSearchParams({
						action: 'splecheh_interpunction_test_search_posts',
						nonce: splechehInterpunctionTest.nonce,
						s: request.term
					});
					fetch(splechehInterpunctionTest.ajaxUrl + '?' + params.toString())
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

		button.addEventListener('click', function () {
			button.disabled = true;
			if (spinner) spinner.style.display = 'inline-block';
			if (msg) msg.style.display = 'none';

			var formData = new FormData();
			formData.append('action', 'splecheh_interpunction_test');
			formData.append('nonce', splechehInterpunctionTest.nonce);
			if (postIdInput && postIdInput.value) {
				formData.append('post_id', postIdInput.value);
			}

			fetch(splechehInterpunctionTest.ajaxUrl, { method: 'POST', body: formData })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					button.disabled = false;
					if (spinner) spinner.style.display = 'none';

					var errData = data.data || {};

					if (requestEl && errData.payload) {
						requestEl.textContent = JSON.stringify(errData.payload, null, 2);
					}

					if (data.success) {
						if (resultEl) resultEl.textContent = JSON.stringify(errData.result, null, 2);
						if (msg) {
							msg.className = 'notice notice-success is-dismissible';
							msg.querySelector('p').textContent = 'Test succeeded.';
							msg.style.display = 'block';
						}
					} else {
						if (resultEl) resultEl.textContent = '';
						if (msg) {
							msg.className = 'notice notice-error is-dismissible';
							msg.querySelector('p').textContent =
								errData.message || splechehInterpunctionTest.i18n.requestFailed;
							msg.style.display = 'block';
						}
					}
				})
				.catch(function () {
					button.disabled = false;
					if (spinner) spinner.style.display = 'none';
					if (msg) {
						msg.className = 'notice notice-error is-dismissible';
						msg.querySelector('p').textContent = splechehInterpunctionTest.i18n.requestFailed;
						msg.style.display = 'block';
					}
				});
		});
	});
}(jQuery));
