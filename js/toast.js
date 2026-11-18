/*
 * toast.js — example.com
 *
 * Thin wrapper over Toastify (js/toastify.js) exposing a single global:
 *
 *     toast(message, type)   // type: 'success' | 'error' | 'info' (default)
 *
 * Falls back to window.alert if Toastify failed to load, so callers never
 * break. Keep call sites using toast() instead of alert() for non-blocking,
 * styled notifications.
 */
(function () {
	'use strict';

	var COLORS = {
		success: 'linear-gradient(135deg,#2e9e5b,#1f7d45)',
		error:   'linear-gradient(135deg,#c0392b,#922b21)',
		info:    'linear-gradient(135deg,#4a63b5,#34508f)'
	};

	window.toast = function (message, type) {
		if (typeof window.Toastify !== 'function') {
			try { window.alert(message); } catch (e) {}
			return;
		}
		Toastify({
			text: message,
			duration: (type === 'error') ? 6000 : 3500,
			gravity: 'bottom',
			position: 'right',
			close: true,
			stopOnFocus: true,
			escapeMarkup: true,
			style: { background: COLORS[type] || COLORS.info }
		}).showToast();
	};
})();
