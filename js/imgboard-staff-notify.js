/*
 * DTEG / imgboard staff notifications (client).
 * Notifies staff when something enters a queue: approvals (files/links/held posts),
 * reports, mod requests and ban appeals. Staff opt in with the checkbox on ?/pending.
 *   checked : toast + (if permitted) OS notification + Web Push (offline, when VAPID keys
 *             are configured server-side and the browser supports it).
 *   unchecked: no active notifications - only the passive "(N)" title-bar badge.
 * Loads on every page but does nothing unless the readable `imgboard_staff` cookie is set
 * (stamped at login), so ordinary visitors never poll. The setting is per-staff, in
 * localStorage, so once ticked on ?/pending it applies on every tab.
 */
(function () {
	'use strict';
	if (window.__imgboardNotify) { return; }
	window.__imgboardNotify = true;

	// Run on any mod.php page (those are staff-only, so no cookie needed there), and on
	// other pages only when the readable staff cookie is present (set at login) so ordinary
	// visitors never poll. Gating the mod pages on the cookie would miss sessions that
	// logged in before the cookie existed.
	var onModPage = /\/mod\.php(?:[/?]|$)/.test(location.href);
	var staffCookie = /(?:^|;\s*)imgboard_staff=1(?:;|$)/.test(document.cookie);
	if (!onModPage && !staffCookie) { return; }

	var POLL_URL = '/mod.php?/notify-poll';
	var SUB_URL = '/mod.php?/notify-subscribe';
	var UNSUB_URL = '/mod.php?/notify-unsubscribe';
	var SW_URL = '/js/sw-push.js';
	var INTERVAL = 30000;
	var baseTitle = document.title;
	var lastPending = parseInt(sessionStorage.getItem('imgboard_notify_last') || '-1', 10);
	var vapidPublic = '';

	function notifyOn() { try { return localStorage.getItem('imgboard_notify_on') === '1'; } catch (e) { return false; } }
	function setNotifyOn(on) { try { localStorage.setItem('imgboard_notify_on', on ? '1' : '0'); } catch (e) {} }

	function toast(msg) {
		if (window.Toastify) {
			try { Toastify({ text: msg, duration: 6000, gravity: 'top', position: 'right', close: true }).showToast(); return; } catch (e) {}
		}
		try { console.log('[notify] ' + msg); } catch (e) {}
	}

	function osNotify(msg) {
		try {
			if (window.Notification && Notification.permission === 'granted') {
				var n = new Notification('Queue', { body: msg, tag: 'imgboard-queue', renotify: true });
				n.onclick = function () { window.focus(); location.href = '/mod.php?/pending'; n.close(); };
			}
		} catch (e) {}
	}

	function setBadge(n) { document.title = (n > 0 ? '(' + n + ') ' : '') + baseTitle; }

	function onCount(pending) {
		setBadge(pending); // passive badge is always on
		if (lastPending >= 0 && pending > lastPending && notifyOn()) {
			var msg = pending + ' item(s) waiting in the queue.';
			toast(msg);
			osNotify(msg);
		}
		lastPending = pending;
		try { sessionStorage.setItem('imgboard_notify_last', String(pending)); } catch (e) {}
	}

	/* ---- Web Push (offline) ---- */
	function urlB64ToUint8(base64) {
		var pad = '='.repeat((4 - base64.length % 4) % 4);
		var b64 = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
		var raw = atob(b64), arr = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) { arr[i] = raw.charCodeAt(i); }
		return arr;
	}

	function enableOffline() {
		if (!('serviceWorker' in navigator) || !('PushManager' in window) || !vapidPublic) { return; }
		navigator.serviceWorker.register(SW_URL).then(function (reg) {
			return reg.pushManager.getSubscription().then(function (sub) {
				if (sub) { return sub; }
				return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(vapidPublic) });
			});
		}).then(function (sub) {
			return fetch(SUB_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub) });
		})['catch'](function () {});
	}

	function disableOffline() {
		if (!('serviceWorker' in navigator)) { return; }
		navigator.serviceWorker.getRegistration(SW_URL).then(function (reg) {
			if (!reg) { return; }
			reg.pushManager.getSubscription().then(function (sub) {
				if (!sub) { return; }
				fetch(UNSUB_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub.endpoint }) });
				sub.unsubscribe();
			});
		});
	}

	function requestPerm() {
		try {
			if (window.Notification && Notification.permission === 'default') {
				var r = Notification.requestPermission(function () {}); // legacy callback form
				if (r && r.then) { r['catch'](function () {}); }           // + modern promise form
			}
		} catch (e) {}
	}

	function applyOn(on, interactive) {
		setNotifyOn(on);
		if (!on) { disableOffline(); return; }
		if (interactive) { requestPerm(); } // must run in the checkbox's user-gesture
		enableOffline();
	}

	/* ---- the checkbox on ?/pending ---- */
	function bindCheckbox() {
		var cb = document.getElementById('imgboard-notify-checkbox');
		if (!cb) { return; }
		cb.checked = notifyOn();
		cb.addEventListener('change', function () { applyOn(cb.checked, true); });
	}

	/* ---- poll loop ---- */
	function poll() {
		fetch(POLL_URL, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (d) {
				if (!d || !d.staff) { return; } // not staff / session gone: stop quietly
				if (d.vapid) { vapidPublic = d.vapid; }
				onCount(d.pending | 0);
				setTimeout(poll, INTERVAL);
			})['catch'](function () { setTimeout(poll, INTERVAL * 2); });
	}

	function start() {
		bindCheckbox();
		if (notifyOn()) { applyOn(true, false); } // refresh the push subscription, no prompt
		poll();
	}

	if (document.body) { start(); }
	else { window.addEventListener('DOMContentLoaded', start); }
})();
