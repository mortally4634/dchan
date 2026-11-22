/*
 * imgboard-viewas.js  --  "previewing as a lower role" indicator.
 *
 * When a staff member is previewing the site as a rank below their own, the
 * server sets a `imgboard_viewas` cookie (the numeric group level). This shows a
 * persistent banner on every page with an Exit link, so they always know they
 * are in preview mode and can leave it from anywhere. The cookie only ever
 * lowers access server-side; this script is purely the visual indicator.
 */
(function () {
	'use strict';
	try {
		var m = document.cookie.match(/(?:^|;\s*)imgboard_viewas=(\d+|user)/);
		if (!m) { return; }
		var names = { '5': 'Mediator', '10': 'Janitor', '20': 'Mod', '30': 'Admin' };
		var role = m[1] === 'user' ? 'a regular user' : (names[m[1]] || ('level ' + m[1]));

		var bar = document.createElement('div');
		bar.id = 'imgboard-viewas-bar';
		bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:2147483647;' +
			'background:#b00;color:#fff;font:bold 13px/1.4 sans-serif;padding:7px 12px;' +
			'text-align:center;box-shadow:0 -2px 8px rgba(0,0,0,.35);';

		var label = document.createElement('span');
		label.textContent = 'Previewing the site as ' + role + ' - ';

		var exit = document.createElement('a');
		exit.href = '/mod.php?/viewas/off';
		exit.textContent = 'Exit preview';
		exit.style.cssText = 'color:#fff;text-decoration:underline;';

		bar.appendChild(label);
		bar.appendChild(exit);

		var add = function () { if (document.body) { document.body.appendChild(bar); } };
		if (document.body) { add(); } else { window.addEventListener('DOMContentLoaded', add); }
	} catch (e) { /* indicator only; never block the page */ }
})();
