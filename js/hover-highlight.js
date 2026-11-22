/*
 * hover-highlight.js - example.com
 *
 * Hovering a ">>19" reference inside a post highlights the post it refers to, in
 * place. The post's own number in its header ("No." / "19") does NOT highlight. The click behaviour
 * (highlightReply / citeReply) is untouched - this uses its own
 * `hover-highlighted` class, styled in stylesheets/style.css.
 */
(function () {
	'use strict';

	var HL = 'hover-highlighted';
	var last = null;

	// The referenced post id, from the link's onclick (highlightReply(19),
	// citeReply(19), or a backlink's highlightReply('19', event)) or, for a
	// post-number link, the numeric hash in its href.
	function resolveId(a) {
		// the header post number never highlights anything
		if (a.classList && a.classList.contains('post_no')) return null;
		var oc = a.getAttribute('onclick') || '';
		var m = oc.match(/(?:highlightReply|citeReply)\(\s*'?(\d+)'?/);
		if (m) return m[1];
		if (a.classList && a.classList.contains('post_no')) {
			var href = a.getAttribute('href') || '';
			var hm = href.match(/#q?(\d+)\s*$/);
			if (hm) return hm[1];
		}
		return null;
	}

	function targetPost(id, a) {
		return document.getElementById('reply_' + id)
			|| document.getElementById('op_' + id)
			|| (a && a.closest ? a.closest('.post') : null); // OP's own number
	}

	function linkFrom(e) {
		return (e.target && e.target.closest) ? e.target.closest('a') : null;
	}

	document.addEventListener('mouseover', function (e) {
		var a = linkFrom(e);
		if (!a) return;
		var id = resolveId(a);
		if (!id) return;
		var post = targetPost(id, a);
		if (!post) return;
		if (last && last !== post) last.classList.remove(HL);
		post.classList.add(HL);
		last = post;
	});

	document.addEventListener('mouseout', function (e) {
		var a = linkFrom(e);
		if (!a || !resolveId(a)) return;
		if (last) { last.classList.remove(HL); last = null; }
	});
})();
