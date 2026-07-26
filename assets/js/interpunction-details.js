/* global splechehInterpunctionDetails */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var table = document.getElementById('splecheh-interpunction-details-table');
		if (!table) return;

		var msg = document.getElementById('splecheh-interpunction-details-message');

		function showMessage(type, text) {
			if (!msg) return;
			msg.className = 'notice notice-' + type + ' is-dismissible';
			msg.querySelector('p').textContent = text;
			msg.style.display = 'block';
		}

		function escapeHtml(text) {
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}

		function markRowResolved(row) {
			row.classList.add('splecheh-resolved');
			var actionsCell = row.querySelector('td:last-child');
			if (actionsCell) {
				actionsCell.innerHTML = '<span class="splecheh-badge splecheh-badge--current">' +
					splechehInterpunctionDetails.i18n.resolved + '</span>';
			}
			var checkbox = row.querySelector('.splecheh-row-check');
			if (checkbox) {
				checkbox.checked = false;
				checkbox.disabled = true;
			}
			var textarea = row.querySelector('.splecheh-interpunction-fixed');
			if (textarea) textarea.disabled = true;
		}

		function post(action, params) {
			var body = new FormData();
			body.append('action', action);
			body.append('nonce', splechehInterpunctionDetails.nonce);
			body.append('post_id', splechehInterpunctionDetails.postId);
			Object.keys(params).forEach(function (key) {
				body.append(key, params[key]);
			});
			return fetch(splechehInterpunctionDetails.ajaxUrl, { method: 'POST', body: body })
				.then(function (r) { return r.json(); });
		}

		function applyFix(rows) {
			var items = rows.map(function (row) {
				var textarea = row.querySelector('.splecheh-interpunction-fixed');
				return { index: row.dataset.index, fixed: textarea ? textarea.value.trim() : '' };
			}).filter(function (item) { return item.fixed !== ''; });

			if (items.length === 0) {
				showMessage('error', splechehInterpunctionDetails.i18n.fixedTextReq);
				return;
			}

			post('splecheh_interpunction_fix', { items: JSON.stringify(items) }).then(function (data) {
				if (!data.success) {
					showMessage('error', splechehInterpunctionDetails.i18n.requestFailed);
					return;
				}
				// Only rows the server confirmed against the saved post are resolved —
				// the rest stay actionable so the fix isn't silently lost.
				var unapplied = (data.data.unapplied || []).map(String);

				rows.forEach(function (row) {
					var wasSubmitted = items.some(function (item) { return item.index === row.dataset.index; });
					if (wasSubmitted && unapplied.indexOf(String(row.dataset.index)) === -1) markRowResolved(row);
				});

				// The fix bumps the post's modified date, so Spell Check is re-run
				// server-side to keep it current — and to catch a spelling error the
				// rewritten sentence may have introduced.
				var text = data.data.fixed + ' ' + splechehInterpunctionDetails.i18n.issuesFixed;
				var spellcheck = data.data.spellcheck || {};

				if (unapplied.length > 0) {
					showMessage('error', text + ' ' + unapplied.length + ' ' + splechehInterpunctionDetails.i18n.fixNotApplied);
				} else if (spellcheck.error) {
					showMessage('warning', text + ' ' + splechehInterpunctionDetails.i18n.spellcheckFailed + ' ' + spellcheck.error);
				} else if (spellcheck.ran && spellcheck.issues > 0) {
					showMessage('warning', text + ' ' + spellcheck.issues + ' ' + splechehInterpunctionDetails.i18n.spellcheckIssues);
				} else {
					showMessage('success', text);
				}
			}).catch(function () {
				showMessage('error', splechehInterpunctionDetails.i18n.requestFailed);
			});
		}

		function applyIgnore(action, rows) {
			var indices = rows.map(function (row) { return row.dataset.index; });
			post(action, { indices: JSON.stringify(indices) }).then(function (data) {
				if (!data.success) {
					showMessage('error', splechehInterpunctionDetails.i18n.requestFailed);
					return;
				}
				rows.forEach(markRowResolved);
				showMessage('success', data.data.ignored + ' ' + splechehInterpunctionDetails.i18n.issuesUpdated);
			}).catch(function () {
				showMessage('error', splechehInterpunctionDetails.i18n.requestFailed);
			});
		}

		function renderIssueRow(issue, index) {
			var checkboxAttrs = issue.resolved ? ' disabled' : '';
			var actionsCell = issue.resolved
				? '<span class="splecheh-badge splecheh-badge--current">' + splechehInterpunctionDetails.i18n.resolved + '</span>'
				: '<button class="button button-primary button-small splecheh-fix">' + splechehInterpunctionDetails.i18n.fix + '</button> ' +
					'<button class="button button-small splecheh-ignore-post">' + splechehInterpunctionDetails.i18n.ignoreInPost + '</button>';

			return '<tr data-index="' + index + '"' + (issue.resolved ? ' class="splecheh-resolved"' : '') + '>' +
				'<th class="check-column"><input type="checkbox" class="splecheh-row-check"' + checkboxAttrs + '></th>' +
				'<td>' + escapeHtml(issue.original) + '</td>' +
				'<td>' + issue.diff + '</td>' +
				'<td><textarea class="splecheh-interpunction-fixed regular-text" rows="2"' + checkboxAttrs + '>' + escapeHtml(issue.fixed) + '</textarea></td>' +
				'<td>' + escapeHtml(issue.explanation) + '</td>' +
				'<td>' + actionsCell + '</td>' +
				'</tr>';
		}

		function renderIssues(issues) {
			var tbody = table.querySelector('tbody');
			if (!tbody) return;

			if (issues.length === 0) {
				tbody.innerHTML = '<tr><td colspan="6">' + splechehInterpunctionDetails.i18n.noIssues + '</td></tr>';
				return;
			}

			tbody.innerHTML = issues.map(renderIssueRow).join('');
		}

		// Re-run interpunction check.
		var rerunBtn = document.getElementById('splecheh-interpunction-rerun-check');
		if (rerunBtn) {
			rerunBtn.addEventListener('click', function () {
				var spinner = document.getElementById('splecheh-interpunction-rerun-spinner');
				rerunBtn.disabled = true;
				rerunBtn.textContent = splechehInterpunctionDetails.i18n.rerunning;
				if (spinner) spinner.style.display = 'inline-block';

				post('splecheh_interpunction_details_rerun', {}).then(function (data) {
					rerunBtn.disabled = false;
					rerunBtn.textContent = splechehInterpunctionDetails.i18n.rerun;
					if (spinner) spinner.style.display = 'none';

					if (!data.success) {
						var errData = data.data || {};
						var text = (typeof errData === 'string' ? errData : errData.message) || splechehInterpunctionDetails.i18n.requestFailed;
						showMessage('error', text);
						return;
					}

					var issues = data.data.issues;
					renderIssues(issues);

					var unresolvedCount = issues.filter(function (issue) { return !issue.resolved; }).length;
					if (unresolvedCount === 0) {
						showMessage('success', splechehInterpunctionDetails.i18n.noIssues);
					} else {
						showMessage('warning', unresolvedCount + ' ' + splechehInterpunctionDetails.i18n.issuesFound);
					}
				}).catch(function () {
					rerunBtn.disabled = false;
					rerunBtn.textContent = splechehInterpunctionDetails.i18n.rerun;
					if (spinner) spinner.style.display = 'none';
					showMessage('error', splechehInterpunctionDetails.i18n.requestFailed);
				});
			});
		}

		// Mark Complete: resolves every remaining issue and marks the post as checked.
		var markCompleteBtn = document.getElementById('splecheh-interpunction-mark-complete');
		if (markCompleteBtn) {
			markCompleteBtn.addEventListener('click', function () {
				var spinner = document.getElementById('splecheh-interpunction-mark-complete-spinner');
				markCompleteBtn.disabled = true;
				if (spinner) spinner.style.display = 'inline-block';

				post('splecheh_interpunction_mark_complete', {}).then(function (data) {
					markCompleteBtn.disabled = false;
					if (spinner) spinner.style.display = 'none';

					if (!data.success) {
						var errData = data.data || {};
						showMessage('error', (typeof errData === 'string' ? errData : errData.message) || splechehInterpunctionDetails.i18n.requestFailed);
						return;
					}

					renderIssues(data.data.issues);
					showMessage('success', splechehInterpunctionDetails.i18n.markedComplete);
				}).catch(function () {
					markCompleteBtn.disabled = false;
					if (spinner) spinner.style.display = 'none';
					showMessage('error', splechehInterpunctionDetails.i18n.requestFailed);
				});
			});
		}

		// Row-level actions.
		table.addEventListener('click', function (e) {
			var row = e.target.closest('tr');
			if (!row) return;

			if (e.target.closest('.splecheh-fix')) {
				applyFix([row]);
			} else if (e.target.closest('.splecheh-ignore-post')) {
				applyIgnore('splecheh_interpunction_ignore_in_post', [row]);
			}
		});

		// Select all.
		var selectAll = document.getElementById('splecheh-interpunction-select-all');
		if (selectAll) {
			selectAll.addEventListener('change', function () {
				table.querySelectorAll('.splecheh-row-check:not(:disabled)').forEach(function (cb) {
					cb.checked = selectAll.checked;
				});
			});
		}

		// Bulk actions.
		var bulkApply = document.getElementById('splecheh-interpunction-bulk-apply');
		if (bulkApply) {
			bulkApply.addEventListener('click', function () {
				var action = document.getElementById('splecheh-interpunction-bulk-action').value;
				var rows = Array.prototype.filter.call(
					table.querySelectorAll('.splecheh-row-check'),
					function (cb) { return cb.checked; }
				).map(function (cb) { return cb.closest('tr'); });

				if (!action) {
					showMessage('error', splechehInterpunctionDetails.i18n.selectAction);
					return;
				}
				if (rows.length === 0) {
					showMessage('error', splechehInterpunctionDetails.i18n.selectRows);
					return;
				}

				if (action === 'fix') {
					applyFix(rows);
				} else if (action === 'ignore_in_post') {
					applyIgnore('splecheh_interpunction_ignore_in_post', rows);
				}
			});
		}
	});
}());
