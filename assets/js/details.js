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

		function applyFix(rows) {
			var items = rows.map(function (row) {
				var input = row.querySelector('.splecheh-replacement');
				return { index: row.dataset.index, replacement: input ? input.value.trim() : '' };
			}).filter(function (item) { return item.replacement !== ''; });

			if (items.length === 0) {
				showMessage('error', splechehDetails.i18n.replacementReq);
				return;
			}

			post('splecheh_fix_word', { items: JSON.stringify(items) }).then(function (data) {
				if (!data.success) {
					showMessage('error', splechehDetails.i18n.requestFailed);
					return;
				}
				rows.forEach(function (row) {
					var wasSubmitted = items.some(function (item) { return item.index === row.dataset.index; });
					if (wasSubmitted) markRowResolved(row);
				});
				showMessage('success', data.data.fixed + ' ' + splechehDetails.i18n.issuesFixed);
			}).catch(function () {
				showMessage('error', splechehDetails.i18n.requestFailed);
			});
		}

		function applyIgnore(action, rows) {
			var indices = rows.map(function (row) { return row.dataset.index; });
			post(action, { indices: JSON.stringify(indices) }).then(function (data) {
				if (!data.success) {
					showMessage('error', splechehDetails.i18n.requestFailed);
					return;
				}
				rows.forEach(markRowResolved);
				showMessage('success', data.data.ignored + ' ' + splechehDetails.i18n.issuesUpdated);
			}).catch(function () {
				showMessage('error', splechehDetails.i18n.requestFailed);
			});
		}

		function renderIssueRow(error, index) {
			var checkboxAttrs = error.resolved ? ' disabled' : '';
			var actionsCell = error.resolved
				? '<span class="splecheh-badge splecheh-badge--current">' + splechehDetails.i18n.resolved + '</span>'
				: '<button class="button button-primary button-small splecheh-fix">' + splechehDetails.i18n.fix + '</button> ' +
					'<button class="button button-small splecheh-ignore-post">' + splechehDetails.i18n.ignoreInPost + '</button> ' +
					'<button class="button button-small splecheh-ignore-always">' + splechehDetails.i18n.ignoreAlways + '</button>';

			return '<tr data-index="' + index + '"' + (error.resolved ? ' class="splecheh-resolved"' : '') + '>' +
				'<th class="check-column"><input type="checkbox" class="splecheh-row-check"' + checkboxAttrs + '></th>' +
				'<td>' + escapeHtml(error.word) + '</td>' +
				'<td>' + error.excerpt + '</td>' +
				'<td><input type="text" class="splecheh-replacement regular-text" value="' + escapeHtml(error.suggestion) + '"' + checkboxAttrs + '></td>' +
				'<td>' + actionsCell + '</td>' +
				'</tr>';
		}

		function renderIssues(errors) {
			var tbody = table.querySelector('tbody');
			if (!tbody) return;

			if (errors.length === 0) {
				tbody.innerHTML = '<tr><td colspan="5">' + splechehDetails.i18n.noIssues + '</td></tr>';
				return;
			}

			tbody.innerHTML = errors.map(renderIssueRow).join('');
		}

		// Re-run spell check.
		var rerunBtn = document.getElementById('splecheh-rerun-check');
		if (rerunBtn) {
			rerunBtn.addEventListener('click', function () {
				var spinner = document.getElementById('splecheh-rerun-spinner');
				rerunBtn.disabled = true;
				rerunBtn.textContent = splechehDetails.i18n.rerunning;
				if (spinner) spinner.style.display = 'inline-block';

				post('splecheh_details_rerun', {}).then(function (data) {
					rerunBtn.disabled = false;
					rerunBtn.textContent = splechehDetails.i18n.rerun;
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
					rerunBtn.disabled = false;
					rerunBtn.textContent = splechehDetails.i18n.rerun;
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
				markCompleteBtn.disabled = true;
				if (spinner) spinner.style.display = 'inline-block';

				post('splecheh_mark_complete', {}).then(function (data) {
					markCompleteBtn.disabled = false;
					if (spinner) spinner.style.display = 'none';

					if (!data.success) {
						var errData = data.data || {};
						showMessage('error', (typeof errData === 'string' ? errData : errData.message) || splechehDetails.i18n.requestFailed);
						return;
					}

					renderIssues(data.data.errors);
					showMessage('success', splechehDetails.i18n.markedComplete);
				}).catch(function () {
					markCompleteBtn.disabled = false;
					if (spinner) spinner.style.display = 'none';
					showMessage('error', splechehDetails.i18n.requestFailed);
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
				applyIgnore('splecheh_ignore_in_post', [row]);
			} else if (e.target.closest('.splecheh-ignore-always')) {
				applyIgnore('splecheh_ignore_always', [row]);
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
					applyFix(rows);
				} else if (action === 'ignore_in_post') {
					applyIgnore('splecheh_ignore_in_post', rows);
				} else if (action === 'ignore_always') {
					applyIgnore('splecheh_ignore_always', rows);
				}
			});
		}
	});
}());
