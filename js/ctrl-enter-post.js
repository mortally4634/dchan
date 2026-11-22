/*
 * ctrl-enter-post.js - example.com
 *
 * Ctrl+Enter (Cmd+Enter on a Mac) in a post form's text boxes submits the post, as if the
 * Post button were pressed: same validation, same ajax handling. Works in the normal form
 * and in quick reply.
 */
(function () {
	'use strict';
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter' || !(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey) return;
		var t = e.target;
		if (!t || !t.closest || !/^(TEXTAREA|INPUT)$/.test(t.tagName) || t.type === 'file') return;
		var form = t.closest('form[name="post"]');
		if (!form) return;
		var btn = form.querySelector('input[type="submit"]');
		if (!btn || btn.disabled) return;
		e.preventDefault();
		btn.click();
	});
})();
