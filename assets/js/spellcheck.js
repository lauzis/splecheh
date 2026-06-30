/* global splechehCheck */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
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
							msg.querySelector('p').textContent = data.data || 'Spell check failed.';
							msg.style.display = 'block';
						}
						return;
					}

					var result = data.data;

					// Update Last Checked cell.
					var checkedCell = row.querySelector('[data-colname="Last Checked"]') ||
						row.cells[2];
					if (checkedCell) checkedCell.textContent = result.checked_at_formatted;

					// Update Status cell.
					var statusCell = row.querySelector('[data-colname="Status"]') ||
						row.cells[3];
					if (statusCell) {
						statusCell.innerHTML = '<span class="splecheh-badge splecheh-badge--current">' +
							splechehCheck.i18n.upToDate + '</span>';
					}

					// Update Report cell.
					var reportCell = row.querySelector('[data-colname="Report"]') ||
						row.cells[4];
					if (reportCell && result.report_url) {
						reportCell.innerHTML = '<a href="' + result.report_url +
							'" target="_blank" rel="noopener">' + splechehCheck.i18n.viewReport + '</a>';
					}

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
	});
}());
