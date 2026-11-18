/*
 * visual-editor.js — example.com
 *
 * Turns any <textarea class="wysiwyg-html"> into a small visual (WYSIWYG)
 * editor: a formatting toolbar above a contenteditable surface. The original
 * textarea is kept in the DOM (hidden) and holds the real HTML that gets
 * POSTed — the editable surface just writes its innerHTML back into it on
 * every change and again on submit, so the server side is unchanged.
 *
 * To give a future editor the same treatment: add class="wysiwyg-html" to its
 * textarea and make sure this script is loaded on that page (editors built on
 * templates/mod/edit_page.html get it for free).
 */
(function () {
	'use strict';

	// [command, argument, label, title]. execCommand is deprecated but is the
	// only cross-browser contenteditable API that ships everywhere with no deps,
	// which is what we want for a self-hosted panel.
	var BUTTONS = [
		['bold',               null,  '𝐁', 'Bold'],
		['italic',             null,  '𝐼', 'Italic'],
		['underline',          null,  'U̲',      'Underline'],
		['strikeThrough',      null,  'S̶',      'Strikethrough'],
		['sep'],
		['formatBlock',        'h2',  'H2',           'Heading'],
		['formatBlock',        'p',   '¶',       'Paragraph'],
		['sep'],
		['insertOrderedList',  null,  '1.',           'Numbered list'],
		['insertUnorderedList',null,  '•',       'Bulleted list'],
		['outdent',            null,  '←',       'Outdent / un-nest'],
		['indent',             null,  '→',       'Indent / nest list'],
		['sep'],
		['createLink',         null,  '🔗', 'Add link'],
		['unlink',             null,  '⛓',       'Remove link'],
		['removeFormat',       null,  '✗',       'Clear formatting']
	];

	function make(tag, cls) {
		var el = document.createElement(tag);
		if (cls) { el.className = cls; }
		return el;
	}

	function upgrade(textarea) {
		textarea.style.display = 'none';
		textarea.classList.add('wysiwyg-source');

		var wrap    = make('div', 'wysiwyg');
		var toolbar = make('div', 'wysiwyg-toolbar');
		var surface = make('div', 'wysiwyg-surface');

		surface.setAttribute('contenteditable', 'true');
		surface.setAttribute('spellcheck', 'true');
		surface.innerHTML = textarea.value;

		// Build the toolbar.
		BUTTONS.forEach(function (b) {
			if (b[0] === 'sep') {
				toolbar.appendChild(make('span', 'wysiwyg-sep'));
				return;
			}
			var cmd = b[0], arg = b[1];
			var btn = make('button', 'wysiwyg-btn');
			btn.type = 'button';          // never submit the form
			btn.innerHTML = b[2];
			btn.title = b[3];
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				surface.focus();
				if (cmd === 'createLink') {
					var url = window.prompt('Link URL:', 'https://');
					if (url) { document.execCommand('createLink', false, url); }
				} else if (cmd === 'formatBlock') {
					document.execCommand('formatBlock', false, arg);
				} else {
					document.execCommand(cmd, false, arg);
				}
				sync();
			});
			toolbar.appendChild(btn);
		});

		function sync() { textarea.value = surface.innerHTML; }

		surface.addEventListener('input', sync);
		surface.addEventListener('blur', sync);
		// Flush once more right before the form is submitted, in case the last
		// edit came from something that didn't fire 'input' (e.g. a drag).
		if (textarea.form) {
			textarea.form.addEventListener('submit', sync);
		}

		wrap.appendChild(toolbar);
		wrap.appendChild(surface);
		textarea.parentNode.insertBefore(wrap, textarea.nextSibling);
	}

	function init() {
		var fields = document.querySelectorAll('textarea.wysiwyg-html');
		for (var i = 0; i < fields.length; i++) { upgrade(fields[i]); }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
