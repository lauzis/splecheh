/* global splechehDetails */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var table = document.getElementById('splecheh-details-table');
		if (!table) return;

		var msg = document.getElementById('splecheh-details-message');

		function showMessage(type, text, docsUrl) {
			if (!msg) return;
			msg.className = 'notice notice-' + type + ' is-dismissible';
			var p = msg.querySelector('p');
			p.textContent = text;
			if (docsUrl) {
				var link = document.createElement('a');
				link.href = docsUrl;
				link.target = '_blank';
				link.rel = 'noopener';
				link.textContent = 'Learn more';
				p.appendChild(document.createTextNode(' '));
				p.appendChild(link);
			}
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
					splechehDetails.i18n.resolved + '</span>';
			}
			var checkbox = row.querySelector('.splecheh-row-check');
			if (checkbox) {
				checkbox.checked = false;
				checkbox.disabled = true;
			}
			var input = row.querySelector('.splecheh-replacement');
			if (input) input.disabled = true;
		}

		function post(action, params) {
			var body = new FormData();
			body.append('action', action);
			body.append('nonce', splechehDetails.nonce);
			body.append('post_id', splechehDetails.postId);
			Object.keys(params).forEach(function (key) {
				body.append(key, params[key]);
			});
			return fetch(splechehDetails.ajaxUrl, { method: 'POST', body: body })
				.then(function (r) { return r.json(); });
		}

		// Every action here rewrites the same two things — the post content and the
		// report JSON — by reading them, changing them and writing them back. Two in
		// flight at once means the second one saves content it read before the first
		// one's edit existed, silently dropping that fix. So one request at a time,
		// page-wide: the triggering button says what it is doing (a Fix that saves a
		// post and can re-run a whole check is slow enough to look like nothing
		// happened), and every other action is disabled until it returns.
		var ACTION_BUTTONS = '#splecheh-details-table button, #splecheh-bulk-apply, #splecheh-rerun-check, #splecheh-mark-complete';

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

		function applyFix(rows, action, trigger) {
			var items = rows.map(function (row) {
				var input = row.querySelector('.splecheh-replacement');
				return { index: row.dataset.index, replacement: input ? input.value.trim() : '' };
			}).filter(function (item) { return item.replacement !== ''; });

			if (items.length === 0) {
				showMessage('error', splechehDetails.i18n.replacementReq);
				return;
			}

			setBusy(trigger, true, splechehDetails.i18n.fixing);

			post(action || 'splecheh_fix_word', { items: JSON.stringify(items) }).then(function (data) {
				setBusy(trigger, false);
				if (!data.success) {
					showMessage('error', splechehDetails.i18n.requestFailed);
					return;
				}
				// Only rows the server confirmed against the saved post are resolved —
				// the rest stay actionable so the fix isn't silently lost.
				var unapplied = (data.data.unapplied || []).map(String);

				rows.forEach(function (row) {
					var wasSubmitted = items.some(function (item) { return item.index === row.dataset.index; });
					if (wasSubmitted && unapplied.indexOf(String(row.dataset.index)) === -1) markRowResolved(row);
				});

				var text = data.data.fixed + ' ' + splechehDetails.i18n.issuesFixed;
				if (unapplied.length > 0) {
					showMessage('error', text + ' ' + unapplied.length + ' ' + splechehDetails.i18n.fixNotApplied);
				} else {
					showMessage('success', text);
				}
			}).catch(function () {
				setBusy(trigger, false);
				showMessage('error', splechehDetails.i18n.requestFailed);
			});
		}

		function applyIgnore(action, rows, trigger) {
			var indices = rows.map(function (row) { return row.dataset.index; });

			setBusy(trigger, true, splechehDetails.i18n.working);

			post(action, { indices: JSON.stringify(indices) }).then(function (data) {
				setBusy(trigger, false);
				if (!data.success) {
					showMessage('error', splechehDetails.i18n.requestFailed);
					return;
				}
				rows.forEach(markRowResolved);
				showMessage('success', data.data.ignored + ' ' + splechehDetails.i18n.issuesUpdated);
			}).catch(function () {
				setBusy(trigger, false);
				showMessage('error', splechehDetails.i18n.requestFailed);
			});
		}

		function renderIssueRow(error, index) {
			var checkboxAttrs = error.resolved ? ' disabled' : '';

			// Whitespace runs are per-post source noise: fixing one everywhere or
			// ignoring it for a whole language would be meaningless.
			var openActions = '<button class="button button-primary button-small splecheh-fix">' + splechehDetails.i18n.fix + '</button> ';
			if (!error.isWhitespace) {
				openActions += '<button class="button button-small splecheh-fix-everywhere">' + splechehDetails.i18n.fixEverywhere + '</button> ';
			}
			openActions += '<button class="button button-small splecheh-ignore-post">' + splechehDetails.i18n.ignoreInPost + '</button>';
			if (!error.isWhitespace) {
				openActions += ' <button class="button button-small splecheh-ignore-always">' + splechehDetails.i18n.ignoreAlways + '</button>';
			}

			var actionsCell = error.resolved
				? '<span class="splecheh-badge splecheh-badge--current">' + splechehDetails.i18n.resolved + '</span>'
				: openActions;

			return '<tr data-index="' + index + '"' + (error.resolved ? ' class="splecheh-resolved"' : '') + '>' +
				'<th class="check-column"><input type="checkbox" class="splecheh-row-check"' + checkboxAttrs + '></th>' +
				'<td>' + error.typeHtml + '</td>' +
				'<td>' + error.wordHtml + '</td>' +
				'<td>' + error.suggestionsHtml + '</td>' +
				'<td>' + error.excerpt + '</td>' +
				'<td><input type="text" class="splecheh-replacement regular-text" value="' + escapeHtml(error.suggestion) + '"' + checkboxAttrs + '></td>' +
				'<td>' + actionsCell + '</td>' +
				'</tr>';
		}

		function renderIssues(errors) {
			var tbody = table.querySelector('tbody');
			if (!tbody) return;

			if (errors.length === 0) {
				tbody.innerHTML = '<tr><td colspan="7">' + splechehDetails.i18n.noIssues + '</td></tr>';
				return;
			}

			tbody.innerHTML = errors.map(renderIssueRow).join('');
		}

		// Re-run spell check.
		var rerunBtn = document.getElementById('splecheh-rerun-check');
		if (rerunBtn) {
			rerunBtn.addEventListener('click', function () {
				var spinner = document.getElementById('splecheh-rerun-spinner');
				setBusy(rerunBtn, true, splechehDetails.i18n.rerunning);
				if (spinner) spinner.style.display = 'inline-block';

				post('splecheh_details_rerun', {}).then(function (data) {
					setBusy(rerunBtn, false);
					if (spinner) spinner.style.display = 'none';

					if (!data.success) {
						var errData = data.data || {};
						var text = (typeof errData === 'string' ? errData : errData.message) || splechehDetails.i18n.requestFailed;
						showMessage('error', text, errData.docs_url);
						return;
					}

					var errors = data.data.errors;
					renderIssues(errors);

					var unresolvedCount = errors.filter(function (error) { return !error.resolved; }).length;
					if (unresolvedCount === 0) {
						showMessage('success', splechehDetails.i18n.noIssues);
					} else {
						showMessage('warning', unresolvedCount + ' ' + splechehDetails.i18n.issuesFound);
					}
				}).catch(function () {
					setBusy(rerunBtn, false);
					if (spinner) spinner.style.display = 'none';
					showMessage('error', splechehDetails.i18n.requestFailed);
				});
			});
		}

		// Mark Complete: resolves every remaining issue and marks the post as checked.
		var markCompleteBtn = document.getElementById('splecheh-mark-complete');
		if (markCompleteBtn) {
			markCompleteBtn.addEventListener('click', function () {
				var spinner = document.getElementById('splecheh-mark-complete-spinner');
				setBusy(markCompleteBtn, true, splechehDetails.i18n.working);
				if (spinner) spinner.style.display = 'inline-block';

				post('splecheh_mark_complete', {}).then(function (data) {
					setBusy(markCompleteBtn, false);
					if (spinner) spinner.style.display = 'none';

					if (!data.success) {
						var errData = data.data || {};
						showMessage('error', (typeof errData === 'string' ? errData : errData.message) || splechehDetails.i18n.requestFailed);
						return;
					}

					renderIssues(data.data.errors);
					showMessage('success', splechehDetails.i18n.markedComplete);
				}).catch(function () {
					setBusy(markCompleteBtn, false);
					if (spinner) spinner.style.display = 'none';
					showMessage('error', splechehDetails.i18n.requestFailed);
				});
			});
		}

		// Row-level actions.
		table.addEventListener('click', function (e) {
			var row = e.target.closest('tr');
			if (!row) return;

			if (e.target.closest('.splecheh-fix-everywhere')) {
				applyFix([row], 'splecheh_fix_everywhere', e.target.closest('.splecheh-fix-everywhere'));
			} else if (e.target.closest('.splecheh-fix')) {
				applyFix([row], null, e.target.closest('.splecheh-fix'));
			} else if (e.target.closest('.splecheh-ignore-post')) {
				applyIgnore('splecheh_ignore_in_post', [row], e.target.closest('.splecheh-ignore-post'));
			} else if (e.target.closest('.splecheh-ignore-always')) {
				applyIgnore('splecheh_ignore_always', [row], e.target.closest('.splecheh-ignore-always'));
			}
		});

		// Select all.
		var selectAll = document.getElementById('splecheh-select-all');
		if (selectAll) {
			selectAll.addEventListener('change', function () {
				table.querySelectorAll('.splecheh-row-check:not(:disabled)').forEach(function (cb) {
					cb.checked = selectAll.checked;
				});
			});
		}

		// Bulk actions.
		var bulkApply = document.getElementById('splecheh-bulk-apply');
		if (bulkApply) {
			bulkApply.addEventListener('click', function () {
				var action = document.getElementById('splecheh-bulk-action').value;
				var rows = Array.prototype.filter.call(
					table.querySelectorAll('.splecheh-row-check'),
					function (cb) { return cb.checked; }
				).map(function (cb) { return cb.closest('tr'); });

				if (!action) {
					showMessage('error', splechehDetails.i18n.selectAction);
					return;
				}
				if (rows.length === 0) {
					showMessage('error', splechehDetails.i18n.selectRows);
					return;
				}

				if (action === 'fix') {
					applyFix(rows, null, bulkApply);
				} else if (action === 'fix_everywhere') {
					applyFix(rows, 'splecheh_fix_everywhere', bulkApply);
				} else if (action === 'ignore_in_post') {
					applyIgnore('splecheh_ignore_in_post', rows, bulkApply);
				} else if (action === 'ignore_always') {
					applyIgnore('splecheh_ignore_always', rows, bulkApply);
				}
			});
		}
	});
}());
