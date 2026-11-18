/*
 * post-options.js — example.com
 *
 * A "Select" row at the top of the post form's upload area, styled like 4chan's
 * selector: one tab per upload method, slash-separated —
 *     Select   File / Embed / Tegaki / Poll
 * Picking a tab shows that method and hides the others; File (the dropzone
 * uploader) is the default. Oekaki opens the Tegaki applet, whose drawing lands
 * in the File dropzone. Replaces the former standalone poll/tegaki toggles.
 */
(function () {
	'use strict';
	if (!window.jQuery) return;

	jQuery(function ($) {
		if (document.getElementById('post_options_row')) return;
		if (!document.getElementById('upload')) return;

		var t = (typeof window._ === 'function') ? window._ : function (s) { return s; };

		var has_embed  = $('#upload_embed').length > 0;
		var has_oekaki = (typeof window.tegaki_integration !== 'undefined');
		var has_poll   = $('#upload_poll').length > 0 && (typeof window.poll_integration !== 'undefined');

		// With nothing but a file upload there is nothing to select between.
		if (!has_embed && !has_oekaki && !has_poll) return;

		function hideAll() {
			$('#upload, #upload_embed, #upload_poll').hide();
		}
		function showFile() {
			hideAll();
			$('#upload').show();
		}
		function showEmbed() {
			hideAll();
			$('#upload_embed').show();
		}
		function showPoll() {
			hideAll();
			$('#upload_poll td').empty();           // rebuild a fresh poll widget
			$('#upload_poll').show();
			if (window.poll_integration) window.poll_integration.show();
		}
		function showOekaki() {
			// Oekaki draws into the File dropzone, so keep File selected and open
			// the Tegaki applet over it.
			showFile();
			if (window.tegaki_integration) window.tegaki_integration.show();
		}

		var row  = $('<tr id="post_options_row"><th>' + t('Select') + '</th><td id="upload_selection"></td></tr>');
		var cell = row.find('#upload_selection');

		function addTab(label, handler) {
			if (cell.children('a').length) {
				cell.append(document.createTextNode(' / '));
			}
			$('<a href="javascript:void(0)" class="post-option"></a>')
				.text(label)
				.on('click', handler)
				.appendTo(cell);
		}

		addTab(t('File'), showFile);
		if (has_embed)  addTab(t('Embed'),  showEmbed);
		if (has_oekaki) addTab(t('Tegaki'), showOekaki);
		if (has_poll)   addTab(t('Poll'),   showPoll);

		row.insertBefore('#upload');

		showFile(); // default to the file uploader

		// After a successful post, reset the selector back to File.
		$(document).on('ajax_after_post', function () { showFile(); });
	});
})();
