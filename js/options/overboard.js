/*
 * options/overboard.js - "Overboard" tab for the options panel.
 *
 * Lets each user exclude boards from their view of the overboard (/o/).
 * The board list is read from the .boardlist nav (slash-wrapped links such
 * as "/doc/"); excluded URIs are stored in localStorage.overboard_exclude.
 * On the overboard page (active_page == 'ukko') excluded boards' threads and
 * their "/board/" headers are hidden client-side. Server render is untouched.
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/options.js';
 *   $config['additional_javascript'][] = 'js/options/overboard.js';
 */

+function(){
	if (!window.Options) return;

	var KEY = 'overboard_exclude';

	var getExcluded = function(){
		try { return JSON.parse(localStorage[KEY] || '[]'); } catch (e) { return []; }
	};
	var setExcluded = function(arr){
		localStorage[KEY] = JSON.stringify(arr);
	};

	var apply = function(){
		if (typeof active_page == 'undefined' || active_page != 'ukko') return;
		var ex = getExcluded();
		$('div[id^="thread_"]').each(function(){
			var header = $(this).prevAll('h2').first();
			var href = header.find('a').attr('href') || '';
			var m = href.match(/\/([^\/]+)\/$/);
			var board = m ? m[1] : null;
			var hide = board && ex.indexOf(board) >= 0;
			$(this).toggle(!hide);
			header.toggle(!hide);
		});
	};

	Options.add_tab('overboard', 'globe', _('Overboard'));
	var tab = Options.get_tab('overboard').content;

	$(document).ready(function(){
		$('<p>' + _('Boards checked below are excluded from the overboard.') + '</p>').appendTo(tab);

		// Collect board URIs from the boardlist (only "/xxx/" style links).
		var boards = [];
		$('.boardlist').first().find('a').each(function(){
			var t = $(this).text().trim();
			if (/^\/[^\/]+\/$/.test(t)) {
				var uri = t.replace(/\//g, '');
				if (boards.indexOf(uri) < 0) boards.push(uri);
			}
		});

		var excluded = getExcluded();
		boards.forEach(function(uri){
			var label = $("<label style='display:block'><input type='checkbox'> /" + uri + "/</label>");
			label.find('input')
				.val(uri)
				.prop('checked', excluded.indexOf(uri) >= 0)
				.on('change', function(){
					var ex = getExcluded();
					var i = ex.indexOf(this.value);
					if (this.checked && i < 0) ex.push(this.value);
					else if (!this.checked && i >= 0) ex.splice(i, 1);
					setExcluded(ex);
					apply();
				});
			label.appendTo(tab);
		});

		apply();
	});
}();
