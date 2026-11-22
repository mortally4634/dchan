/*
 * code-textarea.js - example.com
 *
 * Upgrades any <textarea class="code-lines"> into a code-editor-style box with
 * a line-number gutter down the left edge. It stays a plain textarea - one
 * entry per line - so the form posts exactly what was typed; the gutter is
 * cosmetic and kept in sync with the content and scroll position.
 *
 * Lines are not soft-wrapped (wrap="off"), so each logical line is one visual
 * row and the numbers always line up; long lines scroll horizontally instead.
 */
(function () {
	'use strict';

	function make(tag, cls) {
		var el = document.createElement(tag);
		if (cls) { el.className = cls; }
		return el;
	}

	function upgrade(ta) {
		ta.setAttribute('wrap', 'off');
		ta.classList.add('codeta-input');

		var wrap   = make('div', 'codeta');
		var gutter = make('div', 'codeta-gutter');

		ta.parentNode.insertBefore(wrap, ta);
		wrap.appendChild(gutter);
		wrap.appendChild(ta);

		var lastCount = -1;

		function renderNumbers() {
			var count = ta.value.split('\n').length || 1;
			if (count === lastCount) { return; }
			lastCount = count;
			var out = '';
			for (var i = 1; i <= count; i++) { out += i + '\n'; }
			gutter.textContent = out;
		}

		function syncScroll() {
			gutter.scrollTop = ta.scrollTop;
		}

		ta.addEventListener('input', function () { renderNumbers(); syncScroll(); });
		ta.addEventListener('scroll', syncScroll);
		// Font metrics can shift after web fonts load; recompute once settled.
		window.addEventListener('load', renderNumbers);

		renderNumbers();
	}

	function init() {
		var fields = document.querySelectorAll('textarea.code-lines');
		for (var i = 0; i < fields.length; i++) { upgrade(fields[i]); }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
