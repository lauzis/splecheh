/* global splechehCheck */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {

		// Updates a row's Last Checked/Status/Report/Actions cells after a successful run.
		// Shared by the per-row button and the bulk "Re-run Spell Check" action.
		function applyRunResult(row, result) {
			var checkedCell = row.querySelector('[data-colname="Last Checked"]') || row.cells[3];
			if (checkedCell) checkedCell.textContent = result.checked_at_formatted;

			var statusCell = row.querySelector('[data-colname="Status"]') || row.cells[4];
			if (statusCell) {
				statusCell.innerHTML = '<span class="splecheh-badge splecheh-badge--current">' +
					splechehCheck.i18n.upToDate + '</span>';
			}

			var reportCell = row.querySelector('[data-colname="Report"]') || row.cells[5];
			if (reportCell && result.report_url) {
				reportCell.innerHTML = '<a href="' + result.report_url +
					'" target="_blank" rel="noopener">' + splechehCheck.i18n.viewReport + '</a>';
			}

			var actionsCell = row.querySelector('[data-colname="Actions"]') || row.cells[7];
			if (actionsCell && result.actions_html) {
				actionsCell.innerHTML = result.actions_html;
			}
		}

		// Per-row spell check button.
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.splecheh-run-check');
			if (!btn) return;

			var postId = btn.dataset.postId;
			var row = btn.closest('tr');
			var spinner = btn.nextElementSibling;

			btn.disabled = true;
			if (spinner) spinner.style.display = 'inline-block';

			var formData = new FormData();
			formData.append('action', 'splecheh_run');
			formData.append('nonce', splechehCheck.nonce);
			formData.append('post_id', postId);

			fetch(splechehCheck.ajaxUrl, { method: 'POST', body: formData })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					btn.disabled = false;
					if (spinner) spinner.style.display = 'none';

					var msg = document.getElementById('splecheh-check-message');

					if (!data.success) {
						if (msg) {
							msg.className = 'notice notice-error is-dismissible';
							var errData = data.data || {};
							var p = msg.querySelector('p');
							p.textContent = (typeof errData === 'string' ? errData : errData.message) || 'Spell check failed.';
							if (errData.docs_url) {
								var link = document.createElement('a');
								link.href = errData.docs_url;
								link.target = '_blank';
								link.rel = 'noopener';
								link.textContent = 'Learn more';
								p.appendChild(document.createTextNode(' '));
								p.appendChild(link);
							}
							msg.style.display = 'block';
						}
						return;
					}

					var result = data.data;

					applyRunResult(row, result);

					var errorCount = result.error_count;
					if (msg) {
						if (errorCount === 0) {
							msg.className = 'notice notice-success is-dismissible';
							msg.querySelector('p').textContent = splechehCheck.i18n.noErrors;
						} else {
							msg.className = 'notice notice-warning is-dismissible';
							msg.querySelector('p').textContent =
								errorCount + ' ' + splechehCheck.i18n.errorsFound;
						}
						msg.style.display = 'block';
					}
				})
				.catch(function () {
					btn.disabled = false;
					if (spinner) spinner.style.display = 'none';
					var msg = document.getElementById('splecheh-check-message');
					if (msg) {
						msg.className = 'notice notice-error is-dismissible';
						msg.querySelector('p').textContent = 'Spell check request failed. Please try again.';
						msg.style.display = 'block';
					}
				});
		});

		// Run Now button in the status bar.
		var runNowBtn = document.getElementById('splecheh-run-now');
		if (runNowBtn) {
			runNowBtn.addEventListener('click', function () {
				var spinner = document.getElementById('splecheh-run-now-spinner');
				runNowBtn.disabled = true;
				if (spinner) spinner.style.display = 'inline-block';

				var formData = new FormData();
				formData.append('action', 'splecheh_run_now');
				formData.append('nonce', splechehCheck.runNowNonce);

				fetch(splechehCheck.ajaxUrl, { method: 'POST', body: formData })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						runNowBtn.disabled = false;
						if (spinner) spinner.style.display = 'none';

						if (!data.success) {
							return;
						}

						var result = data.data;

						var elLastRun = document.getElementById('splecheh-status-last-run');
						var elIssues  = document.getElementById('splecheh-status-issues');
						var elPending = document.getElementById('splecheh-status-pending');

						if (elLastRun && result.last_run) {
							elLastRun.textContent = splechehCheck.i18n.running
								.replace('…', '') + ': ' + result.last_run;
						}
						if (elIssues) {
							elIssues.textContent = 'Issues (last batch): ' + result.issues_found;
						}
						if (elPending) {
							elPending.textContent = 'Pending: ' + result.posts_pending;
						}
					})
					.catch(function () {
						runNowBtn.disabled = false;
						if (spinner) spinner.style.display = 'none';
					});
			});
		}

		// Bulk "Re-run Spell Check" action.
		var listForm = document.getElementById('splecheh-list-form');
		if (listForm) {
			listForm.addEventListener('click', function (e) {
				var applyBtn = e.target.closest('#doaction, #doaction2');
				if (!applyBtn) return;

				var select = document.getElementById(
					applyBtn.id === 'doaction2' ? 'bulk-action-selector-bottom' : 'bulk-action-selector-top'
				);
				var action = select ? select.value : '';
				if (action !== 'splecheh_bulk_rerun') return;

				e.preventDefault();

				var msg = document.getElementById('splecheh-check-message');
				var checkboxes = Array.prototype.filter.call(
					listForm.querySelectorAll('input[name="post_ids[]"]'),
					function (cb) { return cb.checked; }
				);

				if (checkboxes.length === 0) {
					if (msg) {
						msg.className = 'notice notice-error is-dismissible';
						msg.querySelector('p').textContent = splechehCheck.i18n.selectRows;
						msg.style.display = 'block';
					}
					return;
				}

				var rowsById = {};
				checkboxes.forEach(function (cb) {
					rowsById[cb.value] = cb.closest('tr');
				});

				applyBtn.disabled = true;

				var formData = new FormData();
				formData.append('action', 'splecheh_bulk_run');
				formData.append('nonce', splechehCheck.bulkRunNonce);
				checkboxes.forEach(function (cb) { formData.append('post_ids[]', cb.value); });

				fetch(splechehCheck.ajaxUrl, { method: 'POST', body: formData })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						applyBtn.disabled = false;

						if (!data.success) {
							if (msg) {
								msg.className = 'notice notice-error is-dismissible';
								msg.querySelector('p').textContent = 'Bulk spell check request failed. Please try again.';
								msg.style.display = 'block';
							}
							return;
						}

						var results = data.data.results;
						Object.keys(results).forEach(function (postId) {
							var row = rowsById[postId];
							if (row && results[postId].success) applyRunResult(row, results[postId]);
						});

						if (msg) {
							msg.className = data.data.failure_count > 0 ? 'notice notice-warning is-dismissible' : 'notice notice-success is-dismissible';
							var text = data.data.success_count + ' ' + splechehCheck.i18n.bulkChecked;
							if (data.data.failure_count > 0) {
								text += ' ' + data.data.failure_count + ' ' + splechehCheck.i18n.bulkFailed;
							}
							msg.querySelector('p').textContent = text;
							msg.style.display = 'block';
						}
					})
					.catch(function () {
						applyBtn.disabled = false;
						if (msg) {
							msg.className = 'notice notice-error is-dismissible';
							msg.querySelector('p').textContent = 'Bulk spell check request failed. Please try again.';
							msg.style.display = 'block';
						}
					});
			});
		}

	});
}());
