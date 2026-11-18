/*
 * imgboard-post-extras.js - example.com additions to the post dropdown menu
 *
 * Adds two things to the post menu (js/post-menu.js), after post-filter.js's
 * own items:
 *   - "Archive post" submenu: Ghostarchive / megalodon.jp / archive.today
 *   - "Hide board" (overboard / ukko only), moved to the top of the menu so it
 *     sits above "Hide post". Hidden boards persist in localStorage.
 *
 * Load order (additional_javascript): post-menu.js, post-filter.js, then this.
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/post-menu.js';
 *   $config['additional_javascript'][] = 'js/post-filter.js';
 *   $config['additional_javascript'][] = 'js/imgboard-post-extras.js';
 */
(function () {
	if (typeof active_page === 'undefined') return;
	if (!(active_page === 'thread' || active_page === 'index' ||
	      active_page === 'catalog' || active_page === 'ukko')) return;

	var onOverboard = (active_page === 'ukko');

	/* ---- Archive services. Change a base URL here if a provider moves. ---- */
	var ARCHIVERS = [
		{ id: 'imgboard-archive-ghostarchive', label: 'Ghostarchive', url: 'https://ghostarchive.org/archive?url=' },
		{ id: 'imgboard-archive-megalodon',    label: 'megalodon.jp', url: 'https://megalodon.jp/pc/get_simple/decide?url=' },
		{ id: 'imgboard-archive-today',        label: 'archive.today', url: 'https://archive.today/?url=' }
	];

	/* ---- Hidden-boards store (overboard) ---- */
	function getHiddenBoards() {
		try { return JSON.parse(localStorage.getItem('imgboardHiddenBoards') || '[]'); }
		catch (e) { return []; }
	}
	function setHiddenBoards(list) {
		try { localStorage.setItem('imgboardHiddenBoards', JSON.stringify(list)); }
		catch (e) {}
	}
	function isBoardHidden(board) {
		return getHiddenBoards().indexOf(String(board)) !== -1;
	}
	function applyHiddenBoards() {
		var list = getHiddenBoards();
		$('.thread').each(function () {
			var $t = $(this);
			var b = $t.data('board');
			if (b == null) return;
			$t.toggle(list.indexOf(String(b)) === -1);
		});
	}
	function toggleBoard(board) {
		board = String(board);
		var list = getHiddenBoards();
		var idx = list.indexOf(board);
		if (idx === -1) list.push(board); else list.splice(idx, 1);
		setHiddenBoards(list);
		applyHiddenBoards();
	}

	$(document).on('menu_ready', function () {
		var Menu = window.Menu;
		if (typeof Menu === 'undefined') return;

		if (onOverboard) {
			Menu.add_item('imgboard-hide-board', 'Hide board');
		}

		var archive = Menu.add_submenu('imgboard-archive', 'Archive post');
		for (var i = 0; i < ARCHIVERS.length; i++) {
			archive.add_item(ARCHIVERS[i].id, ARCHIVERS[i].label);
		}

		Menu.onclick(function (e, $buffer) {
			var ele = e.target.parentElement.parentElement;
			var $ele = $(ele);
			var $parent = $ele.parent();
			var threadId = ($parent.attr('id') || '').replace('thread_', '');
			var boardId = $parent.data('board') || $ele.data('board');
			var postId = $ele.find('.post_no').not('[id]').text();

			/* Permalink to the post */
			var postUrl;
			if (boardId && threadId) {
				postUrl = window.location.origin + '/' + boardId + '/res/' + threadId + '.html#' + postId;
			} else {
				postUrl = window.location.href.split('#')[0] + (postId ? ('#' + postId) : '');
			}
			var enc = encodeURIComponent(postUrl);

			for (var i = 0; i < ARCHIVERS.length; i++) {
				(function (svc) {
					$buffer.find('#' + svc.id).click(function () {
						window.open(svc.url + enc, '_blank', 'noopener');
					});
				})(ARCHIVERS[i]);
			}

			/* Hide board - overboard only, moved above "Hide post" */
			if (onOverboard && boardId) {
				var $hb = $buffer.find('#imgboard-hide-board');
				if ($hb.length) {
					$hb.prependTo($buffer.children('ul'));
					$hb.text(isBoardHidden(boardId) ? ('Unhide /' + boardId + '/') : ('Hide /' + boardId + '/'));
					$hb.off('click').click(function () {
						toggleBoard(boardId);
						$('.post-menu').remove();
						$('.post-btn-open').removeClass('post-btn-open');
					});
				}
			}
		});

		if (onOverboard) applyHiddenBoards();
	});
})();
