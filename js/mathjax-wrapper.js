/*
 * mathjax-wrapper.js — example.com
 *
 * MathJax auto-typesets once on load (startup.typeset = true). This wrapper
 * re-typesets when new posts arrive via ajax (quick-reply, auto-reload,
 * inline expansion) so math in freshly inserted posts renders too.
 */
(function () {
	'use strict';
	function typeset() {
		if (window.MathJax && MathJax.typesetPromise) {
			MathJax.typesetPromise().catch(function (err) { console.error(err); });
		}
	}
	if (window.jQuery) {
		jQuery(function ($) {
			$(document).on('new_post', function () { typeset(); });
		});
	}
})();
