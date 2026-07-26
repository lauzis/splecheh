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

		// Every action here rewrites the same two things — the post content and the
		// report JSON — by reading them, changing them and writing them back. Two in
		// flight at once means the second one saves content it read before the first
		// one's edit existed, silently dropping that fix. So one request at a time,
		// page-wide: the triggering button says what it is doing (a Fix saves the post
		// and then re-runs Spell Check, which is slow enough to look like nothing
		// happened), and every other action is disabled until it returns.
		var ACTION_BUTTONS = '#splecheh-interpunction-details-table button, #splecheh-interpunction-bulk-apply, #splecheh-interpunction-rerun-check, #splecheh-interpunction-mark-complete';

		function setBusy(button, busy, label) {
			document.querySelectorAll(ACTION_BUTTONS).forEach(function (other) {
				other.disabled = busy;
			});

			if (!button) return;

			if (busy) {
				button.dataset.splechehLabel = button.textContent;
				button.textContent = label;

				var spinner = document.createElement('span');
				spinner.className = 'spinner is-active splecheh-inline-spinner';
				spinner.style.cssText = 'float:none;margin:0 4px;vertical-align:middle;';
				button.insertAdjacentElement('afterend', spinner);
			} else {
				if (button.dataset.splechehLabel) {
					button.textContent = button.dataset.splechehLabel;
					delete button.dataset.splechehLabel;
				}

				var running = button.nextElementSibling;
				if (running && running.classList.contains('splecheh-inline-spinner')) running.remove();
			}
		}

		function applyFix(rows, trigger) {
			var items = rows.map(function (row) {
				var textarea = row.querySelector('.splecheh-interpunction-fixed');
				return { index: row.dataset.index, fixed: textarea ? textarea.value.trim() : '' };
			}).filter(function (item) { return item.fixed !== ''; });

			if (items.length === 0) {
				showMessage('error', splechehInterpunctionDetails.i18n.fixedTextReq);
				return;
			}

			setBusy(trigger, true, splechehInterpunctionDetails.i18n.fixing);

			post('splecheh_interpunction_fix', { items: JSON.stringify(items) }).then(function (data) {
				setBusy(trigger, false);
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
				setBusy(trigger, false);
				showMessage('error', splechehInterpunctionDetails.i18n.requestFailed);
			});
		}

		function applyIgnore(action, rows, trigger) {
			var indices = rows.map(function (row) { return row.dataset.index; });

			setBusy(trigger, true, splechehInterpunctionDetails.i18n.working);

			post(action, { indices: JSON.stringify(indices) }).then(function (data) {
				setBusy(trigger, false);
				if (!data.success) {
					showMessage('error', splechehInterpunctionDetails.i18n.requestFailed);
					return;
				}
				rows.forEach(markRowResolved);
				showMessage('success', data.data.ignored + ' ' + splechehInterpunctionDetails.i18n.issuesUpdated);
			}).catch(function () {
				setBusy(trigger, false);
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
				setBusy(rerunBtn, true, splechehInterpunctionDetails.i18n.rerunning);
				if (spinner) spinner.style.display = 'inline-block';

				post('splecheh_interpunction_details_rerun', {}).then(function (data) {
					setBusy(rerunBtn, false);
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
					setBusy(rerunBtn, false);
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
				setBusy(markCompleteBtn, true, splechehInterpunctionDetails.i18n.working);
				if (spinner) spinner.style.display = 'inline-block';

				post('splecheh_interpunction_mark_complete', {}).then(function (data) {
					setBusy(markCompleteBtn, false);
					if (spinner) spinner.style.display = 'none';

					if (!data.success) {
						var errData = data.data || {};
						showMessage('error', (typeof errData === 'string' ? errData : errData.message) || splechehInterpunctionDetails.i18n.requestFailed);
						return;
					}

					renderIssues(data.data.issues);
					showMessage('success', splechehInterpunctionDetails.i18n.markedComplete);
				}).catch(function () {
					setBusy(markCompleteBtn, false);
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
				applyFix([row], e.target.closest('.splecheh-fix'));
			} else if (e.target.closest('.splecheh-ignore-post')) {
				applyIgnore('splecheh_interpunction_ignore_in_post', [row], e.target.closest('.splecheh-ignore-post'));
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
					applyFix(rows, bulkApply);
				} else if (action === 'ignore_in_post') {
					applyIgnore('splecheh_interpunction_ignore_in_post', rows, bulkApply);
				}
			});
		}
	});
}());
