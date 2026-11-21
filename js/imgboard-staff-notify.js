/*
 * DTEG / imgboard staff notifications (client).
 * Notifies staff when posts / images / links enter the approval queue.
 *   - "online"  : while any tab is open — polls ?/notify-poll and shows a toast + (if
 *                 permitted) an OS notification + a title-bar badge.
 *   - "offline" : the above PLUS a Web Push subscription, so notifications arrive with the
 *                 browser closed (needs VAPID keys configured server-side + HTTPS).
 *   - "off"     : nothing.
 * The mode is per-staff, stored in localStorage, picked from a small bell widget.
 * Loads on every page but does nothing unless the readable `imgboard_staff` cookie is set
 * (stamped at login), so ordinary visitors never poll.
 */
(function () {
	'use strict';
	if (window.__imgboardNotify) { return; }
	window.__imgboardNotify = true;

	if (!/(?:^|;\s*)imgboard_staff=1(?:;|$)/.test(document.cookie)) { return; } // staff only

	var POLL_URL = '/mod.php?/notify-poll';
	var SUB_URL = '/mod.php?/notify-subscribe';
	var UNSUB_URL = '/mod.php?/notify-unsubscribe';
	var SW_URL = '/js/sw-push.js';
	var INTERVAL = 30000;
	var baseTitle = document.title;
	var lastPending = parseInt(sessionStorage.getItem('imgboard_notify_last') || '-1', 10);
	var vapidPublic = '';

	function mode() { try { return localStorage.getItem('imgboard_notify_mode') || 'online'; } catch (e) { return 'online'; } }
	function setMode(m) { try { localStorage.setItem('imgboard_notify_mode', m); } catch (e) {} }

	function toast(msg) {
		if (window.Toastify) {
			try { Toastify({ text: msg, duration: 6000, gravity: 'top', position: 'right', close: true }).showToast(); return; } catch (e) {}
		}
		try { console.log('[notify] ' + msg); } catch (e) {}
	}

	function osNotify(msg) {
		try {
			if (window.Notification && Notification.permission === 'granted') {
				var n = new Notification('Approval queue', { body: msg, tag: 'imgboard-approval', renotify: true });
				n.onclick = function () { window.focus(); location.href = '/mod.php?/pending'; n.close(); };
			}
		} catch (e) {}
	}

	function setBadge(n) {
		document.title = (n > 0 ? '(' + n + ') ' : '') + baseTitle;
	}

	function onCount(pending) {
		setBadge(pending);
		if (lastPending >= 0 && pending > lastPending && mode() !== 'off') {
			var msg = pending + ' post(s) awaiting approval.';
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
		if (!('serviceWorker' in navigator) || !('PushManager' in window) || !vapidPublic) {
			toast('Offline push is not available (no HTTPS / service worker / server keys).');
			return;
		}
		navigator.serviceWorker.register(SW_URL).then(function (reg) {
			return reg.pushManager.getSubscription().then(function (sub) {
				if (sub) { return sub; }
				return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(vapidPublic) });
			});
		}).then(function (sub) {
			return fetch(SUB_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub) });
		}).then(function () { toast('Offline notifications enabled.'); })
			['catch'](function () { toast('Could not enable offline push.'); });
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

	function applyMode(m, interactive) {
		setMode(m);
		if (m === 'off') { disableOffline(); return; }
		if (interactive && window.Notification && Notification.permission === 'default') {
			Notification.requestPermission();
		}
		if (m === 'offline') { enableOffline(); } else { disableOffline(); }
	}

	/* ---- settings widget ---- */
	function widget() {
		var wrap = document.createElement('div');
		wrap.style.cssText = 'position:fixed;right:10px;bottom:10px;z-index:9999;font-size:12px;';
		var btn = document.createElement('button');
		btn.type = 'button'; btn.textContent = '🔔';
		btn.title = 'Staff notifications';
		btn.style.cssText = 'cursor:pointer;border:1px solid #9988ee;background:#f3f1ff;border-radius:50%;width:34px;height:34px;font-size:16px;';
		var menu = document.createElement('div');
		menu.style.cssText = 'display:none;position:absolute;right:0;bottom:40px;background:#fff;color:#222;border:1px solid #9988ee;border-radius:6px;padding:8px 10px;box-shadow:0 2px 8px rgba(0,0,0,.2);white-space:nowrap;';
		var opts = [['off', 'Off'], ['online', 'Online only (tab open)'], ['offline', 'Online + offline (push)']];
		var cur = mode();
		menu.innerHTML = '<strong>Notifications</strong><br>';
		opts.forEach(function (o) {
			var id = 'imgn_' + o[0];
			menu.innerHTML += '<label style="display:block;margin:3px 0;cursor:pointer;"><input type="radio" name="imgn" value="' + o[0] + '"' + (cur === o[0] ? ' checked' : '') + '> ' + o[1] + '</label>';
		});
		btn.onclick = function () { menu.style.display = menu.style.display === 'none' ? 'block' : 'none'; };
		menu.addEventListener('change', function (e) {
			if (e.target && e.target.name === 'imgn') { applyMode(e.target.value, true); }
		});
		wrap.appendChild(menu); wrap.appendChild(btn);
		document.body.appendChild(wrap);
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
		widget();
		// Re-assert the chosen mode (keeps the push subscription fresh) without prompting.
		if (mode() === 'offline') { applyMode('offline', false); }
		poll();
	}

	if (document.body) { start(); }
	else { window.addEventListener('DOMContentLoaded', start); }
})();
