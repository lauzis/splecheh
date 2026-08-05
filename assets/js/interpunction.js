/* global splechehInterpunctionCheck */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {

		// Updates a row's Last Checked/Status/Chunks/Actions cells after a run —
		// including a partial (chunk failed partway through) save, not just a full
		// success, since InterpunctionReport::run() may still have saved a report.
		// Shared by the per-row button and the bulk "Re-run Interpunction Check" action.
		function applyRunResult(row, result) {
			var checkedCell = row.querySelector('[data-colname="Last Checked"]') || row.cells[3];
			if (checkedCell) checkedCell.textContent = result.checked_at_formatted;

			var statusCell = row.querySelector('[data-colname="Status"]') || row.cells[4];
			if (statusCell) {
				statusCell.innerHTML = '<span class="splecheh-badge splecheh-badge--current">' +
					splechehInterpunctionCheck.i18n.upToDate + '</span>';
			}

			var chunksCell = row.querySelector('[data-colname="Chunks"]') || row.cells[6];
			if (chunksCell) {
				var total = result.chunks_total;
				if (total === null || total === undefined || total <= 0) {
					chunksCell.innerHTML = '&mdash;';
				} else {
					var label = result.chunks_processed + '/' + total;
					chunksCell.innerHTML = result.chunks_processed < total
						? '<span class="splecheh-badge splecheh-badge--outdated" title="' +
							splechehInterpunctionCheck.i18n.incompleteChunks + '">' + label + '</span>'
						: label;
				}
			}

			var actionsCell = row.querySelector('[data-colname="Actions"]') || row.cells[8];
			if (actionsCell && result.actions_html) {
				actionsCell.innerHTML = result.actions_html;
			}
		}

		// Per-row interpunction check button.
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.splecheh-interpunction-run-check');
			if (!btn) return;

			var postId = btn.dataset.postId;
			var row = btn.closest('tr');
			var spinner = btn.nextElementSibling;

			btn.disabled = true;
			if (spinner) spinner.style.display = 'inline-block';

			var formData = new FormData();
			formData.append('action', 'splecheh_interpunction_run');
			formData.append('nonce', splechehInterpunctionCheck.nonce);
			formData.append('post_id', postId);

			fetch(splechehInterpunctionCheck.ajaxUrl, { method: 'POST', body: formData })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					btn.disabled = false;
					if (spinner) spinner.style.display = 'none';

					var msg = document.getElementById('splecheh-interpunction-check-message');

					if (!data.success) {
						var errData = data.data || {};

						// A chunk can fail partway through and still leave a partial report
						// saved (see InterpunctionReport::run()) — reflect that in the row
						// instead of leaving it showing stale pre-run data.
						if (errData.row) applyRunResult(row, errData.row);

						if (msg) {
							msg.className = 'notice notice-error is-dismissible';
							var p = msg.querySelector('p');
							p.textContent = (typeof errData === 'string' ? errData : errData.message) || 'Interpunction check failed.';
							msg.style.display = 'block';
						}
						return;
					}

					var result = data.data;

					applyRunResult(row, result);

					var issueCount = result.issue_count;
					if (msg) {
						if (issueCount === 0) {
							msg.className = 'notice notice-success is-dismissible';
							msg.querySelector('p').textContent = splechehInterpunctionCheck.i18n.noIssues;
						} else {
							msg.className = 'notice notice-warning is-dismissible';
							msg.querySelector('p').textContent =
								issueCount + ' ' + splechehInterpunctionCheck.i18n.issuesFound;
						}
						msg.style.display = 'block';
					}
				})
				.catch(function () {
					btn.disabled = false;
					if (spinner) spinner.style.display = 'none';
					var msg = document.getElementById('splecheh-interpunction-check-message');
					if (msg) {
						msg.className = 'notice notice-error is-dismissible';
						msg.querySelector('p').textContent = 'Interpunction check request failed. Please try again.';
						msg.style.display = 'block';
					}
				});
		});

		// Run Now button in the status bar.
		var runNowBtn = document.getElementById('splecheh-interpunction-run-now');
		if (runNowBtn) {
			runNowBtn.addEventListener('click', function () {
				var spinner = document.getElementById('splecheh-interpunction-run-now-spinner');
				runNowBtn.disabled = true;
				if (spinner) spinner.style.display = 'inline-block';

				var formData = new FormData();
				formData.append('action', 'splecheh_interpunction_run_now');
				formData.append('nonce', splechehInterpunctionCheck.runNowNonce);

				fetch(splechehInterpunctionCheck.ajaxUrl, { method: 'POST', body: formData })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						runNowBtn.disabled = false;
						if (spinner) spinner.style.display = 'none';

						if (!data.success) return;

						var result = data.data;

						var elLastRun = document.getElementById('splecheh-interpunction-status-last-run');
						var elIssues = document.getElementById('splecheh-interpunction-status-issues');
						var elPending = document.getElementById('splecheh-interpunction-status-pending');

						if (elLastRun && result.last_run) {
							elLastRun.textContent = 'Last run: ' + result.last_run;
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

		// Bulk "Re-run Interpunction Check" action.
		var listForm = document.getElementById('splecheh-interpunction-list-form');
		if (listForm) {
			listForm.addEventListener('click', function (e) {
				var applyBtn = e.target.closest('#doaction, #doaction2');
				if (!applyBtn) return;

				var select = document.getElementById(
					applyBtn.id === 'doaction2' ? 'bulk-action-selector-bottom' : 'bulk-action-selector-top'
				);
				var action = select ? select.value : '';
				if (action !== 'splecheh_interpunction_bulk_rerun') return;

				e.preventDefault();

				var msg = document.getElementById('splecheh-interpunction-check-message');
				var checkboxes = Array.prototype.filter.call(
					listForm.querySelectorAll('input[name="post_ids[]"]'),
					function (cb) { return cb.checked; }
				);

				if (checkboxes.length === 0) {
					if (msg) {
						msg.className = 'notice notice-error is-dismissible';
						msg.querySelector('p').textContent = splechehInterpunctionCheck.i18n.selectRows;
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
				formData.append('action', 'splecheh_interpunction_bulk_run');
				formData.append('nonce', splechehInterpunctionCheck.bulkRunNonce);
				checkboxes.forEach(function (cb) { formData.append('post_ids[]', cb.value); });

				fetch(splechehInterpunctionCheck.ajaxUrl, { method: 'POST', body: formData })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						applyBtn.disabled = false;

						if (!data.success) {
							if (msg) {
								msg.className = 'notice notice-error is-dismissible';
								msg.querySelector('p').textContent = 'Bulk interpunction check request failed. Please try again.';
								msg.style.display = 'block';
							}
							return;
						}

						var results = data.data.results;
						Object.keys(results).forEach(function (postId) {
							var row = rowsById[postId];
							// A chunk failing partway through can still leave a partial report
							// saved, so update the row whenever row data came back — not only
							// on full success — using checked_at_formatted's presence as the
							// signal that a report (full or partial) exists to reflect.
							if (row && results[postId].checked_at_formatted !== undefined) {
								applyRunResult(row, results[postId]);
							}
						});

						if (msg) {
							msg.className = data.data.failure_count > 0 ? 'notice notice-warning is-dismissible' : 'notice notice-success is-dismissible';
							var text = data.data.success_count + ' ' + splechehInterpunctionCheck.i18n.bulkChecked;
							if (data.data.failure_count > 0) {
								text += ' ' + data.data.failure_count + ' ' + splechehInterpunctionCheck.i18n.bulkFailed;
							}
							msg.querySelector('p').textContent = text;
							msg.style.display = 'block';
						}
					})
					.catch(function () {
						applyBtn.disabled = false;
						if (msg) {
							msg.className = 'notice notice-error is-dismissible';
							msg.querySelector('p').textContent = 'Bulk interpunction check request failed. Please try again.';
							msg.style.display = 'block';
						}
					});
			});
		}

	});
}());
