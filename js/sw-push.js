/*
 * DTEG / imgboard staff notifications — service worker (Web Push).
 * Receives push messages (sent by fp/notify endpoints when something enters the approval
 * queue) and shows an OS notification even when no tab is open. Push delivery does not
 * depend on the SW's scope, so this lives under /js/ to avoid needing a root file.
 */
self.addEventListener('push', function (e) {
	var d = {};
	try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
	var title = d.title || 'Staff notification';
	e.waitUntil(self.registration.showNotification(title, {
		body: d.body || '',
		tag: d.tag || 'imgboard-approval',
		renotify: true,
		data: { url: d.url || '/mod.php?/pending' }
	}));
});

self.addEventListener('notificationclick', function (e) {
	e.notification.close();
	var url = (e.notification.data && e.notification.data.url) || '/mod.php?/pending';
	e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (wins) {
		for (var i = 0; i < wins.length; i++) {
			if (wins[i].url.indexOf(url.replace(/^https?:\/\/[^/]+/, '')) > -1 && 'focus' in wins[i]) { return wins[i].focus(); }
		}
		if (clients.openWindow) { return clients.openWindow(url); }
	}));
});
