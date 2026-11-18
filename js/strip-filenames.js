/*
 * strip-filenames.js - "Strip filenames" option for the General tab.
 *
 * When enabled, hides the poster-supplied original filename (the download
 * link in .fileinfo) and the comma separator before it. Purely cosmetic and
 * fully reversible: it toggles a body class and marks the relevant nodes with
 * classes that CSS hides. No server render is affected.
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/strip-filenames.js';
 */

$(document).ready(function(){
	var KEY = 'strip_filenames';

	// Mark the original-filename link and its preceding ", " separator so CSS
	// can hide them. Runs once per file; safe to call repeatedly.
	var process = function(root){
		$(root || document).find('.fileinfo .unimportant a[download]').each(function(){
			if ($(this).data('sfn')) return;
			$(this).data('sfn', 1).addClass('sfn-link');
			var prev = this.previousSibling;
			if (prev && prev.nodeType === 3 && /,\s*$/.test(prev.nodeValue)) {
				var span = document.createElement('span');
				span.className = 'sfn-sep';
				span.textContent = prev.nodeValue;
				prev.parentNode.replaceChild(span, prev);
			}
		});
	};

	var apply = function(){
		$(document.body).toggleClass('strip-filenames', localStorage[KEY] === 'true');
	};

	process(document);
	apply();

	// Re-mark filenames on dynamically inserted posts (auto-reload, inline expand).
	$(document).on('new_post', function(e, post){ process(post); });

	if (window.Options && Options.get_tab('general')) {
		Options.extend_tab('general',
			"<label id='strip-filenames'><input type='checkbox' /> " + _('Strip filenames') + "</label>");
		var cb = $('#strip-filenames > input');
		cb.prop('checked', localStorage[KEY] === 'true');
		cb.on('change', function(){
			localStorage[KEY] = this.checked ? 'true' : 'false';
			apply();
		});
	}
});
