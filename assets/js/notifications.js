(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.splecheh-toast .notice-dismiss').forEach(function (button) {
			button.addEventListener('click', function () {
				var notice = this.closest('.splecheh-toast');
				var id = notice.dataset.splechehId;
				var mode = notice.dataset.splechehMode;

				if (mode === 'one-time') {
					var data = new FormData();
					data.append('action', 'splecheh_dismiss_notification');
					data.append('nonce', splechehNotifications.nonce);
					data.append('notification_id', id);

					fetch(splechehNotifications.ajaxUrl, {
						method: 'POST',
						body: data,
					});
				}

				notice.remove();
			});
		});
	});
})();
