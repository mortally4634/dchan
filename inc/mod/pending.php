<?php

/*
 *  example.com custom moderation handlers
 *  ---------------------------------------
 *  Routed from inc/instance-config.php via $config['mod']['custom_pages']:
 *    /pending                      -> pending_all      (Files + links queue)
 *    /(\%b)/approvefile/(\d+)      -> approve_file     (CSRF-secured)
 *    /pending-links                -> redirect to /pending (legacy path)
 *    /(\%b)/approvelink/(\d+)      -> approve_link     (CSRF-secured)
 *    /blocked-(words|links)        -> blocklist        (view + add/suggest)
 *    /blocked-(words|links)/approve/(\d+) -> blocklist_approve (CSRF-secured)
 *    /blocked-(words|links)/delete/(\d+)  -> blocklist_delete  (CSRF-secured)
 *
 *  Permissions (from instance-config):
 *    approve_files / approve_links : Mods + Admins (MOD, 20)
 *    edit_blocklists               : Mods + Admins (MOD, 20) — add/approve/delete
 *    suggest_blocklists            : Janitors (JANITOR, 10) — suggest only
 */

use Vichan\Context;

defined('TINYBOARD') or exit;

/* ----------------------------------------------------------------------- *
 *  Config registration
 *
 *  Registered here (not only in instance-config.php) because vichan copies
 *  instance-config.php into the instance once and never updates it, while
 *  this file is symlinked and always current. instance-config.php require()s
 *  this file during config load, so these take effect on every instance.
 * ----------------------------------------------------------------------- */
global $config;

// Pin Yotsuba B as the default stylesheet for boards and the mod panel.
// (It is vichan's default already; pinning keeps it explicit and drift-proof.)
$config['default_stylesheet'] = [ 'Yotsuba B', '' ];

// Top bar "future boards": planned board URIs shown (unlinked) next to the
// current boards. Add URIs here as you announce upcoming boards, e.g. ['soy','g'].
if (!isset($config['future_boards'])) {
	$config['future_boards'] = [];
}

// Keep IPs un-hashed (no ipcrypt cloaking) so mods see the real address.
$config['ipcrypt_key'] = '';

// Always recompile Twig templates when their source changes. Without this,
// php-fpm can keep serving a stale compiled template after a template edit
// (the compiled cache is loaded without a freshness check). The per-render
// stat() cost is negligible for this instance.
$config['twig_auto_reload'] = true;

// The file-pending placeholder + thumb size live in inc/instance-config.php:
// pending.php is require_once'd and does NOT re-run on the per-board loadConfig,
// so values set here wouldn't reach a board's own render of a pending file.

// MPA (Media Pending Approval) + LPA (Link Pending Approval): the two halves of
// the /pending queue. Open to Mediator (5) and up so that role can work the
// queues. NOTE: because hasPermission is a linear threshold, lowering these to 5
// also grants them to Janitors (10) — an unavoidable consequence of the model.
$config['mod']['approve_files']      = 5;   // MPA — Mediator + up
$config['mod']['approve_links']      = 5;   // LPA — Mediator + up
// Mediators may read the noticeboard (posting stays Mod+, deleting Admin+).
$config['mod']['noticeboard']        = 5;
$config['mod']['edit_blocklists']    = 20;  // Mods + Admins
$config['mod']['suggest_blocklists'] = 10;  // Janitors

$config['mod']['custom_pages']['/pending']                             = 'pending_all';
$config['mod']['custom_pages']['/(\%b)/approvefile/(\d+)']             = 'secure approve_file';
$config['mod']['custom_pages']['/(\%b)/rejectfile/(\d+)']              = 'secure reject_file';
$config['mod']['custom_pages']['/(\%b)/rejectfilehb/(\d+)']            = 'secure reject_file_hashban';
$config['mod']['custom_pages']['/pending-links']                       = 'pending_links_redirect';
$config['mod']['custom_pages']['/(\%b)/approvelink/(\d+)']             = 'secure approve_link';
$config['mod']['custom_pages']['/(\%b)/rejectlink/(\d+)']              = 'secure reject_link';
$config['mod']['custom_pages']['/(\%b)/rejectlinkban/(\d+)']           = 'secure reject_link_ban';
$config['mod']['custom_pages']['/blocked-(words|links)']               = 'secure_POST blocklist';
$config['mod']['custom_pages']['/blocked-(words|links)/approve/(\d+)'] = 'secure blocklist_approve';
$config['mod']['custom_pages']['/blocked-(words|links)/delete/(\d+)']  = 'secure blocklist_delete';
$config['mod']['custom_pages']['/upgrade-db']                         = 'upgrade_db';
$config['mod']['upgrade_db'] = 30;  // Admins only

// Imgboard: Mediator moderation-by-proposal.
//   Mediators PROPOSE a post deletion / ban (GET = form, POST = file request);
//   Janitors+ review the queue and approve/deny. Perms (vote_delete / vote_ban /
//   approve_mod_requests) are defined in inc/config.php so they reach per-board
//   configs (this file is require_once'd only on the first, global config load).
$config['mod']['custom_pages']['/(\%b)/votedelete/(\d+)']   = 'secure_POST votedelete';
$config['mod']['custom_pages']['/(\%b)/voteban/(\d+)']      = 'secure_POST voteban';
$config['mod']['custom_pages']['/(\%b)/noteip/(\d+)']       = 'secure noteip';
$config['mod']['custom_pages']['/mod-requests']             = 'modreq_queue';
$config['mod']['custom_pages']['/mod-requests/approve/(\d+)'] = 'secure modreq_approve';
$config['mod']['custom_pages']['/mod-requests/deny/(\d+)']    = 'secure modreq_deny';

// (Thread URLs /board/thread/… and the live auto-update JS are set in
//  inc/config.php, which re-runs on every loadConfig — see the note there.)

// Admin-editable site pages (rules / news), edited from the homepage/pages.
$config['mod']['edit_pages_content'] = 30;  // Admins only
// Steganography check: read-only, Mediator and up (inc/mod/steg.php).
$config['mod']['steg'] = 5;
$config['mod']['custom_pages']['/steg/(\%b)/([0-9A-Za-z][0-9A-Za-z._-]*)'] = 'steg';
require_once __DIR__ . '/steg.php';
$config['mod']['custom_pages']['/edit-rules'] = 'secure_POST edit_rules';
$config['mod']['custom_pages']['/edit-news']  = 'secure_POST edit_news';
$config['mod']['custom_pages']['/edit-tos']     = 'secure_POST edit_tos';
$config['mod']['custom_pages']['/edit-privacy'] = 'secure_POST edit_privacy';
$config['mod']['custom_pages']['/edit-abuse']   = 'secure_POST edit_abuse';
// Mod-context views of the public pages (with the mod bar + Edit link). Bans
// already have ?/bans. Any logged-in mod may view; editing stays admin-gated.
$config['mod']['custom_pages']['/rules'] = 'view_rules';
$config['mod']['custom_pages']['/news']  = 'view_news';

// Per-board name settings (default anon name + whether the name field shows).
$config['mod']['edit_board_names'] = 30;  // Admins only
$config['mod']['custom_pages']['/board-names'] = 'secure_POST board_names';
$config['mod']['custom_pages']['/nameroll']     = 'secure_POST nameroll';  // same permission as board names

// Site killswitch: JSID (permanent, Owner only) and Temp-JSID (temporary, any
// admin). GET renders the control screen; POST (CSRF-checked) toggles it.
$config['mod']['killswitch_temp'] = 30;  // Temp-JSID: any Admin
$config['mod']['custom_pages']['/jsid']      = 'secure_POST jsid';
$config['mod']['custom_pages']['/temp-jsid'] = 'secure_POST temp_jsid';

// Admin-editable moderation log (edit / delete / add entries).
$config['mod']['edit_modlog'] = 30;  // Admins only
$config['mod']['custom_pages']['/modlog-edit'] = 'secure_POST modlog_edit';

// Drag-to-reorder top bar editor (reorder/hide nav links + boards). The
// dashboard link lives in the Administration section (mod/dashboard.html),
// gated by edit_topbar so it only shows for roles that may use it.
$config['mod']['edit_topbar'] = 30;  // Admins only
$config['mod']['custom_pages']['/topbar'] = 'secure_POST topbar_edit';

// Overboard in mod mode: ?/o renders the ukko overboard with per-post
// moderation controls (delete/ban/etc.), like a board index mod view. Note: no
// trailing slash — ?/o/ would be caught by the built-in /(board)/ route first
// (which runs before custom pages) and error with "Invalid board!".
$config['mod']['custom_pages']['/o'] = 'overboard';

// Schizo: nuclear reset. Deletes every board except /meta/ and fires all mods
// (deletes every mod account except admin). Owner only, exactly like JSID. GET
// renders a typed-confirmation screen; POST (CSRF-checked) performs the reset.
$config['mod']['custom_pages']['/schizo'] = 'secure_POST schizo';

// CattyWipe: per-board wipe. Deletes every thread on a board except stickied
// ones (keeping sticky OPs and their replies). Admin only. GET renders a
// confirm screen; POST (CSRF-checked) performs the wipe.
$config['mod']['custom_pages']['/(\%b)/cattywipe'] = 'secure_POST cattywipe';

// NOTE: the [B&D+]/[B&D++] control labels live in inc/config.php, not here.
// pending.php is require_once'd, so its config lines run only for the first
// (global) config load; loadConfig rebuilds a fresh config per board and
// re-runs inc/config.php but not this file, so per-board configs would miss
// any label defined here (the control would render with empty text).

// Don't jail the mod cookie to a path, so the homepage and rules/news pages
// can detect a logged-in admin (to show the "Edit" link).
$config['cookies']['jail'] = false;

/* ----------------------------------------------------------------------- *
 *  Shared helpers
 * ----------------------------------------------------------------------- */

// Boards the current mod may act on.
function imgboard_mod_boards(): array {
	global $mod;
	$out = [];
	foreach (listBoards() as $b) {
		if ($mod['boards'][0] === '*' || in_array($b['uri'], $mod['boards'])) {
			$out[] = $b;
		}
	}
	return $out;
}

// Pull the external links out of a post body for the staff link preview and the
// "Reject + ban link" action. A post is flagged pending-link when its rendered
// body contains an external anchor (rel="nofollow"), so prefer those hrefs — the
// exact links vichan recognised — and fall back to bare http(s):// URLs in text.
function imgboard_extract_links(string $text): array {
	$out = [];
	// External hyperlinks in the rendered markup: <a ... rel="nofollow" ... href="URL">
	// (href and rel in either order).
	if (preg_match_all('~<a\b(?=[^>]*\brel="nofollow")[^>]*\bhref="([^"]+)"~i', $text, $m)) {
		foreach ($m[1] as $u) {
			$out[] = html_entity_decode($u, ENT_QUOTES);
		}
	}
	// Bare http(s):// URLs (covers body_nomarkup / anything not anchored).
	if (preg_match_all('~https?://[^\s<>"\'\]]+~i', $text, $m2)) {
		foreach ($m2[0] as $u) {
			$out[] = html_entity_decode($u, ENT_QUOTES);
		}
	}
	return array_values(array_unique(array_filter($out)));
}

// Build a pending queue across boards for a given WHERE clause + approve action.
// $kind is 'file' or 'link' and selects the staff preview + reject actions.
function imgboard_pending_queue(string $where, string $approve_action, string $kind): array {
	global $mod, $config;
	$reject_action       = $kind === 'link' ? 'rejectlink'    : 'rejectfile';
	$reject_extra_action = $kind === 'link' ? 'rejectlinkban'  : 'rejectfilehb';
	$pending = [];
	foreach (imgboard_mod_boards() as $b) {
		openBoard($b['uri']);
		$query = query(sprintf("SELECT * FROM ``posts_%s`` WHERE $where ORDER BY `time` DESC", $b['uri']))
			or error(db_error());
		while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
			$post['board'] = $b['uri'];
			// Build the preview with the real file/links shown (not the pending
			// placeholder): staff are reviewing exactly what they will approve.
			$build_row = $post;
			$build_row['approved']      = 1;
			$build_row['approved_link'] = 1;
			$po = $build_row['thread'] ? new Post($build_row, '?/', $mod) : new Thread($build_row, '?/', $mod, false);
			$post['built'] = $po->build(true);

			$post['approve_action'] = $approve_action;
			$post['approve_token']  = make_secure_link_token($b['uri'] . '/' . $approve_action . '/' . $post['id']);
			$post['reject_action']  = $reject_action;
			$post['reject_token']   = make_secure_link_token($b['uri'] . '/' . $reject_action . '/' . $post['id']);
			$post['reject_extra_action'] = $reject_extra_action;
			$post['reject_extra_token']  = make_secure_link_token($b['uri'] . '/' . $reject_extra_action . '/' . $post['id']);

			// Staff-only preview of the real content behind the placeholder.
			if ($kind === 'file') {
				$post['previews'] = [];
				$files = @json_decode($post['files']);
				if (is_array($files)) {
					foreach ($files as $f) {
						if (!isset($f->thumb) || $f->thumb === 'file' || (isset($f->file) && $f->file === 'deleted')) {
							continue;
						}
						$post['previews'][] = [
							'thumb' => $config['uri_thumb'] . $f->thumb,
							'src'   => $config['uri_img'] . $f->file,
							'name'  => isset($f->name) ? $f->name : '',
						];
					}
				}
			} else {
				$post['preview_links'] = imgboard_extract_links($post['body'] ?? $post['body_nomarkup'] ?? '');
			}

			$pending[] = $post;
		}
	}
	return $pending;
}

// Approve one post by flipping a column, then rebuild.
function imgboard_approve($config, $board, $post, string $column, string $logmsg, string $redirect) {
	if (!openBoard($board))
		error($config['error']['noboard']);
	$post = (int)$post;

	$q = prepare(sprintf("SELECT `thread` FROM ``posts_%s`` WHERE `id` = :id", $board));
	$q->bindValue(':id', $post, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	$row = $q->fetch(PDO::FETCH_ASSOC);
	if ($row === false)
		error($config['error']['invalidpost']);

	$q = prepare(sprintf("UPDATE ``posts_%s`` SET `$column` = 1 WHERE `id` = :id", $board));
	$q->bindValue(':id', $post, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));

	buildThread($row['thread'] ? $row['thread'] : $post);
	buildIndex();
	modLog($logmsg, $board);
	header('Location: ' . $redirect, true, $config['redirect_http']);
}

/* ----------------------------------------------------------------------- *
 *  Pending files
 * ----------------------------------------------------------------------- */

function mod_pending_files(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');
	if (!hasPermission($config['mod']['approve_files']))
		error($config['error']['noaccess']);

	echo mod_page('Pending files', 'mod/pending.html', [
		'pending'   => imgboard_pending_queue('`approved` = 0 AND `num_files` > 0', 'approvefile', 'file'),
		'pending_kind' => 'file',
	], $mod);
}

function mod_approve_file(Context $ctx, $board, $post) {
	global $config, $mod;
	if (!hasPermission($config['mod']['approve_files'], $board))
		error($config['error']['noaccess']);
	imgboard_approve($config, $board, $post, 'approved', 'Approved file', '?/pending');
}

/* ----------------------------------------------------------------------- *
 *  Pending links
 * ----------------------------------------------------------------------- */

function mod_pending_links(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');
	if (!hasPermission($config['mod']['approve_links']))
		error($config['error']['noaccess']);

	echo mod_page('Pending links', 'mod/pending.html', [
		'pending'   => imgboard_pending_queue('`approved_link` = 0', 'approvelink', 'link'),
		'pending_kind' => 'link',
	], $mod);
}

function mod_approve_link(Context $ctx, $board, $post) {
	global $config, $mod;
	if (!hasPermission($config['mod']['approve_links'], $board))
		error($config['error']['noaccess']);
	imgboard_approve($config, $board, $post, 'approved_link', 'Approved link', '?/pending');
}

/* ----------------------------------------------------------------------- *
 *  Combined pending queue (/pending) — files + links in one page.
 *  Shows whichever sections the current mod may act on.
 * ----------------------------------------------------------------------- */

function mod_pending_all(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');
	$may_files = hasPermission($config['mod']['approve_files']);
	$may_links = hasPermission($config['mod']['approve_links']);
	$may_posts = hasPermission($config['mod']['ppa_approve'] ?? 10);
	if (!$may_files && !$may_links && !$may_posts)
		error($config['error']['noaccess']);

	$sections = [];
	if ($may_files) {
		$sections[] = [
			'kind'    => 'file',
			'title'   => 'Pending files',
			'anchor'  => 'files',
			'pending' => imgboard_pending_queue('`approved` = 0 AND `num_files` > 0', 'approvefile', 'file'),
		];
	}
	if ($may_links) {
		$sections[] = [
			'kind'    => 'link',
			'title'   => 'Pending links',
			'anchor'  => 'links',
			'pending' => imgboard_pending_queue('`approved_link` = 0', 'approvelink', 'link'),
		];
	}

	// Post Pending approval: whole posts held for approval (if the viewer may act).
	$pending_posts = [];
	$ppa_enabled = false;
	$ppa_board_toggles = [];
	if ($may_posts) {
		imgboard_ppa_table();
		if ($q = query("SELECT `id`,`board`,`thread`,`is_op`,`subject`,`name`,`body_preview`,`num_files`,`ip`,`time` FROM ``imgboard_pending_posts`` ORDER BY `time` ASC")) {
			$pending_posts = $q->fetchAll(PDO::FETCH_ASSOC);
		}
		foreach ($pending_posts as &$pp) {
			$pp['approve_token'] = make_secure_link_token('ppa/approve/' . $pp['id']);
			$pp['reject_token']  = make_secure_link_token('ppa/reject/' . $pp['id']);
		}
		unset($pp);
		$ppa_enabled = imgboard_ppa_enabled();
		foreach (listBoards() as $b) {
			$ppa_board_toggles[] = [
				'uri'   => $b['uri'],
				'on'    => imgboard_ppa_enabled($b['uri']),
				'token' => make_secure_link_token('ppa/toggle/' . $b['uri']),
			];
		}
	}

	echo mod_page('Pending', 'mod/pending.html', [
		'sections'       => $sections,
		'pending_posts'  => $pending_posts,
		'ppa_enabled'    => $ppa_enabled,
		'ppa_board_toggles' => $ppa_board_toggles,
		'ppa_can_toggle' => hasPermission($config['mod']['ppa_toggle'] ?? 30),
	], $mod);
}

// Old /pending-links path now lives under /pending; keep the URL working.
function mod_pending_links_redirect(Context $ctx) {
	global $config;
	header('Location: ' . $config['root'] . '?/pending#pending-links', true, $config['redirect_http']);
}

/* ----------------------------------------------------------------------- *
 *  Overboard in mod mode (?/o/)
 *
 *  Renders the ukko overboard with $mod set so each post gets the usual
 *  per-post moderation controls, exactly like a board index mod view. Any
 *  logged-in mod may view; individual actions are gated by their own perms.
 * ----------------------------------------------------------------------- */
function mod_overboard(Context $ctx) {
	global $mod, $config;
	if (!$mod) {
		error($config['error']['noaccess']);
	}
	// Load the ukko theme class (its own `require 'info.php'` resolves against
	// the theme dir). require_once is a no-op if 'all' already pulled it in.
	require_once $config['dir']['themes'] . '/ukko/theme.php';
	if (!class_exists('ukko')) {
		error('Overboard theme (ukko) is not available.');
	}
	$u = new ukko();
	$u->settings = \Vichan\Functions\Theme\theme_settings('ukko');
	echo $u->build($mod);
}

/* ----------------------------------------------------------------------- *
 *  Delete every post by an IP (used by B&D+ / B&D++ in mod_ban_post).
 *  Mirrors mod_deletebyip's deletion loop, minus the permission check and
 *  redirect (the caller owns those). $boardName is the current board uri.
 * ----------------------------------------------------------------------- */
function imgboard_delete_posts_by_ip(string $ip, bool $global, string $boardName): void {
	global $config, $board;

	$boards = $global ? listBoards() : [ [ 'uri' => $boardName ] ];

	$sql = '';
	foreach ($boards as $_b) {
		$sql .= sprintf("SELECT `thread`, `id`, '%s' AS `board` FROM ``posts_%s`` WHERE `ip` = :ip UNION ALL ", $_b['uri'], $_b['uri']);
	}
	$sql = preg_replace('/UNION ALL $/', '', $sql);

	$q = prepare($sql);
	$q->bindValue(':ip', $ip);
	$q->execute() or error(db_error($q));

	@set_time_limit($config['mod']['rebuild_timelimit']);

	$threads_to_rebuild = [];
	$threads_deleted    = [];
	while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
		openBoard($row['board']);
		deletePost($row['id'], false, false);
		\Vichan\Functions\Theme\rebuild_themes('post-delete', $board['uri']);
		buildIndex();
		if ($row['thread']) {
			$threads_to_rebuild[$row['board']][$row['thread']] = true;
		} else {
			$threads_deleted[$row['board']][$row['id']] = true;
		}
	}
	foreach ($threads_to_rebuild as $_b => $_threads) {
		openBoard($_b);
		foreach ($_threads as $_thread => $_dummy) {
			if (!isset($threads_deleted[$_b][$_thread])) {
				buildThread($_thread);
			}
		}
		buildIndex();
	}
	if ($global) {
		$board = false;
	}

	$cip = cloak_ip($ip);
	modLog("Banned & deleted all posts by IP address: <a href=\"?/IP/$cip\">$cip</a>");
}

/* ----------------------------------------------------------------------- *
 *  Customisable top bar
 *
 *  The top bar (createBoardlist) is rendered from an ordered catalog of
 *  "items": nav links (home/rules/news/bans/overboard), group-break
 *  separators, each public board, and any future boards. Admins reorder and
 *  hide items on the ?/topbar page; the order + hidden set persist in
 *  imgboard_settings and are honoured on every page build.
 * ----------------------------------------------------------------------- */

// token => [ 'type' => nav|sep|board|future, 'label' => editor label, 'html' => rendered ]
// When $mod is set, the overboard links to its mod view (?/o/) so a logged-in
// mod lands on the overboard with moderation controls.
function imgboard_topbar_catalog(string $root, $mod = false): array {
	global $config;

	// Mod links are absolute to mod.php so they work even when the mod bar is
	// rendered on a page that isn't served by mod.php (e.g. the public homepage,
	// where a bare "?/news" would resolve to "/?/news"). Non-mods get the public
	// pages.
	$modp = $root . $config['file_mod'];   // e.g. /mod.php
	$overboard_href = $mod ? $modp . '?/o'     : $root . 'o/';
	$rules_href     = $mod ? $modp . '?/rules' : $root . 'rules.php';
	$news_href      = $mod ? $modp . '?/news'  : $root . 'news.php';
	$bans_href      = $mod ? $modp . '?/bans'  : $root . 'bans.html';

	$cat = [];
	$home_href      = $mod ? $modp . '?/' : $root;
	$cat['home']      = ['type' => 'nav', 'label' => 'home',      'html' => '<a href="' . $home_href . '">home</a>'];
	$cat['rules']     = ['type' => 'nav', 'label' => 'rules',     'html' => '<a href="' . $rules_href . '">rules</a>'];
	$cat['news']      = ['type' => 'nav', 'label' => 'news',      'html' => '<a href="' . $news_href . '">news</a>'];
	$cat['bans']      = ['type' => 'nav', 'label' => 'bans',      'html' => '<a href="' . $bans_href . '">bans</a>'];
	$cat['search']    = ['type' => 'nav', 'label' => 'search',    'html' => '<a href="' . $root . 'search.php">search</a>'];
	$cat['overboard'] = ['type' => 'nav', 'label' => 'overboard', 'html' => '<a href="' . $overboard_href . '">overboard</a>'];
	$cat['sep']       = ['type' => 'sep', 'label' => '— group break —', 'html' => ''];

	foreach (listBoards() as $b) {
		if (function_exists('imgboard_is_staff_board') && imgboard_is_staff_board($b['uri'])) {
			continue;
		}
		$cat['board:' . $b['uri']] = [
			'type'  => 'board',
			// Imgboard: bare board name everywhere (the bar slash-separates
			// boards instead of using the per-board /…/ wrapper), so the editor
			// chips and preview match the real bar.
			'label' => $b['uri'],
			// Mods browse boards through mod.php so every page keeps the mod controls.
			'html'  => '<a href="' . ($mod ? $modp . '?/' . $b['uri'] . '/' . $config['file_index'] : $root . $b['uri'] . '/') . '">' . $b['uri'] . '</a>',
		];
	}
	if (!empty($config['future_boards'])) {
		foreach ($config['future_boards'] as $fb) {
			$cat['future:' . $fb] = [
				'type'  => 'future',
				'label' => $fb . ' (soon)',
				'html'  => '<span class="future-board unimportant" title="coming soon">' . $fb . '</span>',
			];
		}
	}
	return $cat;
}

// The saved order, reconciled with the live catalog: saved tokens first (valid
// ones only), then any catalog tokens not yet placed (so new boards appear).
function imgboard_topbar_order(array $valid): array {
	$saved = function_exists('imgboard_setting_get') ? imgboard_setting_get('topbar_order', '') : '';
	$order = json_decode($saved, true);
	if (!is_array($order)) {
		// Never configured: use the catalog's natural order, which already has
		// one group break sitting between the nav links and the boards.
		return $valid;
	}
	$final = [];
	foreach ($order as $t) {
		if (!is_string($t)) continue;
		if ($t === 'sep') { $final[] = 'sep'; continue; }   // group breaks may repeat
		if (in_array($t, $valid, true) && !in_array($t, $final, true)) {
			$final[] = $t;
		}
	}
	// Append any catalog item the saved order didn't mention (e.g. a new board),
	// but never auto-insert a group break the admin didn't place.
	foreach ($valid as $t) {
		if ($t === 'sep') continue;
		if (!in_array($t, $final, true)) {
			$final[] = $t;
		}
	}
	return $final;
}

function imgboard_topbar_hidden(): array {
	$saved = function_exists('imgboard_setting_get') ? imgboard_setting_get('topbar_hidden', '') : '';
	$h = json_decode($saved, true);
	return is_array($h) ? array_values(array_filter($h, 'is_string')) : [];
}

// Render the nav+boards portion of the top bar as one or more bracketed groups.
// A 'sep' item starts a new bracket; hidden items are skipped.
function imgboard_topbar_build(string $root, $mod = false): string {
	$cat    = imgboard_topbar_catalog($root, $mod);
	$order  = imgboard_topbar_order(array_keys($cat));
	$hidden = imgboard_topbar_hidden();

	$groups = [[]];
	foreach ($order as $tok) {
		if (!isset($cat[$tok]) || in_array($tok, $hidden, true)) {
			continue;
		}
		if ($cat[$tok]['type'] === 'sep') {
			$groups[] = [];
			continue;
		}
		$groups[count($groups) - 1][] = $cat[$tok];
	}

	$out = [];
	foreach ($groups as $g) {
		if (!$g) {
			continue;
		}
		// Imgboard: every group is slash-separated ("[ home / rules / news ]").
		$html = array_map(function ($it) { return $it['html']; }, $g);
		$out[] = '[ ' . implode(' / ', $html) . ' ]';
	}
	return implode(' ', $out);
}

// Full static rebuild so a reordered bar takes effect on every already-built
// page (the bar is baked into indexes, threads and themed pages).
function imgboard_topbar_rebuild_all(): void {
	global $config, $board;
	@set_time_limit(0);

	// Flush the config cache so the next builds pick up any dependent config.
	@unlink('tmp/cache/cache_config.php');

	Vichan\Functions\Theme\rebuild_themes('all');
	foreach (listBoards() as $b) {
		openBoard($b['uri']);
		$config['try_smarter'] = false;
		buildIndex();
		$q = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL", $b['uri'])) or error(db_error());
		while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
			openBoard($b['uri']);
			buildThread($row['id']);
		}
	}
}

function mod_topbar_edit(Context $ctx) {
	global $mod, $config;
	if (!hasPermission($config['mod']['edit_topbar'])) {
		error($config['error']['noaccess']);
	}

	$cat   = imgboard_topbar_catalog($config['root']);
	$valid = array_keys($cat);

	if (isset($_POST['order'])) {
		$order = json_decode($_POST['order'], true);
		if (!is_array($order)) {
			$order = [];
		}
		// Keep only known tokens, de-duplicated, in the submitted order — except
		// group breaks ('sep'), which may appear as many times as the admin wants.
		$clean = [];
		foreach ($order as $t) {
			if (!is_string($t)) continue;
			if ($t === 'sep') { $clean[] = 'sep'; continue; }
			if (in_array($t, $valid, true) && !in_array($t, $clean, true)) {
				$clean[] = $t;
			}
		}
		$hidden = json_decode($_POST['hidden'] ?? '[]', true);
		if (!is_array($hidden)) {
			$hidden = [];
		}
		$clean_hidden = [];
		foreach ($hidden as $t) {
			if (is_string($t) && in_array($t, $valid, true) && !in_array($t, $clean_hidden, true)) {
				$clean_hidden[] = $t;
			}
		}

		// An empty layout only ever comes from a broken/blocked editor script; never
		// save it, or the whole bar would be wiped.
		if (!$clean) {
			header('Location: ' . $config['root'] . '?/topbar', true, $config['redirect_http']);
			return;
		}
		imgboard_setting_set('topbar_order',  json_encode($clean));
		imgboard_setting_set('topbar_hidden', json_encode($clean_hidden));
		modLog('Edited the top bar layout');
		imgboard_topbar_rebuild_all();

		header('Location: ' . $config['root'] . '?/topbar&saved=1', true, $config['redirect_http']);
		return;
	}

	// GET: list items in current order, flagging hidden ones.
	$order  = imgboard_topbar_order($valid);
	$hidden = imgboard_topbar_hidden();
	$items  = [];
	foreach ($order as $tok) {
		$items[] = [
			'token'  => $tok,
			'label'  => $cat[$tok]['label'],
			'type'   => $cat[$tok]['type'],
			'html'   => $cat[$tok]['html'],
			'hidden' => in_array($tok, $hidden, true),
		];
	}

	// Static preview of the mod-only group (staff boards + dashboard + pending),
	// which createBoardlist appends to every mod's bar but which isn't part of
	// the reorderable catalog. Shown read-only so the editor preview matches the
	// full bar a mod actually sees.
	$parts = [];
	foreach (listBoards() as $b) {
		if (function_exists('imgboard_is_staff_board') && imgboard_is_staff_board($b['uri'])) {
			$parts[] = $b['uri'];
		}
	}
	$pf = function_exists('imgboard_pending_count') ? imgboard_pending_count('files') : 0;
	$pl = function_exists('imgboard_pending_count') ? imgboard_pending_count('links') : 0;
	$parts[] = 'dashboard';
	$parts[] = 'pending (' . ($pf + $pl) . ')';
	$mod_preview = '[ ' . implode(' / ', $parts) . ' ]';

	echo mod_page('Edit top bar', 'mod/topbar.html', [
		'items'       => $items,
		'preview'     => strip_tags(imgboard_topbar_build($config['root'], false)),
		'mod_preview' => $mod_preview,
		'saved'       => isset($_GET['saved']),
		'token'       => make_secure_link_token('topbar'),
	], $mod);
}

/* ----------------------------------------------------------------------- *
 *  Rejecting pending files / links
 *
 *  Reject deletes the offending post. The "+ hashban" / "+ ban link" variants
 *  also record the file hash(es) / link(s) so the same content is refused on
 *  future posts (enforced in post.php).
 * ----------------------------------------------------------------------- */

// Banned file hashes (md5), checked against uploads in post.php.
function imgboard_filehash_table(): string {
	imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``banned_file_hashes`` (
		`hash` varchar(32) CHARACTER SET ascii NOT NULL,
		`added_by` varchar(30) DEFAULT NULL,
		`time` int(11) NOT NULL,
		PRIMARY KEY (`hash`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());
	return 'banned_file_hashes';
}

function imgboard_filehash_ban(array $hashes, ?string $by): void {
	imgboard_filehash_table();
	foreach (array_unique(array_filter($hashes)) as $h) {
		$q = prepare("REPLACE INTO ``banned_file_hashes`` (`hash`, `added_by`, `time`) VALUES (:h, :u, :t)");
		$q->bindValue(':h', $h);
		$q->bindValue(':u', $by);
		$q->bindValue(':t', time(), PDO::PARAM_INT);
		$q->execute() or error(db_error($q));
	}
	cache::delete('imgboard_filehash_bans');
}

function imgboard_filehash_banned_list(): array {
	global $config;
	$key = 'imgboard_filehash_bans';
	if ($config['cache']['enabled'] && ($v = cache::get($key)) !== false) {
		return $v;
	}
	imgboard_filehash_table();
	$rows = query("SELECT `hash` FROM ``banned_file_hashes``") or error(db_error());
	$out = [];
	while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
		$out[] = $r['hash'];
	}
	if ($config['cache']['enabled']) {
		cache::set($key, $out);
	}
	return $out;
}

// Called from post.php: true if any of these file hashes is banned.
function imgboard_filehash_is_banned(array $hashes): bool {
	$banned = imgboard_filehash_banned_list();
	if (!$banned) {
		return false;
	}
	foreach ($hashes as $h) {
		if ($h && in_array($h, $banned, true)) {
			return true;
		}
	}
	return false;
}

// Add a value to a blocklist (reused by Reject + ban link).
function imgboard_blocklist_add(string $type, string $value, ?string $by, string $status = 'active'): void {
	$value = trim($value);
	if ($value === '') {
		return;
	}
	$table = imgboard_blocklist_table($type);
	$q = prepare("INSERT INTO ``$table`` (`value`, `status`, `added_by`, `time`) VALUES (:v, :s, :u, :t)");
	$q->bindValue(':v', $value);
	$q->bindValue(':s', $status);
	$q->bindValue(':u', $by);
	$q->bindValue(':t', time(), PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	cache_delete_blocklist($type);
}

function imgboard_reject(string $board, $post, bool $hashban, bool $banlinks, bool $is_link, string $redirect = '?/pending') {
	global $config, $mod;
	// Reject needs the same authority as approving that kind.
	$perm = $is_link || $banlinks
		? $config['mod']['approve_links'] : $config['mod']['approve_files'];
	if (!hasPermission($perm, $board))
		error($config['error']['noaccess']);
	if (!openBoard($board))
		error($config['error']['noboard']);
	$post = (int)$post;

	$q = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `id` = :id", $board));
	$q->bindValue(':id', $post, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	$row = $q->fetch(PDO::FETCH_ASSOC);
	if ($row === false)
		error($config['error']['invalidpost']);

	if ($hashban) {
		$hashes = [];
		if (!empty($row['filehash'])) {
			$hashes[] = $row['filehash'];
		}
		$files = @json_decode($row['files']);
		if (is_array($files)) {
			foreach ($files as $f) {
				if (isset($f->hash)) {
					$hashes[] = $f->hash;
				}
			}
		}
		imgboard_filehash_ban($hashes, $mod['username']);
	}
	if ($banlinks) {
		foreach (imgboard_extract_links($row['body'] ?? $row['body_nomarkup'] ?? '') as $url) {
			imgboard_blocklist_add('links', $url, $mod['username']);
		}
	}

	deletePost($post); // rebuilds the thread/index

	// One clean action label in the moderation log.
	$is_link = $is_link || $banlinks;
	$label = $is_link
		? ($banlinks ? 'Rejected + Banned link' : 'Rejected link')
		: ($hashban  ? 'Rejected + Hashbanned file' : 'Rejected file');
	modLog($label, $board);
	header('Location: ' . $redirect, true, $config['redirect_http']);
}

function mod_reject_file(Context $ctx, $board, $post)         { imgboard_reject($board, $post, false, false, false, '?/pending'); }
function mod_reject_file_hashban(Context $ctx, $board, $post) { imgboard_reject($board, $post, true,  false, false, '?/pending'); }
function mod_reject_link(Context $ctx, $board, $post)         { imgboard_reject($board, $post, false, false, true,  '?/pending'); }
function mod_reject_link_ban(Context $ctx, $board, $post)     { imgboard_reject($board, $post, false, true,  true,  '?/pending'); }

/* ----------------------------------------------------------------------- *
 *  Mediator moderation-by-proposal  (vote-to-delete / vote-to-ban)
 *
 *  A Mediator cannot delete or ban directly. Instead they file a *request*
 *  (a "vote") that lands in the /mod-requests queue, where a Janitor or higher
 *  approves or denies it. Approving executes the action — delete the post, or
 *  ban the poster's IP (the ban is attributed to the approver). Requests live
 *  in the mod_action_requests table.
 * ----------------------------------------------------------------------- */

function imgboard_modreq_table(): string {
	imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``mod_action_requests`` (
		`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
		`board` varchar(58) NOT NULL,
		`post_id` int(11) unsigned NOT NULL,
		`action` enum('delete','ban') NOT NULL,
		`reason` text,
		`duration` varchar(64) DEFAULT NULL,
		`requested_by` varchar(30) DEFAULT NULL,
		`time` int(11) NOT NULL,
		`status` enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
		`handled_by` varchar(30) DEFAULT NULL,
		`handled_time` int(11) DEFAULT NULL,
		PRIMARY KEY (`id`),
		KEY `status` (`status`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());
	return 'mod_action_requests';
}

// Open proposals count (dashboard badge). 0 for roles that can't approve.
function imgboard_modreq_count(): int {
	global $mod, $config;
	if (!$mod || !hasPermission($config['mod']['approve_mod_requests'] ?? 10)) {
		return 0;
	}
	imgboard_modreq_table();
	$q = query("SELECT COUNT(*) AS c FROM ``mod_action_requests`` WHERE `status` = 'pending'");
	$r = $q ? $q->fetch(PDO::FETCH_ASSOC) : false;
	return $r ? (int)$r['c'] : 0;
}

// Fetch one post row (or null) for a board; opens the board as a side effect.
function imgboard_modreq_fetch_post(string $board, int $post): ?array {
	if (!openBoard($board)) {
		return null;
	}
	$q = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `id` = :id", $board));
	$q->bindValue(':id', $post, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	$row = $q->fetch(PDO::FETCH_ASSOC);
	return $row === false ? null : $row;
}

// Shared GET(form) / POST(file request) handler for a proposal.
function imgboard_vote(Context $ctx, string $board, $post, string $action) {
	global $mod;
	$config = $ctx->get('config');
	$perm = $action === 'ban' ? $config['mod']['vote_ban'] : $config['mod']['vote_delete'];
	if (!hasPermission($perm, $board)) {
		error($config['error']['noaccess']);
	}

	$post = (int)$post;
	$row = imgboard_modreq_fetch_post($board, $post);
	if ($row === null) {
		error($config['error']['invalidpost']);
	}

	if (isset($_POST['reason'])) {
		imgboard_modreq_table();
		// One open request per (board, post, action, requester) — ignore repeats.
		$dup = prepare("SELECT `id` FROM ``mod_action_requests`` WHERE `board` = :b AND `post_id` = :p AND `action` = :a AND `requested_by` = :u AND `status` = 'pending'");
		$dup->bindValue(':b', $board);
		$dup->bindValue(':p', $post, PDO::PARAM_INT);
		$dup->bindValue(':a', $action);
		$dup->bindValue(':u', $mod['username']);
		$dup->execute() or error(db_error($dup));
		if (!$dup->fetch()) {
			$ins = prepare("INSERT INTO ``mod_action_requests`` (`board`, `post_id`, `action`, `reason`, `duration`, `requested_by`, `time`, `status`) VALUES (:b, :p, :a, :r, :d, :u, :t, 'pending')");
			$ins->bindValue(':b', $board);
			$ins->bindValue(':p', $post, PDO::PARAM_INT);
			$ins->bindValue(':a', $action);
			$ins->bindValue(':r', trim((string)$_POST['reason']));
			$ins->bindValue(':d', $action === 'ban' ? trim((string)($_POST['duration'] ?? '')) : null);
			$ins->bindValue(':u', $mod['username']);
			$ins->bindValue(':t', time(), PDO::PARAM_INT);
			$ins->execute() or error(db_error($ins));
			modLog(($action === 'ban' ? 'Proposed a ban for' : 'Proposed deletion of') . " post #{$post}", $board);
		}
		header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
		return;
	}

	// GET: the proposal form.
	echo mod_page($action === 'ban' ? 'Propose ban' : 'Propose deletion', 'mod/modreq_form.html', [
		'action'  => $action,
		'board'   => $board,
		'post_id' => $post,
		'snippet' => mb_substr(trim(strip_tags((string)($row['body_nomarkup'] ?? ''))), 0, 300),
		'token'   => make_secure_link_token($board . '/' . ($action === 'ban' ? 'voteban' : 'votedelete') . '/' . $post),
	], $mod);
}

function mod_votedelete(Context $ctx, $board, $post) { imgboard_vote($ctx, $board, $post, 'delete'); }
function mod_voteban(Context $ctx, $board, $post)    { imgboard_vote($ctx, $board, $post, 'ban'); }

// Quick "note on this poster" jump from a post's controls: resolve the post's IP
// server-side and bounce to its IP page (so the raw IP never appears in a page
// rendered for a role without show_ip). The note itself is added there, gated
// by create_notes.
function mod_noteip(Context $ctx, $board, $post) {
	global $config;
	if (!hasPermission($config['mod']['create_notes'], $board)) {
		error($config['error']['noaccess']);
	}
	$row = imgboard_modreq_fetch_post($board, (int)$post);
	if ($row === null || !isset($row['ip'])) {
		error($config['error']['invalidpost']);
	}
	header('Location: ?/IP/' . cloak_ip($row['ip']) . '#notes', true, $config['redirect_http']);
}

function mod_modreq_queue(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');
	if (!hasPermission($config['mod']['approve_mod_requests'])) {
		error($config['error']['noaccess']);
	}
	imgboard_modreq_table();

	$rows = query("SELECT * FROM ``mod_action_requests`` WHERE `status` = 'pending' ORDER BY `time` ASC") or error(db_error());
	$requests = [];
	foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$post = imgboard_modreq_fetch_post($r['board'], (int)$r['post_id']);
		if ($post !== null) {
			$post['board'] = $r['board'];
			$po = $post['thread'] ? new Post($post, '?/', $mod) : new Thread($post, '?/', $mod, false);
			$r['built'] = $po->build(true);
		} else {
			$r['built'] = '<em>(post no longer exists)</em>';
		}
		$r['missing']       = ($post === null);
		$r['approve_token'] = make_secure_link_token('mod-requests/approve/' . $r['id']);
		$r['deny_token']    = make_secure_link_token('mod-requests/deny/' . $r['id']);
		$requests[] = $r;
	}

	echo mod_page('Mod requests', 'mod/modreq_queue.html', [ 'requests' => $requests ], $mod);
}

function imgboard_modreq_load(int $id): ?array {
	imgboard_modreq_table();
	$q = prepare("SELECT * FROM ``mod_action_requests`` WHERE `id` = :id");
	$q->bindValue(':id', $id, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	$r = $q->fetch(PDO::FETCH_ASSOC);
	return $r === false ? null : $r;
}

function imgboard_modreq_close(int $id, string $status): void {
	global $mod;
	$q = prepare("UPDATE ``mod_action_requests`` SET `status` = :s, `handled_by` = :h, `handled_time` = :t WHERE `id` = :id");
	$q->bindValue(':s', $status);
	$q->bindValue(':h', $mod['username']);
	$q->bindValue(':t', time(), PDO::PARAM_INT);
	$q->bindValue(':id', $id, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
}

function mod_modreq_approve(Context $ctx, $id) {
	global $mod;
	$config = $ctx->get('config');
	if (!hasPermission($config['mod']['approve_mod_requests'])) {
		error($config['error']['noaccess']);
	}
	$id = (int)$id;
	$req = imgboard_modreq_load($id);
	if ($req === null || $req['status'] !== 'pending') {
		error('No such open request.');
	}
	// Per-board authority (honours per-board mod assignments).
	if (!hasPermission($config['mod']['approve_mod_requests'], $req['board'])) {
		error($config['error']['noaccess']);
	}

	$post = imgboard_modreq_fetch_post($req['board'], (int)$req['post_id']);

	if ($req['action'] === 'delete') {
		if ($post !== null) {
			deletePost((int)$req['post_id']);
			buildIndex();
			Vichan\Functions\Theme\rebuild_themes('post-delete', $req['board']);
			modLog("Approved deletion of post #{$req['post_id']} (proposed by {$req['requested_by']})", $req['board']);
		}
	} else { // ban
		if ($post !== null && isset($post['ip'])) {
			$length = ($req['duration'] !== null && $req['duration'] !== '') ? $req['duration'] : false;
			Bans::new_ban($post['ip'], $req['reason'], $length, false /* site-wide */, false,
				$config['ban_show_post'] ? $post : false);
			modLog("Approved site-wide ban of post #{$req['post_id']} (proposed by {$req['requested_by']})", $req['board']);
		}
	}

	imgboard_modreq_close($id, 'approved');
	header('Location: ?/mod-requests', true, $config['redirect_http']);
}

function mod_modreq_deny(Context $ctx, $id) {
	global $config;
	if (!hasPermission($config['mod']['approve_mod_requests'])) {
		error($config['error']['noaccess']);
	}
	$id = (int)$id;
	$req = imgboard_modreq_load($id);
	if ($req === null || $req['status'] !== 'pending') {
		error('No such open request.');
	}
	imgboard_modreq_close($id, 'denied');
	modLog("Denied a {$req['action']} proposal for post #{$req['post_id']} (proposed by {$req['requested_by']})", $req['board']);
	header('Location: ?/mod-requests', true, $config['redirect_http']);
}

/* ----------------------------------------------------------------------- *
 *  Blocked words / Blocked links
 * ----------------------------------------------------------------------- */

// Map the URL token to a table name and ensure the table exists.
function imgboard_blocklist_table(string $type): string {
	$table = $type === 'words' ? 'blocked_words' : 'blocked_links';
	imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``$table`` (
		`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
		`value` varchar(255) NOT NULL,
		`status` enum('active','suggested') NOT NULL DEFAULT 'active',
		`added_by` varchar(30) DEFAULT NULL,
		`time` int(11) NOT NULL,
		PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());
	return $table;
}

// Count of pending suggestions awaiting a moderator's approve/dismiss. Shown as
// a badge next to the Blocked words/links links on the dashboard.
function imgboard_blocklist_suggested_count(string $type): int {
	$table = imgboard_blocklist_table($type);
	$q = query("SELECT COUNT(*) AS c FROM ``$table`` WHERE `status` = 'suggested'");
	$r = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
	return $r ? (int)$r['c'] : 0;
}

// Active values for enforcement (cached). Used by post.php too.
function imgboard_blocklist_active(string $type): array {
	$table = imgboard_blocklist_table($type);
	$rows = query("SELECT `value` FROM ``$table`` WHERE `status` = 'active'") or error(db_error());
	$out = [];
	while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
		$out[] = $r['value'];
	}
	return $out;
}

function mod_blocklist(Context $ctx, $type) {
	global $mod;
	$config = $ctx->get('config');
	$type = ($type === 'links') ? 'links' : 'words';
	$table = imgboard_blocklist_table($type);

	$can_edit    = hasPermission($config['mod']['edit_blocklists']);
	$can_suggest = hasPermission($config['mod']['suggest_blocklists']);
	if (!$can_edit && !$can_suggest)
		error($config['error']['noaccess']);

	// Bulk edit: the whole active list arrives as one entry per line from the
	// code box. Replace the active set wholesale; leave suggestions untouched.
	if ($can_edit && isset($_POST['content'])) {
		$seen = [];
		$values = [];
		foreach (preg_split('/\r\n|\r|\n/', $_POST['content']) as $line) {
			$v = trim($line);
			if ($v === '') continue;
			$k = mb_strtolower($v);
			if (isset($seen[$k])) continue;   // de-dupe, case-insensitively
			$seen[$k] = true;
			$values[] = $v;
		}
		query("DELETE FROM ``$table`` WHERE `status` = 'active'") or error(db_error());
		if ($values) {
			$ins = prepare("INSERT INTO ``$table`` (`value`, `status`, `added_by`, `time`) VALUES (:v, 'active', :u, :t)");
			foreach ($values as $v) {
				$ins->bindValue(':v', $v);
				$ins->bindValue(':u', $mod['username']);
				$ins->bindValue(':t', time(), PDO::PARAM_INT);
				$ins->execute() or error(db_error($ins));
			}
		}
		modLog("Edited the blocked " . $type . " list (" . count($values) . " entries)");
		cache_delete_blocklist($type);
		header('Location: ?/blocked-' . $type, true, $config['redirect_http']);
		return;
	}

	// Suggest a single value (roles that may suggest but not edit the list).
	if (!$can_edit && $can_suggest && isset($_POST['value']) && $_POST['value'] !== '') {
		$q = prepare("INSERT INTO ``$table`` (`value`, `status`, `added_by`, `time`) VALUES (:v, 'suggested', :u, :t)");
		$q->bindValue(':v', $_POST['value']);
		$q->bindValue(':u', $mod['username']);
		$q->bindValue(':t', time(), PDO::PARAM_INT);
		$q->execute() or error(db_error($q));
		modLog("Suggested a blocked " . rtrim($type, 's') . ": " . $_POST['value']);
		cache_delete_blocklist($type);
		header('Location: ?/blocked-' . $type, true, $config['redirect_http']);
		return;
	}

	// Active values, one per line, for the code editor.
	$arows = query("SELECT `value` FROM ``$table`` WHERE `status` = 'active' ORDER BY `id` ASC") or error(db_error());
	$active_text = implode("\n", array_map(fn($r) => $r['value'], $arows->fetchAll(PDO::FETCH_ASSOC)));

	// Pending suggestions, listed separately with approve / dismiss.
	$srows = query("SELECT * FROM ``$table`` WHERE `status` = 'suggested' ORDER BY `id` DESC") or error(db_error());
	$suggested = $srows->fetchAll(PDO::FETCH_ASSOC);
	foreach ($suggested as &$it) {
		$it['approve_token'] = make_secure_link_token("blocked-$type/approve/" . $it['id']);
		$it['delete_token']  = make_secure_link_token("blocked-$type/delete/" . $it['id']);
	}
	unset($it);

	echo mod_page($type === 'words' ? 'Blocked words' : 'Blocked links', 'mod/blocklist.html', [
		'type'        => $type,
		'active_text' => $active_text,
		'suggested'   => $suggested,
		'can_edit'    => $can_edit,
		'can_suggest' => $can_suggest,
		'token'       => make_secure_link_token("blocked-$type"),
	], $mod);
}

function mod_blocklist_approve(Context $ctx, $type, $id) {
	global $config;
	$type = ($type === 'links') ? 'links' : 'words';
	if (!hasPermission($config['mod']['edit_blocklists']))
		error($config['error']['noaccess']);
	$table = imgboard_blocklist_table($type);
	$q = prepare("UPDATE ``$table`` SET `status` = 'active' WHERE `id` = :id");
	$q->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	modLog("Approved a suggested blocked " . rtrim($type, 's') . " (#" . (int)$id . ")");
	cache_delete_blocklist($type);
	header('Location: ?/blocked-' . $type, true, $config['redirect_http']);
}

function mod_blocklist_delete(Context $ctx, $type, $id) {
	global $config;
	$type = ($type === 'links') ? 'links' : 'words';
	if (!hasPermission($config['mod']['edit_blocklists']))
		error($config['error']['noaccess']);
	$table = imgboard_blocklist_table($type);
	$q = prepare("DELETE FROM ``$table`` WHERE `id` = :id");
	$q->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	modLog("Deleted a blocked " . rtrim($type, 's') . " (#" . (int)$id . ")");
	cache_delete_blocklist($type);
	header('Location: ?/blocked-' . $type, true, $config['redirect_http']);
}

/* ----------------------------------------------------------------------- *
 *  Schema upgrade (admin): add any columns this build expects that older
 *  boards are missing. Idempotent and safe to run repeatedly. Visit
 *  ?/upgrade-db as an admin after deploying a build that adds post columns.
 * ----------------------------------------------------------------------- */

function mod_upgrade_db(Context $ctx) {
	global $mod, $config;
	if (!hasPermission($config['mod']['upgrade_db'] ?? 30))
		error($config['error']['noaccess']);

	// Columns this build expects on every posts_<board> table.
	$required = [
		'approved'       => "tinyint(1) NOT NULL DEFAULT 1",
		'approved_link'  => "tinyint(1) NOT NULL DEFAULT 1",
		'cookie_id'      => "varchar(64) CHARACTER SET ascii DEFAULT NULL",
		'hwid'           => "varchar(64) CHARACTER SET ascii DEFAULT NULL",
		'is_vpn'         => "tinyint(1) NOT NULL DEFAULT 0",
		'show_poster_id' => "tinyint(1) NOT NULL DEFAULT 0",
		'mod_id'         => "int(11) DEFAULT NULL",
		// Appended last to match posts.sql order (positional post INSERT).
		'fp_hash'        => "varchar(32) CHARACTER SET ascii DEFAULT NULL",
	];
	$db = $config['db']['database'];
	$report = [];

	// Browser-fingerprint store (DTEG (js/dteg/) -> fp.php).
	imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``imgboard_fp`` (
		`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`fp_hash` varchar(32) CHARACTER SET ascii NOT NULL,
		`stable_hash` varchar(32) CHARACTER SET ascii NOT NULL,
		`cookie_id` varchar(64) CHARACTER SET ascii NOT NULL DEFAULT '',
		`hwid` varchar(64) CHARACTER SET ascii DEFAULT NULL,
		`ip` varchar(45) CHARACTER SET ascii DEFAULT NULL,
		`trust` tinyint(3) unsigned DEFAULT NULL,
		`lies` text,
		`components` mediumtext,
		`user_agent` varchar(512) DEFAULT NULL,
		`first_seen` int(11) NOT NULL,
		`last_seen` int(11) NOT NULL,
		`hits` int(11) NOT NULL DEFAULT 1,
		PRIMARY KEY (`id`),
		UNIQUE KEY `sighting` (`stable_hash`,`cookie_id`,`ip`),
		KEY `k_fp` (`fp_hash`),
		KEY `k_stable` (`stable_hash`),
		KEY `k_cookie` (`cookie_id`),
		KEY `k_hwid` (`hwid`),
		KEY `k_ip` (`ip`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());

	foreach (listBoards() as $b) {
		$table = 'posts_' . $b['uri'];
		$q = prepare("SELECT `COLUMN_NAME` FROM information_schema.columns WHERE table_schema = :db AND table_name = :t");
		$q->bindValue(':db', $db);
		$q->bindValue(':t', $table);
		$q->execute() or error(db_error($q));
		$have = array_map(fn($r) => $r['COLUMN_NAME'], $q->fetchAll(PDO::FETCH_ASSOC));

		$added = [];
		foreach ($required as $col => $def) {
			if (!in_array($col, $have, true)) {
				query(sprintf("ALTER TABLE ``%s`` ADD COLUMN ``%s`` %s", $table, $col, $def)) or error(db_error());
				$added[] = $col;
			}
		}
		$report[] = '/' . $b['uri'] . '/ : ' . ($added ? 'added ' . implode(', ', $added) : 'already up to date');
	}

	// Activate the Public Banlist theme (generates /bans.html) if not already on.
	$q = query("SELECT 1 FROM ``theme_settings`` WHERE `theme` = 'public_banlist' AND `name` IS NULL AND `value` IS NULL");
	if ($q && !$q->fetch()) {
		query("INSERT INTO ``theme_settings`` (`theme`, `name`, `value`) VALUES
			('public_banlist', NULL, NULL),
			('public_banlist', 'file_json', 'bans.json'),
			('public_banlist', 'file_bans', 'bans.html')") or error(db_error());
		cache::delete('themes');
		$report[] = 'Public Banlist theme: activated (rebuild to generate bans.html)';
	} else {
		$report[] = 'Public Banlist theme: already active';
	}

	// Seed the editable page content if empty.
	if (imgboard_setting_get('rules', '') === '') { imgboard_setting_set('rules', IMGBOARD_DEFAULT_RULES); $report[] = 'Rules page: seeded default'; }
	if (imgboard_setting_get('news', '') === '')  { imgboard_setting_set('news', IMGBOARD_DEFAULT_NEWS);  $report[] = 'News page: seeded default'; }

	if ($report) {
		modLog('Ran schema/setup upgrade');
	}

	echo mod_page('Upgrade database', 'mod/upgrade_db.html', [ 'report' => $report ], $mod);
}

/* ----------------------------------------------------------------------- *
 *  Site settings store (rules / news page content), admin-editable.
 * ----------------------------------------------------------------------- */

function imgboard_settings_table(): string {
	imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``imgboard_settings`` (
		`name` varchar(64) NOT NULL,
		`value` mediumtext,
		PRIMARY KEY (`name`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());
	return 'imgboard_settings';
}

function imgboard_setting_get(string $name, string $default = ''): string {
	imgboard_settings_table();
	$q = prepare("SELECT `value` FROM ``imgboard_settings`` WHERE `name` = :n");
	$q->bindValue(':n', $name);
	$q->execute() or error(db_error($q));
	$r = $q->fetch(PDO::FETCH_ASSOC);
	return ($r && $r['value'] !== null && $r['value'] !== '') ? $r['value'] : $default;
}

function imgboard_setting_set(string $name, string $value): void {
	imgboard_settings_table();
	$q = prepare("REPLACE INTO ``imgboard_settings`` (`name`, `value`) VALUES (:n, :v)");
	$q->bindValue(':n', $name);
	$q->bindValue(':v', $value);
	$q->execute() or error(db_error($q));
}

// Default page content (shown until an admin edits it).
const IMGBOARD_DEFAULT_RULES = <<<'HTML'
<ol>
	<li>You must be 18 years or older to use this site.</li>
	<li>You will not upload, post, request, or link to content that puts the site at risk, including but not limited to:
		<ul><li>Child abuse</li><li>Animal crush</li><li>Credible threats of violence</li></ul></li>
	<li>You will not post the following types of content intended to harm other users or their browsing experiences:
		<ul><li>IP grabbers</li><li>Malware</li><li>Text formatting abuse that slows down browsers</li></ul></li>
	<li>You will not upload pornographic content.
		<ul><li>Goatse and cado are exceptions to this rule.</li></ul></li>
	<li>Evading bans or other moderation actions is not tolerated and will result in further action.</li>
	<li>Unsolicited advertising is not welcome.</li>
	<li>Posts irrelevant to the board will be deleted.</li>
	<li>Do not attempt to make yourself identifiable across the board unless the name field is present.</li>
	<li>Bots and automated posting scripts are prohibited.</li>
	<li>Pedophilia, zoophilia, and entomophilia are not permitted.</li>
</ol>
HTML;

const IMGBOARD_DEFAULT_NEWS = '<p>No news to show.</p>';

// Footer pages. These are the starting texts; the owner edits them from the page itself
// ([ Edit this page ], Admin+). They describe what the site actually does today - update
// them if the site changes (what is collected, who it is shared with).
// NOTE: this is protective boilerplate, NOT legal advice. Before you launch, set a real
// [jurisdiction] in the Governing law clause, replace example.com, and have a lawyer review
// it for your country and host — liability waivers are not enforceable everywhere.
const IMGBOARD_DEFAULT_TOS = <<<'HTML'
<p class="unimportant">Last updated: November 2026</p>
<p>By accessing or using example.com (including its Tor and I2P mirrors) you agree to these Terms and to the <a href="/rules.php">Rules</a>, which are incorporated by reference. If you do not agree, do not use the site.</p>
<h4>Eligibility</h4>
<p>You must be at least 18 years old and old enough to form a binding contract where you live. By using the site you represent and warrant that you meet this requirement.</p>
<h4>Your content</h4>
<ul>
	<li>You are solely responsible for everything you post or upload and for any consequence of it. Do not post anything you do not have the right to post.</li>
	<li>You keep ownership of your content. By posting it you grant the operator a non-exclusive, royalty-free, worldwide licence to store, display, copy, adapt and distribute it as needed to run the site.</li>
	<li>Posts are public. Anything you post may be seen, copied and quoted by anyone, and may remain visible or cached elsewhere after it is removed here.</li>
</ul>
<h4>User content and no endorsement</h4>
<p>The site hosts content submitted by its users. The operator does not create, endorse, verify, monitor or adopt user content, and is not responsible or liable for it. Any views expressed are those of the user who posted them, not the operator. You access and rely on any content at your own risk.</p>
<h4>Illegal content</h4>
<p>Child sexual abuse material (CSAM), and any other content that is illegal, is forbidden with no exceptions. It is removed, the poster is banned, and it is reported to the authorities (for CSAM, the NCMEC CyberTipline) along with the information we hold about the poster.</p>
<h4>Moderation</h4>
<ul>
	<li>Staff may remove any content and ban any user at any time, with or without a reason or notice. Evading a ban is itself a violation.</li>
	<li>Posts made through the Tor and I2P mirrors are held for staff approval before they appear.</li>
	<li>Moderation is provided on a best-effort basis. We do not promise to review, remove or retain any particular content, and not acting on something is not a waiver of any right.</li>
</ul>
<h4>No warranty</h4>
<p>The site is provided "as is" and "as available", without warranties of any kind, express or implied, including merchantability, fitness for a particular purpose, availability, accuracy or non-infringement, to the maximum extent permitted by law. It may be slow, change, go offline or lose data at any time, and we do not guarantee that any content will be kept. We are not responsible for user content or for links to other sites.</p>
<h4>Limitation of liability</h4>
<p>To the maximum extent permitted by law, the operator, its staff and its providers are not liable for any indirect, incidental, special, consequential or punitive damages, or for any loss of data, profits, goodwill or content, arising from or relating to your use of the site or any content on it, whether based in contract, tort or any other theory, even if advised of the possibility. Where liability cannot lawfully be excluded, it is limited to the smallest amount the law permits.</p>
<h4>Assumption of risk and indemnification</h4>
<p>You use the site at your own risk. You agree to indemnify and hold harmless the operator and its staff from any claim, demand, loss or expense (including reasonable legal fees) arising from your content, your use of the site, or your breach of these Terms, the Rules, or any law or third-party right.</p>
<h4>Governing law and severability</h4>
<p>These Terms are governed by the laws of [jurisdiction], without regard to conflict-of-laws rules, and any dispute is subject to the exclusive jurisdiction of its courts. If any part of these Terms is held unenforceable, the rest stays in effect.</p>
<h4>Changes and contact</h4>
<p>We may change these Terms at any time; continued use means you accept the new version. To report content or reach staff, see <a href="/abuse.php">Report Abuse</a>. How we handle information is described in the <a href="/privacy.php">Privacy Policy</a>.</p>
HTML;

const IMGBOARD_DEFAULT_PRIVACY = <<<'HTML'
<p class="unimportant">Last updated: November 2026</p>
<p>This describes what example.com collects and who can see it. There are no accounts for ordinary users.</p>
<h4>What is collected</h4>
<ul>
	<li><b>What you post:</b> the text, name, files, file names and file hashes you submit, and the deletion password you choose.</li>
	<li><b>Your IP address,</b> stored with each post and used to moderate and stop abuse.</li>
	<li><b>Cookies:</b> a random ID that is stamped on your posts, a short-lived captcha cookie, and on the Tor and I2P mirrors a random cookie that stands in for an IP address. Staff use a login cookie.</li>
	<li><b>Device information:</b> a hash of your browser headers, and, when scripts are enabled, a browser fingerprint (screen, graphics, fonts, time zone and similar signals). This is collected when you load pages, whether or not you post.</li>
</ul>
<h4>Who can see it</h4>
<p>Only staff. What a staff member can see depends on their rank: lower ranks see only scrambled identifiers; higher ranks see IP addresses and fingerprint details. Your IP address and device details are not shown to other users.</p>
<h4>Other services</h4>
<ul>
	<li>The site is served through <b>Cloudflare</b>, which handles your connection and sees your IP address. New threads may require a Cloudflare Turnstile challenge. Country flags are taken from Cloudflare.</li>
	<li>IP addresses of people who post may be checked against a VPN and proxy detection service (proxycheck.io).</li>
	<li>The Tor and I2P mirrors do not see your real IP address; each visitor is given a stand-in address derived from a cookie.</li>
</ul>
<h4>Keeping and sharing</h4>
<ul>
	<li>Posts and the information above are kept for moderation and abuse prevention. There is no fixed deletion schedule.</li>
	<li>We do not sell this information. We may share it with law enforcement or others when the law requires it, or to deal with illegal content such as CSAM.</li>
</ul>
<h4>Your choices</h4>
<p>You can delete your own posts with the deletion password you set, and avoid cookies and scripts by blocking them in your browser (some features will stop working). To ask about information that relates to you, see <a href="/abuse.php">Report Abuse</a> for how to contact staff. You must be 18 or older to use the site.</p>
HTML;

const IMGBOARD_DEFAULT_ABUSE = <<<'HTML'
<p>Use this page to report content or behaviour that breaks the <a href="/rules.php">Rules</a> or the law.</p>
<h4>Report a post</h4>
<p>The fastest way is on the post itself: tick the box next to the post, then click <b>Report</b> at the bottom of the page and say what is wrong. Staff review every report. Reports about illegal content are handled first.</p>
<h4>Illegal content (including child sexual abuse material)</h4>
<ul>
	<li>Report it immediately with the Report button. Do not download, copy or share it.</li>
	<li>We remove it, ban the poster, keep the evidence and report it to the authorities.</li>
	<li>You can also report CSAM straight to the <a href="https://report.cybertip.org/" rel="noreferrer">NCMEC CyberTipline</a> (report.cybertip.org), or to your local police.</li>
</ul>
<!-- TODO: replace contact@example.com (below and in the Contact staff section) with your own contact info. -->
<h4>Copyright and takedown requests</h4>
<p>If you own content that was posted without permission, email <a href="mailto:contact@example.com">contact@example.com</a> with: what the work is, the link to each post, how we can reach you, and a statement that you are the owner or authorised to act for them.</p>
<h4>Contact staff</h4>
<p>For takedown requests and any other abuse report, email <a href="mailto:contact@example.com">contact@example.com</a>. Include the link to each post you are reporting and what is wrong with it. The Report button on a post is still the fastest way to flag something for review.</p>
<p>For legal reasons, site/hosting inquiries and other general inquiries, email <a href="mailto:contact@example.com">contact@example.com</a>.</p>
HTML;

// The text each footer/content page starts with, until an admin edits it.
const IMGBOARD_PAGE_DEFAULTS = [
	'rules'   => IMGBOARD_DEFAULT_RULES,
	'news'    => IMGBOARD_DEFAULT_NEWS,
	'tos'     => IMGBOARD_DEFAULT_TOS,
	'privacy' => IMGBOARD_DEFAULT_PRIVACY,
	'abuse'   => IMGBOARD_DEFAULT_ABUSE,
];

function imgboard_edit_page_content(Context $ctx, string $key, string $title, string $view) {
	global $mod, $config;
	if (!hasPermission($config['mod']['edit_pages_content']))
		error($config['error']['noaccess']);

	if (isset($_POST['content'])) {
		imgboard_setting_set($key, $_POST['content']);
		modLog("Edited the {$key} page");
		header('Location: ' . $config['root'] . $view, true, $config['redirect_http']);
		return;
	}

	$default = IMGBOARD_PAGE_DEFAULTS[$key] ?? '';
	echo mod_page('Edit ' . $title, 'mod/edit_page.html', [
		'key'        => $key,
		'page_title' => $title,
		'content'    => imgboard_setting_get($key, $default),
		'token'      => make_secure_link_token('edit-' . $key),
		'view'       => $config['root'] . $view,
	], $mod);
}

function mod_edit_rules(Context $ctx) { imgboard_edit_page_content($ctx, 'rules', 'Rules', 'rules.php'); }
function mod_edit_tos(Context $ctx)     { imgboard_edit_page_content($ctx, 'tos', 'Terms of Service', 'tos.php'); }
function mod_edit_privacy(Context $ctx) { imgboard_edit_page_content($ctx, 'privacy', 'Privacy Policy', 'privacy.php'); }
function mod_edit_abuse(Context $ctx)   { imgboard_edit_page_content($ctx, 'abuse', 'Report Abuse', 'abuse.php'); }

// Mod-context views of the public pages: ?/rules and ?/news render the page
// with the mod bar and (for admins) the Edit link, so staff can read them and
// jump to editing without leaving mod.php. Bans already live at ?/bans.
function mod_view_rules(Context $ctx) { imgboard_render_content_page('rules', 'Rules', IMGBOARD_DEFAULT_RULES, true); }
function mod_view_news(Context $ctx)  { imgboard_render_news_page(true); }

// Strip anything that can run script from admin-written HTML (rules/news are
// stored raw). Without this a rogue/compromised Admin could plant script that
// runs when Root or a Manager views the page. Keeps normal formatting.
// shortcut: blocklist, not a full allowlist; swap for HTMLPurifier if rich embeds are needed.
function imgboard_sanitize_html(string $html): string {
	if (trim($html) === '') return '';
	$d = new DOMDocument();
	libxml_use_internal_errors(true);
	$d->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	libxml_clear_errors();
	$xp = new DOMXPath($d);
	foreach ($xp->query('//script|//iframe|//object|//embed|//form|//meta|//link|//base|//style|//svg|//math') as $n) {
		$n->parentNode->removeChild($n);
	}
	foreach ($xp->query('//*[@*]') as $el) {
		foreach (iterator_to_array($el->attributes) as $a) {
			$name = strtolower($a->name);
			$val = strtolower(preg_replace('/[\x00-\x20]+/', '', $a->value));
			$bad = strpos($name, 'on') === 0 || $name === 'srcdoc' || $name === 'formaction'
				|| (in_array($name, ['href', 'src', 'xlink:href', 'action'], true)
					&& preg_match('/^(javascript|vbscript|data):/', $val) && strpos($val, 'data:image/') !== 0);
			if ($bad) $el->removeAttributeNode($a);
		}
	}
	$out = '';
	foreach ($d->documentElement->childNodes as $c) $out .= $d->saveHTML($c);
	return $out;
}

// Public News page. News is managed through vichan's NATIVE news system
// (?/edit_news, the ``news`` table), so this renders those entries and points
// the Edit link there — not at the custom settings store.
function imgboard_render_news_page(bool $as_mod = false) {
	global $config, $mod;

	if ($as_mod) {
		// Called from the mod ?/news route: $mod is already set by mod.php, so
		// show the mod bar and keep the mod context.
		$show_mod = $mod;
	} else {
		$ctx = Vichan\build_context($config);
		$mod = false;
		if (isset($_COOKIE[$config['cookies']['mod']])) {
			check_login($ctx, false);
		}
		$show_mod = false;
	}
	$is_admin = $mod && $mod['type'] >= ($config['mod']['news'] ?? 20);

	$entries = [];
	if ($q = query("SELECT * FROM ``news`` ORDER BY `id` DESC LIMIT 50")) {
		$entries = $q->fetchAll(PDO::FETCH_ASSOC);
		foreach ($entries as &$e) { $e['body'] = imgboard_sanitize_html((string)$e['body']); }
		unset($e);
	}

	echo Element('page.html', [
		'config'              => $config,
		'mod'                 => $show_mod,
		'hide_dashboard_link' => true,
		'title'               => 'News',
		'subtitle'            => '',
		'boardlist'           => createBoardlist($as_mod),
		'body'                => Element('imgboard_news_page.html', [
			'config'   => $config,
			'entries'  => $entries,
			'is_admin' => $is_admin,
			'edit_url' => $config['root'] . $config['file_mod'] . '?/edit_news',
		]),
	]);
}

// Render a public content page (rules / news) with the site chrome, plus an
// Edit link when an admin is viewing. Called from the rules.php / news.php
// front controllers.
function imgboard_render_content_page(string $key, string $title, string $default, bool $as_mod = false) {
	global $config, $mod;

	if ($as_mod) {
		// Called from the mod ?/rules route: $mod is already set by mod.php, so
		// show the mod bar and keep the mod context.
		$show_mod = $mod;
	} else {
		$ctx = Vichan\build_context($config);
		$mod = false;
		// Soft login check so we can show the Edit link to admins (needs jail=false).
		if (isset($_COOKIE[$config['cookies']['mod']])) {
			check_login($ctx, false);
		}
		$show_mod = false;
	}
	$is_admin = $mod && $mod['type'] >= $config['mod']['edit_pages_content'];

	echo Element('page.html', [
		'config'              => $config,
		'mod'                 => $show_mod,
		'hide_dashboard_link' => true,
		'title'               => $title,
		'subtitle'            => '',
		'boardlist'           => createBoardlist($as_mod),
		'body'                => Element('imgboard_content_page.html', [
			'config'   => $config,
			'heading'  => $title,
			'content'  => imgboard_sanitize_html(imgboard_setting_get($key, $default)),
			'is_admin' => $is_admin,
			'edit_url' => $config['root'] . $config['file_mod'] . '?/edit-' . $key,
		]),
	]);
}

/* ----------------------------------------------------------------------- *
 *  Pending counts (dashboard badges + top bar)
 * ----------------------------------------------------------------------- */

// Live count of pending files/links across the current mod's boards. Exposed
// to templates as the Twig function imgboard_pending_count() (see inc/template.php)
// and called directly from createBoardlist() for the mod top bar.
function imgboard_pending_count(string $type): int {
	global $mod;
	if (!$mod) return 0;
	$where = $type === 'links'
		? '`approved_link` = 0'
		: '`approved` = 0 AND `num_files` > 0';
	$total = 0;
	foreach (imgboard_mod_boards() as $b) {
		$q = query(sprintf("SELECT COUNT(*) AS c FROM ``posts_%s`` WHERE $where", $b['uri']));
		if ($q && ($r = $q->fetch(PDO::FETCH_ASSOC))) {
			$total += (int)$r['c'];
		}
	}
	return $total;
}

/* ----------------------------------------------------------------------- *
 *  Per-board name settings
 *
 *  Boards are forced-anon by default (inc/config.php). An admin can, per board,
 *  show the name field and/or set a custom default (anonymous) name from the
 *  ?/board-names page. Settings are stored in imgboard_settings:
 *    board_<uri>_name_field : '1' = show the name field, '0' = forced anon
 *    board_<uri>_anon_name  : custom default name (blank = use the global one)
 * ----------------------------------------------------------------------- */

// Called from inc/instance-config.php on every (uncached) loadConfig, so it
// sees $board when a board is open; the result is cached per board URI.
function imgboard_apply_board_overrides(): void {
	global $board, $config;
	if (!isset($board['uri'])) {
		return;
	}
	try {
		$uri = $board['uri'];
		$name_field = imgboard_setting_get('board_' . $uri . '_name_field', '');
		if ($name_field !== '') {
			$config['field_disable_name'] = ($name_field !== '1');
		}
		$anon = imgboard_setting_get('board_' . $uri . '_anon_name', '');
		if ($anon !== '') {
			$config['anonymous'] = $anon;
		}
		$cflags = imgboard_setting_get('board_' . $uri . '_country_flags', '');
		if ($cflags !== '') {
			$config['country_flags'] = ($cflags === '1');
		}
		$pids = imgboard_setting_get('board_' . $uri . '_poster_ids', '');
		if ($pids !== '') {
			$config['poster_ids'] = ($pids === '1');
		}
		// Board-wide permanent Staff IDs (SIDs): same ID for a poster across every
		// thread on the board. Enabling SIDs implies showing the ID.
		$sids = imgboard_setting_get('board_' . $uri . '_sids', '');
		if ($sids === '1') {
			$config['imgboard_sids'] = true;
			$config['poster_ids']  = true;
		}
	} catch (\Throwable $e) {
		// DB not ready during an early config load — keep the global defaults.
	}
}

// True if a board is staff-only (hidden from the public, staff-only posting).
function imgboard_is_staff_board(string $uri): bool {
	global $config;
	return in_array($uri, $config['imgboard_staff_boards'] ?? [], true);
}

// Boards a given viewer may see: everyone sees normal boards; only logged-in
// staff see staff-only boards.
function imgboard_visible_boards($mod): array {
	$out = [];
	foreach (listBoards() as $b) {
		if (imgboard_is_staff_board($b['uri']) && !$mod) {
			continue;
		}
		$out[] = $b;
	}
	return $out;
}

/* ----------------------------------------------------------------------- *
 *  Site killswitch (JSID / Temp-JSID)
 *
 *  State lives in imgboard_settings; a flag file in the web root lets nginx gate
 *  public pages (see docker/nginx/vichan.conf + killswitch.php). mod.php and
 *  post.php stay reachable so staff keep operating through the mod panel.
 *    - jsid : permanent-style shutdown, only Owner can toggle or operate.
 *    - temp : temporary shutdown, any Admin can toggle; any staff can operate.
 * ----------------------------------------------------------------------- */

// Path to the flag file nginx checks. The web root is this process's cwd
// (bootstrap chdir's to it); fall back to /var/www.
function imgboard_killswitch_flag(): string {
	global $config;
	// root_file is false by default, so use ?: (not ??) to fall back to the cwd,
	// which is the web root (/var/www) during a request.
	$root = ($config['root_file'] ?? false) ?: (getcwd() ?: '/var/www');
	return rtrim($root, '/') . '/killswitch.flag';
}

function imgboard_killswitch_mode(): string {
	$m = imgboard_setting_get('killswitch', 'none');
	return in_array($m, [ 'jsid', 'temp' ], true) ? $m : 'none';
}

function imgboard_killswitch_since(): int {
	return (int) imgboard_setting_get('killswitch_since', '0');
}

// Write or remove the flag file to match the stored mode.
function imgboard_killswitch_sync(): void {
	$flag = imgboard_killswitch_flag();
	$mode = imgboard_killswitch_mode();
	if ($mode !== 'none') {
		@file_put_contents($flag, $mode);
	} else {
		@unlink($flag);
	}
}

function imgboard_killswitch_set(string $mode, ?string $by): void {
	if (!in_array($mode, [ 'none', 'jsid', 'temp' ], true)) {
		$mode = 'none';
	}
	imgboard_setting_set('killswitch', $mode);
	if ($mode !== 'none') {
		imgboard_setting_set('killswitch_since', (string) time());
		imgboard_setting_set('killswitch_by', (string) $by);
	}
	imgboard_killswitch_sync();
}

// Render the control screen for a killswitch kind ('jsid' or 'temp').
function imgboard_killswitch_page(string $kind, $token) {
	global $mod, $config;
	echo mod_page($kind === 'jsid' ? 'JSID' : 'Temp-JSID', 'mod/killswitch.html', [
		'kind'  => $kind,
		'mode'  => imgboard_killswitch_mode(),
		'since' => imgboard_killswitch_since(),
		'by'    => imgboard_setting_get('killswitch_by', ''),
		'token' => $token,
	], $mod);
}

function mod_jsid(Context $ctx) {
	global $mod, $config;
	// JSID is the owner's alone: only Root (the single top role, 40).
	if (!$mod || (int)($mod['real_type'] ?? $mod['type'] ?? 0) !== 40) {
		error($config['error']['noaccess']);
	}
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$new = imgboard_killswitch_mode() === 'jsid' ? 'none' : 'jsid';
		imgboard_killswitch_set($new, $mod['username']);
		modLog($new === 'jsid' ? 'Activated JSID (site down)' : 'Deactivated JSID (site restored)');
		header('Location: ?/jsid', true, $config['redirect_http']);
		return;
	}
	imgboard_killswitch_page('jsid', make_secure_link_token('jsid'));
}

function mod_temp_jsid(Context $ctx) {
	global $mod, $config;
	if (!hasPermission($config['mod']['killswitch_temp'] ?? 30)) {
		error($config['error']['noaccess']);
	}
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$new = imgboard_killswitch_mode() === 'temp' ? 'none' : 'temp';
		imgboard_killswitch_set($new, $mod['username']);
		modLog($new === 'temp' ? 'Activated Temp-JSID (site down)' : 'Deactivated Temp-JSID (site restored)');
		header('Location: ?/temp-jsid', true, $config['redirect_http']);
		return;
	}
	imgboard_killswitch_page('temp', make_secure_link_token('temp-jsid'));
}

/* ----------------------------------------------------------------------- *
 *  Schizo — nuclear site reset
 *
 *  Deletes every board except /meta/ and fires all mods (deletes every mod
 *  account except admin). Owner's alone, exactly like JSID. GET shows a typed
 *  confirmation screen; POST (CSRF-checked, and gated again on the typed
 *  phrase) performs the reset. There is no undo.
 * ----------------------------------------------------------------------- */
// Imgboard: Root (40) and Manager (35) are single-holder roles. When one is
// granted to an account, demote every OTHER holder of that role to Admin (30),
// so only one person is ever Root (the owner) or Manager (second in command).
function imgboard_enforce_singleton_role(int $type, int $keep_id): void {
	if ($type !== 35 && $type !== 40) {
		return;
	}
	$q = prepare("UPDATE ``mods`` SET `type` = 30 WHERE `type` = :t AND `id` <> :keep");
	$q->bindValue(':t', $type, PDO::PARAM_INT);
	$q->bindValue(':keep', $keep_id, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
}

function imgboard_schizo_reset($cache): void {
	global $config, $board;

	// 1. Delete every board except /meta/, reusing mod_edit_board's recipe:
	//    drop its posts table, clear reports/cites/antispam, remove it from the
	//    boards table and from every mod's board list, and nuke its directory.
	foreach (listBoards() as $b) {
		$uri = $b['uri'];
		if ($uri === 'meta') {
			continue;
		}

		query(sprintf('DROP TABLE IF EXISTS ``posts_%s``', $uri)) or error(db_error());

		$q = prepare('DELETE FROM ``reports`` WHERE `board` = :u');
		$q->bindValue(':u', $uri);
		$q->execute() or error(db_error($q));

		$q = prepare('DELETE FROM ``cites`` WHERE `board` = :u OR `target_board` = :u');
		$q->bindValue(':u', $uri);
		$q->execute() or error(db_error($q));

		$q = prepare('DELETE FROM ``antispam`` WHERE `board` = :u');
		$q->bindValue(':u', $uri);
		$q->execute() or error(db_error($q));

		$q = prepare('DELETE FROM ``boards`` WHERE `uri` = :u');
		$q->bindValue(':u', $uri);
		$q->execute() or error(db_error($q));

		rrmdir($uri . '/');
		$cache->delete('board_' . $uri);
	}
	$cache->delete('all_boards');

	// 2. Fire all mods: delete every account except the site owner (Root, 40) and
	//    whoever ran the reset. Any boards left in a kept account's list that
	//    point at a now-deleted board are harmless.
	$acting = (int)($GLOBALS['mod']['id'] ?? 0);
	$q = prepare('DELETE FROM ``mods`` WHERE `type` <> 40 AND `id` <> :acting');
	$q->bindValue(':acting', $acting, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));

	// 3. Wipe /meta/ (the only board left) down to only its stickied+locked
	//    threads. Everything else goes: every thread that isn't BOTH sticky and
	//    locked (OP + its replies), plus every reply in the surviving threads,
	//    leaving just those OPs. OPs are `thread IS NULL`; sticky/locked live on
	//    the OP row.
	if (openBoard('meta')) {
		// Threads that aren't both sticky and locked -> delete OP (takes replies).
		$q = query("SELECT `id` FROM ``posts_meta`` WHERE `thread` IS NULL AND NOT (`sticky` = 1 AND `locked` = 1)") or error(db_error());
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			deletePost($id, false, false);
		}
		// Surviving sticky+locked threads: drop their replies, keep only the OP.
		$q = query("SELECT `id` FROM ``posts_meta`` WHERE `thread` IS NULL") or error(db_error());
		$survivors = $q->fetchAll(PDO::FETCH_COLUMN);
		$q = query("SELECT `id` FROM ``posts_meta`` WHERE `thread` IS NOT NULL") or error(db_error());
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			deletePost($id, false, false);
		}
		foreach ($survivors as $id) {
			buildThread($id);
		}
		buildIndex();
	}

	// 4. Rebuild all themes against the reduced board set.
	Vichan\Functions\Theme\rebuild_themes('boards');

	modLog('SCHIZO: reset site to /meta/ (sticky+locked threads only) and fired all mods', false);
}

function mod_schizo(Context $ctx) {
	global $mod, $config;

	// Schizo is open to Manager (35) and Root (40) — see $config['mod']['schizo'].
	if (!$mod || !hasPermission($config['mod']['schizo'] ?? 35)) {
		error($config['error']['noaccess']);
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		// Second gate on top of the CSRF token: the typed phrase must match.
		if (($_POST['confirm'] ?? '') !== 'SCHIZO') {
			error('Reset not confirmed — type SCHIZO exactly to proceed.');
		}
		imgboard_schizo_reset($ctx->get(\Vichan\Data\Driver\CacheDriver::class));
		header('Location: ?/dashboard', true, $config['redirect_http']);
		return;
	}

	echo mod_page('Schizo', 'mod/schizo.html', [
		'token' => make_secure_link_token('schizo'),
	], $mod);
}

/* ----------------------------------------------------------------------- *
 *  CattyWipe — per-board wipe that keeps only stickied threads
 * ----------------------------------------------------------------------- */

function imgboard_cattywipe($board): void {
	if (!openBoard($board)) {
		error('Board not found.');
	}

	// Delete every non-stickied thread (OP + all its replies). OPs are
	// `thread IS NULL`; the sticky flag lives on the OP row, and deletePost on
	// an OP takes its replies with it. Rebuild once at the end rather than per
	// delete. Stickied threads (and their replies) are left untouched.
	$q = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL AND `sticky` = 0", $board)) or error(db_error());
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
		deletePost($id, false, false);
	}

	buildIndex();
	Vichan\Functions\Theme\rebuild_themes('post-delete');

	modLog('CATTYWIPE: wiped /' . $board . '/ (kept stickied threads)', false);
}

function mod_cattywipe(Context $ctx, $board) {
	global $mod, $config;

	// Admin only (see $config['mod']['cattywipe'] in inc/config.php).
	if (!hasPermission($config['mod']['cattywipe'], $board)) {
		error($config['error']['noaccess']);
	}
	if (!openBoard($board)) {
		error('Board not found.');
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		// secure_POST has already verified the CSRF token.
		imgboard_cattywipe($board);
		header('Location: ?/' . $board . '/' . $config['file_index'], true, $config['redirect_http']);
		return;
	}

	echo mod_page('CattyWipe /' . $board . '/', 'mod/cattywipe.html', [
		'board' => $board,
		'token' => make_secure_link_token($board . '/cattywipe'),
	], $mod);
}

/* ----------------------------------------------------------------------- *
 *  Admin-editable moderation log
 * ----------------------------------------------------------------------- */

// Ensure modlogs has a surrogate id (so entries can be edited individually) and
// normalise the old verbose approve labels to the clean ones. Idempotent.
function imgboard_modlog_setup(): void {
	global $config;
	$db = $config['db']['database'];
	$q = prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = :db AND table_name = 'modlogs' AND column_name = 'id'");
	$q->bindValue(':db', $db);
	$q->execute() or error(db_error($q));
	if (!$q->fetch()) {
		query("ALTER TABLE ``modlogs`` ADD COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST") or error(db_error());
	}
	// Collapse the old verbose labels to the clean action names.
	query("UPDATE ``modlogs`` SET `text` = 'Approved file' WHERE `text` LIKE 'Approved pending file(s)%'");
	query("UPDATE ``modlogs`` SET `text` = 'Approved link' WHERE `text` LIKE 'Approved pending link(s)%'");
	query("UPDATE ``modlogs`` SET `text` = 'Rejected + Hashbanned file' WHERE `text` LIKE 'Hash-banned file(s) from rejected post%'");
	query("UPDATE ``modlogs`` SET `text` = 'Rejected + Banned link' WHERE `text` LIKE 'Blocked link(s) from rejected post%'");
	query("UPDATE ``modlogs`` SET `text` = 'Rejected file' WHERE `text` LIKE 'Rejected pending post%'");
}

function mod_modlog_edit(Context $ctx) {
	global $mod, $config;
	if (!hasPermission($config['mod']['edit_modlog']))
		error($config['error']['noaccess']);
	imgboard_modlog_setup();

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (isset($_POST['delete_id'])) {
			$q = prepare("DELETE FROM ``modlogs`` WHERE `id` = :id");
			$q->bindValue(':id', (int) $_POST['delete_id'], PDO::PARAM_INT);
			$q->execute() or error(db_error($q));
			modLog('Deleted a moderation log entry');
		} elseif (isset($_POST['edit_id'])) {
			$q = prepare("UPDATE ``modlogs`` SET `text` = :t WHERE `id` = :id");
			$q->bindValue(':t', (string) ($_POST['text'] ?? ''));
			$q->bindValue(':id', (int) $_POST['edit_id'], PDO::PARAM_INT);
			$q->execute() or error(db_error($q));
			modLog('Edited a moderation log entry');
		} elseif (isset($_POST['add'])) {
			$text = trim((string) ($_POST['new_text'] ?? ''));
			if ($text !== '') {
				$boardv = trim((string) ($_POST['new_board'] ?? ''));
				$when = !empty($_POST['new_time']) ? strtotime((string) $_POST['new_time']) : time();
				if ($when === false) {
					$when = time();
				}
				$q = prepare("INSERT INTO ``modlogs`` (`mod`, `ip`, `board`, `time`, `text`) VALUES (:mod, :ip, :board, :time, :text)");
				$q->bindValue(':mod', $mod['id'], PDO::PARAM_INT);
				$q->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
				$q->bindValue(':board', $boardv !== '' ? $boardv : null);
				$q->bindValue(':time', $when, PDO::PARAM_INT);
				$q->bindValue(':text', $text);
				$q->execute() or error(db_error($q));
				modLog('Added a moderation log entry');
			}
		}
		header('Location: ?/modlog-edit', true, $config['redirect_http']);
		return;
	}

	$rows = query("SELECT `id`, `mod`, `board`, `time`, `text` FROM ``modlogs`` ORDER BY `time` DESC, `id` DESC LIMIT 300") or error(db_error());
	echo mod_page('Edit moderation log', 'mod/modlog_edit.html', [
		'logs'  => $rows->fetchAll(PDO::FETCH_ASSOC),
		'token' => make_secure_link_token('modlog-edit'),
	], $mod);
}

// Drop the cached config for a board so the next load picks up new overrides.
function imgboard_flush_board_config(string $uri): void {
	cache::delete('config_' . $uri);
	@unlink('tmp/cache/cache_config.php');
}

// The three per-board display options (name field, country flags, poster IDs), shared by the
// new/edit board forms and ?/board-names. They live in the same imgboard_settings keys, so the
// two places always agree.
function imgboard_board_options_get(string $uri): array {
	return [
		'name_field'    => imgboard_setting_get('board_' . $uri . '_name_field', '0') === '1',
		'country_flags' => imgboard_setting_get('board_' . $uri . '_country_flags', '0') === '1',
		'poster_ids'    => imgboard_setting_get('board_' . $uri . '_poster_ids', '0') === '1',
		'ppa'           => imgboard_ppa_enabled($uri),
	];
}

// Is this a country-flag board? Cached per request: it is asked once per post while pages render.
function imgboard_flag_board($uri): bool {
	static $cache = [];
	$uri = (string)$uri;
	if ($uri === '') return false;
	if (!isset($cache[$uri])) {
		$cache[$uri] = imgboard_setting_get('board_' . $uri . '_country_flags', '0') === '1';
	}
	return $cache[$uri];
}

// Save them from a submitted board form (checkbox absent = off). Only for staff who may edit
// board names; anyone else leaves them untouched.
function imgboard_board_options_save(string $uri, array $post): void {
	global $config;
	if (!hasPermission($config['mod']['edit_board_names'])) return;
	$nf  = isset($post['opt_name_field'])    ? '1' : '0';
	$cf  = isset($post['opt_country_flags']) ? '1' : '0';
	$pid = isset($post['opt_poster_ids'])    ? '1' : '0';
	imgboard_setting_set('board_' . $uri . '_name_field', $nf);
	imgboard_setting_set('board_' . $uri . '_country_flags', $cf);
	imgboard_setting_set('board_' . $uri . '_poster_ids', $pid);
	imgboard_flush_board_config($uri);
	modLog('Set display options for /' . $uri . '/: name field ' . ($nf ? 'on' : 'off') .
		', country flags ' . ($cf ? 'on' : 'off') . ', poster IDs ' . ($pid ? 'on' : 'off'));

	// Post Pending approval is a separate (ppa_toggle) permission and lives in the
	// shared ppa_boards list, so handle it here only when the saver may toggle it.
	if (isset($post['opt_ppa_present']) && hasPermission($config['mod']['ppa_toggle'] ?? 30)) {
		$all  = array_column(listBoards(), 'uri');
		$list = imgboard_setting_get('ppa', '0') === '1' ? $all : imgboard_ppa_boards();
		if (imgboard_setting_get('ppa', '0') === '1') { imgboard_setting_set('ppa', '0'); } // retire the sitewide switch
		$want = isset($post['opt_ppa']);
		$had  = in_array($uri, $list, true);
		$list = $want ? array_merge($list, [$uri]) : array_values(array_diff($list, [$uri]));
		imgboard_setting_set('ppa_boards', implode(',', array_values(array_unique($list))));
		if ($want !== $had) {
			modLog('Turned Post Pending approval ' . ($want ? 'ON' : 'OFF') . ' for /' . $uri . '/', $uri);
		}
	}
}

// Rebuild a board's static pages so the post/reply forms reflect the new
// name-field setting immediately.
function imgboard_rebuild_board(string $uri): void {
	global $config;
	if (!openBoard($uri)) {
		return;
	}
	$config['try_smarter'] = false;
	buildIndex();
	$q = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL", $uri));
	if ($q) {
		while ($t = $q->fetch(PDO::FETCH_ASSOC)) {
			openBoard($uri);
			buildThread($t['id']);
		}
	}
}

function mod_board_names(Context $ctx) {
	global $mod, $config;
	if (!hasPermission($config['mod']['edit_board_names']))
		error($config['error']['noaccess']);

	$saved = false;
	if (isset($_POST['save'])) {
		foreach (listBoards() as $b) {
			$uri = $b['uri'];
			$nf  = isset($_POST['name_field_' . $uri]) ? '1' : '0';
			$an  = isset($_POST['anon_name_' . $uri]) ? trim($_POST['anon_name_' . $uri]) : '';
			$cf  = isset($_POST['country_flags_' . $uri]) ? '1' : '0';
			$pid = isset($_POST['poster_ids_' . $uri]) ? '1' : '0';
			imgboard_setting_set('board_' . $uri . '_name_field', $nf);
			imgboard_setting_set('board_' . $uri . '_anon_name', $an);
			imgboard_setting_set('board_' . $uri . '_country_flags', $cf);
			imgboard_setting_set('board_' . $uri . '_poster_ids', $pid);
			imgboard_flush_board_config($uri);
			imgboard_rebuild_board($uri);
		}
		modLog('Updated per-board name settings');
		$saved = true;
	}

	$boards = [];
	foreach (listBoards() as $b) {
		$boards[] = [
			'uri'           => $b['uri'],
			'title'         => $b['title'],
			'name_field'    => imgboard_setting_get('board_' . $b['uri'] . '_name_field', '0') === '1',
			'anon_name'     => imgboard_setting_get('board_' . $b['uri'] . '_anon_name', ''),
			'country_flags' => imgboard_setting_get('board_' . $b['uri'] . '_country_flags', '0') === '1',
			'poster_ids'    => imgboard_setting_get('board_' . $b['uri'] . '_poster_ids', '0') === '1',
		];
	}

	echo mod_page('Board names', 'mod/board_names.html', [
		'boards'       => $boards,
		'default_anon' => is_array($config['anonymous']) ? implode(', ', $config['anonymous']) : $config['anonymous'],
		'saved'        => $saved,
		'token'        => make_secure_link_token('board-names'),
	], $mod);
}

/* ----------------------------------------------------------------------- *
 *  Nameroll (?/nameroll)
 *
 *  A list of names (one per line, imgboard_settings 'nameroll'). Each anonymous post on a
 *  public board WITHOUT a name field gets a random name from it, unless the post contains
 *  a ">" anywhere. Staff posts and staff boards are never rolled. Empty list = off.
 * ----------------------------------------------------------------------- */

const IMGBOARD_NAMEROLL_MAX_LEN     = 35;   // vichan's own name length limit
const IMGBOARD_NAMEROLL_MAX_ENTRIES = 500;

// Text -> clean list of names: one per line, blanks and repeats dropped, control characters
// and '#' (tripcode marker) removed, each cut to the name limit.
function imgboard_nameroll_parse(string $text): array {
	$names = [];
	foreach (preg_split('/\R/u', $text) as $line) {
		$n = preg_replace('/[\x00-\x1f\x7f#]+/u', '', $line);
		$n = trim(preg_replace('/\s+/u', ' ', (string)$n));
		$n = mb_substr($n, 0, IMGBOARD_NAMEROLL_MAX_LEN);
		if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
		if (count($names) >= IMGBOARD_NAMEROLL_MAX_ENTRIES) break;
	}
	return $names;
}

// The name to give this post, or null to leave the normal name alone.
function imgboard_nameroll_pick(string $body, string $board_uri): ?string {
	if (strpos($body, '>') !== false) return null;            // ">" anywhere opts out
	if (function_exists('imgboard_is_staff_board') && imgboard_is_staff_board($board_uri)) return null;
	$names = imgboard_nameroll_parse((string)imgboard_setting_get('nameroll', ''));
	return $names ? $names[random_int(0, count($names) - 1)] : null;
}

function mod_nameroll(Context $ctx) {
	global $mod, $config;
	if (!hasPermission($config['mod']['edit_board_names']))
		error($config['error']['noaccess']);

	$saved = false;
	if (isset($_POST['save'])) {
		$list = imgboard_nameroll_parse((string)($_POST['names'] ?? ''));
		imgboard_setting_set('nameroll', implode("\n", $list));
		modLog('Updated the nameroll (' . count($list) . ' names)');
		$saved = true;
	}

	$current = imgboard_nameroll_parse((string)imgboard_setting_get('nameroll', ''));
	echo mod_page('Nameroll', 'mod/nameroll.html', [
		'names'       => implode("\n", $current),
		'count'       => count($current),
		'saved'       => $saved,
		'max_len'     => IMGBOARD_NAMEROLL_MAX_LEN,
		'max_entries' => IMGBOARD_NAMEROLL_MAX_ENTRIES,
		'token'       => make_secure_link_token('nameroll'),
	], $mod);
}

/* ----------------------------------------------------------------------- *
 *  Enforcement (called from post.php)
 * ----------------------------------------------------------------------- */

/* ----------------------------------------------------------------------- *
 *  Cloudflare Turnstile (new-thread captcha)
 *
 *  Keys come from the environment (set in inc/instance-config.php). When both
 *  are present the widget is shown on the new-thread form and verified in
 *  post.php for OPs only.
 * ----------------------------------------------------------------------- */

// True for requests that arrived through the Tor/I2P front door (docker/nginx/onion.conf).
// nginx sets IMGBOARD_HS itself and only trusts the marker from 127.0.0.1, so a clearnet
// client cannot fake it. Those pages run with no JavaScript, so they use the image
// captcha instead of Turnstile.
function imgboard_is_hs(): bool {
	return ($_SERVER['IMGBOARD_HS'] ?? '0') === '1';
}

// Label for a stored poster IP that is a hidden-service pseudo-address (set by
// docker/nginx/onion.conf): Tor uses fdd0::/16, I2P uses fdd1::/16.
function imgboard_hs_label($ip): string {
	$ip = strtolower((string)$ip);
	if (strncmp($ip, 'fdd0:', 5) === 0) return 'Tor';
	if (strncmp($ip, 'fdd1:', 5) === 0) return 'I2P';
	return '';
}

function imgboard_turnstile_enabled(): bool {
	global $config;
	if (imgboard_is_hs()) return false;
	return !empty($config['imgboard_turnstile']['sitekey'])
		&& !empty($config['imgboard_turnstile']['secret']);
}

function imgboard_turnstile_verify(string $token, ?string $ip): bool {
	global $config;
	$secret = $config['imgboard_turnstile']['secret'] ?? '';
	if ($secret === '' || $token === '') {
		return false;
	}
	$post = http_build_query([
		'secret'   => $secret,
		'response' => $token,
		'remoteip' => $ip ?? '',
	]);
	$ctx = stream_context_create(['http' => [
		'method'        => 'POST',
		'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
		'content'       => $post,
		'timeout'       => 5,
		'ignore_errors' => true,
	]]);
	$raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
	if ($raw === false) {
		return false; // network failure: fail closed for OPs
	}
	$data = json_decode($raw, true);
	return is_array($data) && !empty($data['success']);
}

/* ----------------------------------------------------------------------- *
 *  VPN / proxy detection (mod indicator next to the IP)
 *
 *  Uses proxycheck.io. Results are cached per IP in imgboard_vpn_cache so each
 *  address is looked up at most once per TTL. Toggle with $config['imgboard_vpn']
 *  (set in inc/instance-config.php); an optional API key raises the free quota.
 *  NOTE: this sends the poster's IP to proxycheck.io.
 * ----------------------------------------------------------------------- */

function imgboard_vpn_cache_table(): string {
	imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``imgboard_vpn_cache`` (
		`ip` varchar(45) CHARACTER SET ascii NOT NULL,
		`is_vpn` tinyint(1) NOT NULL DEFAULT 0,
		`time` int(11) NOT NULL,
		PRIMARY KEY (`ip`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());
	return 'imgboard_vpn_cache';
}

function imgboard_ip_is_vpn(string $ip): bool {
	global $config;
	if (empty($config['imgboard_vpn']['enabled']) || $ip === '') {
		return false;
	}
	// Never flag private / loopback addresses.
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
		return false;
	}

	$ttl = (int)($config['imgboard_vpn']['ttl'] ?? 604800); // 7 days
	imgboard_vpn_cache_table();
	$q = prepare("SELECT `is_vpn`, `time` FROM ``imgboard_vpn_cache`` WHERE `ip` = :ip");
	$q->bindValue(':ip', $ip);
	$q->execute() or error(db_error($q));
	if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
		if ((time() - (int)$r['time']) < $ttl) {
			return (bool)$r['is_vpn'];
		}
	}

	$is_vpn = imgboard_vpn_lookup($ip) ? 1 : 0;
	$ins = prepare("REPLACE INTO ``imgboard_vpn_cache`` (`ip`, `is_vpn`, `time`) VALUES (:ip, :v, :t)");
	$ins->bindValue(':ip', $ip);
	$ins->bindValue(':v', $is_vpn, PDO::PARAM_INT);
	$ins->bindValue(':t', time(), PDO::PARAM_INT);
	$ins->execute() or error(db_error($ins));
	return (bool)$is_vpn;
}

// Live lookup against proxycheck.io. Returns true if the IP is a proxy/VPN.
function imgboard_vpn_lookup(string $ip): bool {
	global $config;
	$key = $config['imgboard_vpn']['api_key'] ?? '';
	$url = 'https://proxycheck.io/v2/' . rawurlencode($ip) . '?vpn=1&risk=1'
		. ($key !== '' ? '&key=' . rawurlencode($key) : '');
	$ctx = stream_context_create(['http' => [
		'method'        => 'GET',
		'timeout'       => 4,
		'ignore_errors' => true,
	]]);
	$raw = @file_get_contents($url, false, $ctx);
	if ($raw === false) {
		return false; // fail open: don't block or flag on lookup failure
	}
	$data = json_decode($raw, true);
	if (!is_array($data) || ($data['status'] ?? '') !== 'ok' || !isset($data[$ip])) {
		return false;
	}
	return (($data[$ip]['proxy'] ?? 'no') === 'yes');
}

function cache_delete_blocklist(string $type): void {
	cache::delete('imgboard_blocklist_' . $type);
}

function imgboard_blocklist_cached(string $type): array {
	$key = 'imgboard_blocklist_' . $type;
	global $config;
	if ($config['cache']['enabled'] && ($v = cache::get($key)) !== false) {
		return $v;
	}
	$v = imgboard_blocklist_active($type);
	if ($config['cache']['enabled']) {
		cache::set($key, $v);
	}
	return $v;
}

// If the body contains an active blocked word or link, return the error
// message to show; otherwise null. (The caller cleans up any upload first.)
function imgboard_blocklist_match(string $body_nomarkup): ?string {
	foreach (imgboard_blocklist_cached('words') as $w) {
		if ($w !== '' && mb_stripos($body_nomarkup, $w) !== false) {
			return 'Your post contains a blocked word.';
		}
	}
	foreach (imgboard_blocklist_cached('links') as $l) {
		if ($l !== '' && mb_stripos($body_nomarkup, $l) !== false) {
			return 'Your post contains a blocked link.';
		}
	}
	return null;
}

/* ----------------------------------------------------------------------- *
 *  Staff account hardening
 *
 *  1. Login brute-force throttle: after a handful of failed logins from one IP
 *     within a window, that IP is locked out for a cooldown. Keyed on
 *     REMOTE_ADDR, the same real client IP the ban/post system already uses.
 *  2. Minimum password strength: enforced wherever a mod sets or changes a
 *     password, but NOT on the login auto-rehash path, so an existing short
 *     password keeps working until its owner changes it.
 * ----------------------------------------------------------------------- */

const IMGBOARD_LOGIN_MAX_FAILS = 5;    // failed logins allowed within the window
const IMGBOARD_LOGIN_WINDOW    = 900;  // 15 min: span over which fails accumulate
const IMGBOARD_LOGIN_LOCKOUT   = 900;  // 15 min: lockout once the threshold trips
const IMGBOARD_MIN_PASSWORD_LEN = 10;  // minimum staff password length

function imgboard_login_attempts_table(): string {
	imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``imgboard_login_attempts`` (
		`ip` varchar(45) CHARACTER SET ascii NOT NULL,
		`fails` int(11) NOT NULL DEFAULT 0,
		`first_fail` int(11) NOT NULL DEFAULT 0,
		`locked_until` int(11) NOT NULL DEFAULT 0,
		PRIMARY KEY (`ip`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());
	return 'imgboard_login_attempts';
}

const IMGBOARD_LOGIN_USER_MAX_FAILS = 15; // fails per username (any address) before that username is locked for a while

// Throttle key for a client: IPv6 clients share one key per /64 so rotating addresses in a
// range (or the Tor/I2P mirrors' client-chosen pseudo-IPs, via the username key below) does
// not reset the count.
function imgboard_login_ip_key(string $ip): string {
	return function_exists('imgboard_ip_lock_key') ? imgboard_ip_lock_key($ip) : $ip;
}

// Throttle key for a username. Hashed so it fits the ip column and never stores the name.
function imgboard_login_user_key(string $username): string {
	return 'u:' . substr(sha1(strtolower($username)), 0, 16);
}

// Seconds left on an active lockout for this IP, or 0 if it may try now.
function imgboard_login_locked_for(string $ip): int {
	if ($ip === '') return 0;
	imgboard_login_attempts_table();
	$q = prepare("SELECT `locked_until` FROM ``imgboard_login_attempts`` WHERE `ip` = :ip");
	$q->bindValue(':ip', $ip);
	$q->execute() or error(db_error($q));
	$r = $q->fetch(PDO::FETCH_ASSOC);
	if (!$r) return 0;
	$rem = (int)$r['locked_until'] - time();
	return $rem > 0 ? $rem : 0;
}

// Record a failed login; trip a lockout once too many fails land in the window.
function imgboard_login_record_fail(string $ip, int $max = IMGBOARD_LOGIN_MAX_FAILS): void {
	if ($ip === '') return;
	imgboard_login_attempts_table();
	$now = time();
	$q = prepare("SELECT `fails`, `first_fail` FROM ``imgboard_login_attempts`` WHERE `ip` = :ip");
	$q->bindValue(':ip', $ip);
	$q->execute() or error(db_error($q));
	$r = $q->fetch(PDO::FETCH_ASSOC);
	if (!$r || ($now - (int)$r['first_fail']) > IMGBOARD_LOGIN_WINDOW) {
		$fails = 1; $first = $now;           // start a fresh window
	} else {
		$fails = (int)$r['fails'] + 1; $first = (int)$r['first_fail'];
	}
	$locked_until = ($fails >= $max) ? ($now + IMGBOARD_LOGIN_LOCKOUT) : 0;
	$ins = prepare("REPLACE INTO ``imgboard_login_attempts`` (`ip`, `fails`, `first_fail`, `locked_until`) VALUES (:ip, :f, :ff, :lu)");
	$ins->bindValue(':ip', $ip);
	$ins->bindValue(':f', $fails, PDO::PARAM_INT);
	$ins->bindValue(':ff', $first, PDO::PARAM_INT);
	$ins->bindValue(':lu', $locked_until, PDO::PARAM_INT);
	$ins->execute() or error(db_error($ins));
}

// Clear the counter after a successful login.
function imgboard_login_clear(string $ip): void {
	if ($ip === '') return;
	imgboard_login_attempts_table();
	$q = prepare("DELETE FROM ``imgboard_login_attempts`` WHERE `ip` = :ip");
	$q->bindValue(':ip', $ip);
	$q->execute();
}

// Reject a too-weak staff password. Call before hashing a mod-supplied password.
function imgboard_require_strong_password(string $pw): void {
	if (mb_strlen($pw) < IMGBOARD_MIN_PASSWORD_LEN) {
		error('Password must be at least ' . IMGBOARD_MIN_PASSWORD_LEN . ' characters long.');
	}
}

/* ----------------------------------------------------------------------- *
 *  Browser-fingerprint detail (?/fp/<hash>)
 *  Shows the full un-hashed signal breakdown, trust score, detected spoof
 *  tells and every sighting for a fingerprint. Gated on show_ip, so only
 *  staff who may already see IPs can read it.
 * ----------------------------------------------------------------------- */

// Turn a decoded component value into a readable one-line string for staff.
function imgboard_fp_flatten($value): string {
	if (is_bool($value)) return $value ? 'true' : 'false';
	if (is_null($value)) return '(null)';
	if (is_scalar($value)) return (string)$value;
	// arrays / objects: compact JSON, trimmed so one freak signal can't flood the page
	$s = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return mb_strlen($s) > 2000 ? mb_substr($s, 0, 2000) . '...' : $s;
}

// "View as a lower role": set or clear the imgboard_viewas cookie. The cookie is
// only ever honoured when it names a rank strictly below the viewer's real one
// (see check_login), so this can never escalate — only preview downward.
function mod_imgboard_viewas(Context $ctx, $to) {
	global $mod, $config;
	if (!$mod) error($config['error']['noaccess']);
	$real = isset($mod['real_type']) ? $mod['real_type'] : $mod['type'];
	$secure = (isset($config['cookies']['secure_login_only']) && $config['cookies']['secure_login_only'] === 1);
	if ($to === 'off') {
		setcookie('imgboard_viewas', '', time() - 3600, '/', '', $secure, false);
	} elseif ($to === 'user') {
		// Regular-user preview: any signed-in staff member may (it only removes access).
		setcookie('imgboard_viewas', 'user', 0, '/', '', $secure, false);
		header('Location: /', true, $config['redirect_http']);
		return;
	} else {
		$as = (int)$to;
		// Only a real group strictly below the viewer's true rank (never 99).
		if (isset($config['mod']['groups'][$as]) && $as < $real && $as != 99) {
			setcookie('imgboard_viewas', (string)$as, 0, '/', '', $secure, false);
		}
	}
	header('Location: ?/dashboard', true, $config['redirect_http']);
}

// Janitor-tier poster number: a salted, non-reversible number derived from the
// best identifier available (fingerprint, else cookie, else IP). Lets janitors
// tell posters apart without exposing the fingerprint, cookie or IP itself.
function imgboard_fp_jannie($value): string {
	global $config;
	$value = (string)$value;
	if ($value === '') return '--------';
	$h = sha1(($config['cookies']['salt'] ?? '') . '|jannie|' . $value);
	return sprintf('%08u', hexdec(substr($h, 0, 7)) % 100000000);
}

// Mediator-tier super-hashed IP: a double, heavily salted hash of the IP, so a
// mediator can recognise the same source across posts without seeing the
// address. Deliberately distinct from every other identifier token.
function imgboard_fp_superip($ip): string {
	global $config;
	$ip = (string)$ip;
	if ($ip === '') return '------------';
	$salt = $config['cookies']['salt'] ?? '';
	return substr(hash('sha256', $salt . '|superip|' . hash('sha256', $salt . $ip)), 0, 12);
}

// Alt / ban-evasion signal: how many distinct cookie ids and IPs share a
// fingerprint. One device posting under several cookies (cleared cookies /
// multiple "identities") or from several IPs is the classic evasion tell.
// Result is cached per request so a page full of the same fp hits the DB once.
function imgboard_fp_alt($hash) {
	static $cache = [];
	$hash = preg_replace('/[^a-f0-9]/', '', (string)$hash);
	if ($hash === '') return ['alt' => false, 'cookies' => 0, 'ips' => 0];
	if (isset($cache[$hash])) return $cache[$hash];

	$res = ['alt' => false, 'cookies' => 0, 'ips' => 0];
	try {
		$q = prepare("SELECT COUNT(DISTINCT NULLIF(`cookie_id`, '')) AS c, COUNT(DISTINCT `ip`) AS i
			FROM ``imgboard_fp`` WHERE `stable_hash` = :h OR `fp_hash` = :h");
		$q->bindValue(':h', $hash);
		$q->execute();
		if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
			$res['cookies'] = (int)$r['c'];
			$res['ips']     = (int)$r['i'];
			$res['alt']     = ($res['cookies'] > 1 || $res['ips'] > 1);
		}
	} catch (Exception $e) { /* table may not exist yet; treat as no data */ }
	return $cache[$hash] = $res;
}

function mod_imgboard_fp_view(Context $ctx, $hash) {
	global $mod;
	$config = $ctx->get('config');
	if (!hasPermission($config['mod']['show_ip']))
		error($config['error']['noaccess']);

	$hash = preg_replace('/[^a-f0-9]/', '', (string)$hash);
	if ($hash === '') error('Invalid fingerprint.');

	// Every sighting that matches this hash (as either the full or stable hash).
	$q = prepare("SELECT * FROM ``imgboard_fp``
		WHERE `stable_hash` = :h OR `fp_hash` = :h
		ORDER BY `last_seen` DESC");
	$q->bindValue(':h', $hash);
	$q->execute() or error(db_error($q));
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);

	if (!$rows) {
		echo mod_page('Fingerprint ' . $hash, 'mod/imgboard_fp.html', [
			'hash' => $hash, 'found' => false,
		], $mod);
		return;
	}

	$latest = $rows[0];

	// Distinct identifiers seen with this fingerprint — the bridge back to past
	// posts: each cookie id / hwid links to a post search.
	$cookie_ids = []; $hwids = []; $ips = [];
	foreach ($rows as $r) {
		if (!empty($r['cookie_id'])) $cookie_ids[$r['cookie_id']] = true;
		if (!empty($r['hwid']))      $hwids[$r['hwid']] = true;
		if (!empty($r['ip']))        $ips[$r['ip']] = true;
	}

	// The full un-hashed signal breakdown is Admin-only. Mods get the
	// bare-bones view (trust, spoof tells, sightings, alt) without raw signals.
	$can_raw = hasPermission($config['mod']['fp_raw']);
	$components = json_decode($latest['components'] ?? '', true);
	if (!is_array($components)) $components = [];

	$groups = [];
	if ($can_raw) {
		foreach ($components as $group => $vals) {
			$items = [];
			if (is_array($vals)) {
				foreach ($vals as $k => $v) {
					$items[] = ['key' => (string)$k, 'value' => imgboard_fp_flatten($v)];
				}
			} else {
				$items[] = ['key' => '', 'value' => imgboard_fp_flatten($vals)];
			}
			$groups[] = ['name' => (string)$group, 'items' => $items];
		}
	}

	$lies = json_decode($latest['lies'] ?? '', true);
	if (!is_array($lies)) $lies = [];

	// Alt / ban-evasion verdict: one device across several cookies or IPs.
	$alt = (count($cookie_ids) > 1 || count($ips) > 1);

	// First/last seen across every sighting.
	$first_seen = $latest['first_seen']; $last_seen = $latest['last_seen'];
	foreach ($rows as $r) {
		$first_seen = min($first_seen, $r['first_seen']);
		$last_seen  = max($last_seen, $r['last_seen']);
	}

	// Summary digest: the headline features only, limited to what the viewer's
	// role may see. Mods get the moderation highlights; Admins additionally get
	// the standout raw signals (device/GPU/screen/etc). Toggle via &view=summary.
	$summary = (isset($_GET['view']) && $_GET['view'] === 'summary');
	$highlights = [
		['label' => 'Trust',       'value' => ($latest['trust'] === null ? '?' : $latest['trust'] . '/100')],
		['label' => 'Alt / evasion', 'value' => $alt ? ('YES - ' . count($cookie_ids) . ' cookies, ' . count($ips) . ' IPs') : 'no'],
		['label' => 'Spoof tells', 'value' => count($lies) ? (count($lies) . ' (' . implode(', ', array_slice($lies, 0, 4)) . ')') : 'none'],
		['label' => 'Sightings',   'value' => count($rows) . ' (' . count($cookie_ids) . ' cookies, ' . count($hwids) . ' hwids, ' . count($ips) . ' IPs)'],
		['label' => 'First seen',  'value' => date('Y-m-d H:i', (int)$first_seen)],
		['label' => 'Last seen',   'value' => date('Y-m-d H:i', (int)$last_seen)],
	];
	if ($can_raw) {
		$g = function (string $grp, string $key, $d = '') use ($components) {
			return (isset($components[$grp]) && is_array($components[$grp]) && isset($components[$grp][$key]))
				? imgboard_fp_flatten($components[$grp][$key]) : $d;
		};
		$screen = trim($g('screen', 'width') . ' x ' . $g('screen', 'height') . ' @ ' . $g('screen', 'devicePixelRatio') . 'x', ' x@');
		$raw_hl = [
			['label' => 'Platform',    'value' => trim($g('nav', 'platform') . '  ' . $g('uaData', 'platform'))],
			['label' => 'User agent',  'value' => $g('nav', 'userAgent')],
			['label' => 'GPU',         'value' => $g('webgl', 'unmaskedRenderer', $g('webgl', 'renderer'))],
			['label' => 'Screen',      'value' => $screen],
			['label' => 'Timezone',    'value' => $g('locale', 'timezone')],
			['label' => 'Languages',   'value' => $g('nav', 'languages', $g('nav', 'language'))],
			['label' => 'CPU cores',   'value' => $g('nav', 'hardwareConcurrency')],
			['label' => 'Device memory', 'value' => $g('nav', 'deviceMemory')],
			['label' => 'Fonts',       'value' => $g('fonts', 'count')],
			['label' => 'Canvas hash', 'value' => $g('canvas', 'hash')],
			['label' => 'Audio hash',  'value' => $g('audio', 'hash')],
		];
		// Private-browsing / incognito verdict (best-effort heuristic).
		if (isset($components['incognito']) && is_array($components['incognito'])) {
			$ig = $components['incognito'];
			$priv = $ig['private'] ?? null;
			$pv = ($priv === true || $priv === 1 || $priv === 'true') ? 'likely YES'
				: ((($priv === false || $priv === 0 || $priv === 'false')) ? 'no' : 'unknown');
			if (!empty($ig['method'])) { $pv .= ' (' . imgboard_fp_flatten($ig['method']) . ')'; }
			$raw_hl[] = ['label' => 'Private browsing', 'value' => $pv];
		}
		foreach ($raw_hl as $h) { if ($h['value'] !== '' && $h['value'] !== null) $highlights[] = $h; }
	}

	// Plain-text report of exactly the data this role may see (shared with the zip
	// export via imgboard_fp_report_text), used for the Copy button and the &format=txt
	// download. Attach the per-sighting network label for the on-page table too.
	$role_name = $config['mod']['groups'][$mod['type']] ?? ('level ' . $mod['type']);
	$report_text = imgboard_fp_report_text($hash, $can_raw, $role_name);
	foreach ($rows as &$r_net) { $r_net['network'] = imgboard_fp_network($r_net['ip'] ?? ''); } unset($r_net);

	// Download path: serve the report as a plain-text attachment.
	if (isset($_GET['format']) && $_GET['format'] === 'txt') {
		header('Content-Type: text/plain; charset=utf-8');
		header('Content-Disposition: attachment; filename="fingerprint-' . $hash . '.txt"');
		header('X-Content-Type-Options: nosniff');
		echo $report_text;
		return;
	}

	echo mod_page('Fingerprint ' . $hash, 'mod/imgboard_fp.html', [
		'found'        => true,
		'hash'         => $hash,
		'latest'       => $latest,
		'trust'        => $latest['trust'],
		'lies'         => $lies,
		'groups'       => $groups,
		'sightings'    => $rows,
		'cookie_ids'   => array_keys($cookie_ids),
		'hwids'        => array_keys($hwids),
		'ips'          => array_keys($ips),
		'alt'          => $alt,
		'cookie_count' => count($cookie_ids),
		'ip_count'     => count($ips),
		'can_raw'      => $can_raw,
		'summary'      => $summary,
		'highlights'   => $highlights,
		'report_text'  => $report_text,
	], $mod);
}

/* ----------------------------------------------------------------------- *
 *  Mod homepage (?/)
 *
 *  Renders the site front page at mod.php?/ with the mod bar + mod features,
 *  so a logged-in mod lands on the homepage (keeping mod.php in the URL)
 *  instead of the dashboard. The dashboard moved to ?/dashboard.
 * ----------------------------------------------------------------------- */
function mod_home(Context $ctx) {
	global $mod;
	require_once __DIR__ . '/../imgboard-home.php';
	echo imgboard_render_home($mod);
}

/* ----------------------------------------------------------------------- *
 *  User fingerprint data (?/fingerprints)  — Manager+ (config.mod.fp_data)
 *
 *  Every visitor the fingerprint store has seen: posters AND lurkers, since
 *  DTEG (js/dteg/) records a sighting on page load. The list shows one row per
 *  device (stable_hash) with the latest IP / HWID / cookie and counts, linking
 *  to the full ?/fp/<hash> breakdown and a per-visitor .txt. "Download all"
 *  ships a .zip of one formatted .txt per visitor.
 * ----------------------------------------------------------------------- */

// Self-contained plain-text fingerprint report for one hash — same layout as
// the ?/fp/<hash>&format=txt download. Returns null if the hash is unknown.
// Network classification for a sighting IP: Tor / I2P (from the hidden-service
// pseudo-IP prefix) or VPN/Proxy / clear (from the proxycheck.io cache ONLY — a
// fresh lookup is never fired here, so exporting every fingerprint stays cheap and
// never floods proxycheck; an IP never checked at post time shows 'unknown').
function imgboard_fp_network(?string $ip): string {
	static $cache = [];
	$ip = (string)$ip;
	if ($ip === '') return '-';
	if (isset($cache[$ip])) return $cache[$ip];
	$hs = function_exists('imgboard_hs_label') ? imgboard_hs_label($ip) : '';
	if ($hs !== '') return $cache[$ip] = $hs; // Tor / I2P
	$net = 'unknown';
	try {
		$q = prepare("SELECT `is_vpn` FROM ``imgboard_vpn_cache`` WHERE `ip` = :ip");
		$q->bindValue(':ip', $ip);
		if ($q->execute() && ($r = $q->fetch(PDO::FETCH_ASSOC)) !== false) {
			$net = ((int)$r['is_vpn'] === 1) ? 'VPN/Proxy' : 'clear';
		}
	} catch (Exception $e) { /* cache table may not exist yet */ }
	return $cache[$ip] = $net;
}

function imgboard_fp_report_text(string $hash, bool $can_raw, string $role_name): ?string {
	$hash = preg_replace('/[^a-f0-9]/', '', $hash);
	if ($hash === '') return null;

	$q = prepare("SELECT * FROM ``imgboard_fp`` WHERE `stable_hash` = :h OR `fp_hash` = :h ORDER BY `last_seen` DESC");
	$q->bindValue(':h', $hash);
	$q->execute();
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	if (!$rows) return null;
	$latest = $rows[0];

	$cookie_ids = []; $hwids = []; $ips = [];
	foreach ($rows as $r) {
		if (!empty($r['cookie_id'])) $cookie_ids[$r['cookie_id']] = true;
		if (!empty($r['hwid']))      $hwids[$r['hwid']] = true;
		if (!empty($r['ip']))        $ips[$r['ip']] = true;
	}
	$components = json_decode($latest['components'] ?? '', true);
	if (!is_array($components)) $components = [];
	$lies = json_decode($latest['lies'] ?? '', true);
	if (!is_array($lies)) $lies = [];
	$alt = (count($cookie_ids) > 1 || count($ips) > 1);
	$first_seen = $latest['first_seen']; $last_seen = $latest['last_seen'];
	foreach ($rows as $r) { $first_seen = min($first_seen, $r['first_seen']); $last_seen = max($last_seen, $r['last_seen']); }

	$highlights = [
		['label' => 'Trust',         'value' => ($latest['trust'] === null ? '?' : $latest['trust'] . '/100')],
		['label' => 'Alt / evasion', 'value' => $alt ? ('YES - ' . count($cookie_ids) . ' cookies, ' . count($ips) . ' IPs') : 'no'],
		['label' => 'Spoof tells',   'value' => count($lies) ? (count($lies) . ' (' . implode(', ', array_slice($lies, 0, 4)) . ')') : 'none'],
		['label' => 'Sightings',     'value' => count($rows) . ' (' . count($cookie_ids) . ' cookies, ' . count($hwids) . ' hwids, ' . count($ips) . ' IPs)'],
		['label' => 'First seen',    'value' => date('Y-m-d H:i', (int)$first_seen)],
		['label' => 'Last seen',     'value' => date('Y-m-d H:i', (int)$last_seen)],
	];

	$groups = [];
	if ($can_raw) {
		foreach ($components as $group => $vals) {
			$items = [];
			if (is_array($vals)) { foreach ($vals as $k => $v) $items[] = ['key' => (string)$k, 'value' => imgboard_fp_flatten($v)]; }
			else { $items[] = ['key' => '', 'value' => imgboard_fp_flatten($vals)]; }
			$groups[] = ['name' => (string)$group, 'items' => $items];
		}
	}

	$L = []; $sep = str_repeat('=', 64);
	$L[] = $sep;
	$L[] = ' example.com - browser fingerprint report';
	$L[] = $sep;
	$L[] = sprintf('%-15s: %s', 'Fingerprint', $hash);
	$L[] = sprintf('%-15s: %s', 'Generated', gmdate('Y-m-d H:i') . ' UTC');
	$L[] = sprintf('%-15s: %s', 'Viewer role', $role_name);
	$L[] = sprintf('%-15s: %s', 'Scope', $can_raw ? 'full (raw device signals included)' : 'bare-bones (moderation data only)');
	$L[] = '';
	$L[] = '--- Summary ' . str_repeat('-', 52);
	foreach ($highlights as $h) { $L[] = sprintf('%-17s: %s', $h['label'], $h['value']); }
	$L[] = sprintf('%-17s: %s', 'Last user agent', $latest['user_agent']);
	$L[] = '';
	$L[] = '--- Sightings ' . str_repeat('-', 50);
	$L[] = sprintf('%-3s %-16s %-16s %-5s %-5s %-39s %-10s %-13s %s', '#', 'Last seen', 'First seen', 'Hits', 'Trust', 'IP', 'Network', 'Cookie', 'HWID');
	$n = 1;
	foreach ($rows as $r) {
		$L[] = sprintf('%-3d %-16s %-16s %-5s %-5s %-39s %-10s %-13s %s',
			$n++, date('Y-m-d H:i', (int)$r['last_seen']), date('Y-m-d H:i', (int)$r['first_seen']),
			$r['hits'], ($r['trust'] === null ? '?' : $r['trust']),
			($r['ip'] ?: '-'), imgboard_fp_network($r['ip']), substr(($r['cookie_id'] ?: '-'), 0, 12), ($r['hwid'] ?: '-'));
	}
	// Full identifier dump: every distinct hash / cookie / hwid / IP tied to this device.
	$fp_hashes = [];
	foreach ($rows as $r) { if (!empty($r['fp_hash'])) { $fp_hashes[$r['fp_hash']] = true; } }
	$L[] = '';
	$L[] = '--- Identifiers ' . str_repeat('-', 48);
	$L[] = sprintf('%-15s: %s', 'Stable hash', $latest['stable_hash'] ?: '-');
	$L[] = sprintf('%-15s: %s', 'FP hash(es)', $fp_hashes ? implode(', ', array_keys($fp_hashes)) : '-');
	$L[] = sprintf('%-15s: %s', 'Cookies', $cookie_ids ? implode(', ', array_keys($cookie_ids)) : '-');
	$L[] = sprintf('%-15s: %s', 'HWIDs', $hwids ? implode(', ', array_keys($hwids)) : '-');
	$L[] = sprintf('%-15s: %s', 'Last user agent', $latest['user_agent'] ?: '-');
	$L[] = 'IPs (' . count($ips) . '):';
	if ($ips) { foreach (array_keys($ips) as $ipv) { $L[] = sprintf('  %-39s  %s', $ipv, imgboard_fp_network($ipv)); } }
	else { $L[] = '  -'; }
	if ($can_raw) {
		$L[] = ''; $L[] = '--- Raw signal breakdown ' . str_repeat('-', 39);
		foreach ($groups as $grp) {
			$L[] = '[' . $grp['name'] . ']';
			foreach ($grp['items'] as $it) { $L[] = sprintf('  %-18s: %s', ($it['key'] !== '' ? $it['key'] : '(value)'), $it['value']); }
		}
	} else {
		$L[] = ''; $L[] = 'Raw device signals: Admin-only (not included in this report).';
	}
	$L[] = $sep;
	return implode("\n", $L) . "\n";
}

// Identifiers that belong to staff, so the User fingerprint export (the list and the
// zip) never includes a staff member's own device, IP or cookie. Staff IPs come from the
// mod log (every staff action logs the real IP); staff cookie ids / fingerprints come from
// their own posts (mod_id set; the stored fp_hash is the stable device hash). Cached per request.
// shortcut: links staff only via the mod log + their posts; a staff member who only lurks
//           (never logged in, never posted) is not identifiable and would still appear.
function imgboard_staff_fp_exclusions(): array {
	static $cache = null;
	if ($cache !== null) return $cache;
	$ips = []; $cookies = []; $fps = [];
	try {
		if ($q = query("SELECT DISTINCT `ip` FROM ``modlogs`` WHERE `ip` IS NOT NULL AND `ip` <> ''")) {
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $ip) { if ($ip !== '') $ips[$ip] = true; }
		}
	} catch (\Throwable $e) {}
	foreach (listBoards() as $b) {
		try {
			$q = query(sprintf("SELECT DISTINCT `cookie_id`, `ip`, `fp_hash` FROM ``posts_%s`` WHERE `mod_id` IS NOT NULL", $b['uri']));
			if (!$q) continue;
			while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
				if (!empty($r['cookie_id'])) $cookies[$r['cookie_id']] = true;
				if (!empty($r['ip']))        $ips[$r['ip']] = true;
				if (!empty($r['fp_hash']))   $fps[$r['fp_hash']] = true;
			}
		} catch (\Throwable $e) {}
	}
	return $cache = ['ips' => array_keys($ips), 'cookies' => array_keys($cookies), 'fps' => array_keys($fps)];
}

// A boolean SQL expression (on ``imgboard_fp`` rows) that is true for non-staff sightings.
// Values come from the DB (IPs / hashes), but are $pdo->quote'd anyway. Returns '1' when
// there is nothing to exclude. Filters per sighting, so every sighting of a staff device
// drops out; a non-staff visitor sharing only a staff NAT IP is dropped too (deliberately
// privacy-conservative).
function imgboard_staff_fp_where(): string {
	global $pdo;
	$ex = imgboard_staff_fp_exclusions();
	$inlist = function (array $vals) use ($pdo) { return implode(',', array_map([$pdo, 'quote'], $vals)); };
	$parts = [];
	if ($ex['ips'])     { $parts[] = "(`ip` IS NULL OR `ip` NOT IN (" . $inlist($ex['ips']) . "))"; }
	if ($ex['cookies']) { $parts[] = "(`cookie_id` IS NULL OR `cookie_id` = '' OR `cookie_id` NOT IN (" . $inlist($ex['cookies']) . "))"; }
	if ($ex['fps'])     { $parts[] = "(`stable_hash` NOT IN (" . $inlist($ex['fps']) . ") AND (`fp_hash` IS NULL OR `fp_hash` NOT IN (" . $inlist($ex['fps']) . ")))"; }
	return $parts ? implode(' AND ', $parts) : '1';
}

function mod_imgboard_fp_list(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');
	if (!hasPermission($config['mod']['fp_data'])) error($config['error']['noaccess']);
	$not_staff = imgboard_staff_fp_where();

	$per  = 100;
	$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
	$off  = ($page - 1) * $per;

	$total = 0;
	if ($q = query("SELECT COUNT(DISTINCT `stable_hash`) FROM ``imgboard_fp`` WHERE $not_staff")) {
		$total = (int)$q->fetchColumn();
	}
	$pages = max(1, (int)ceil($total / $per));

	// One row per visitor (stable_hash): latest IP/HWID/cookie/fp_hash plus the
	// spread (distinct cookies / IPs / hwids) and activity window.
	$q = prepare("SELECT `stable_hash`,
			SUBSTRING_INDEX(GROUP_CONCAT(`ip`                  ORDER BY `last_seen` DESC SEPARATOR 0x1f), 0x1f, 1) AS `ip`,
			SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(`hwid`,'')      ORDER BY `last_seen` DESC SEPARATOR 0x1f), 0x1f, 1) AS `hwid`,
			SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(`cookie_id`,'') ORDER BY `last_seen` DESC SEPARATOR 0x1f), 0x1f, 1) AS `cookie_id`,
			SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(`fp_hash`,'')   ORDER BY `last_seen` DESC SEPARATOR 0x1f), 0x1f, 1) AS `fp_hash`,
			MAX(`last_seen`) AS `last_seen`, MIN(`first_seen`) AS `first_seen`, SUM(`hits`) AS `hits`,
			COUNT(*) AS `sightings`,
			COUNT(DISTINCT NULLIF(`cookie_id`,'')) AS `cookies`,
			COUNT(DISTINCT `ip`) AS `ips`,
			COUNT(DISTINCT NULLIF(`hwid`,'')) AS `hwids`,
			MAX(`trust`) AS `trust`
		FROM ``imgboard_fp``
		WHERE $not_staff
		GROUP BY `stable_hash`
		ORDER BY `last_seen` DESC
		LIMIT :lim OFFSET :off");
	$q->bindValue(':lim', $per, PDO::PARAM_INT);
	$q->bindValue(':off', $off, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	$visitors = $q->fetchAll(PDO::FETCH_ASSOC);

	echo mod_page('User fingerprint data', 'mod/imgboard_fp_list.html', [
		'visitors' => $visitors,
		'total'    => $total,
		'page'     => $page,
		'pages'    => $pages,
		'per'      => $per,
	], $mod);
}

function mod_imgboard_fp_zip(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');
	if (!hasPermission($config['mod']['fp_data'])) error($config['error']['noaccess']);
	if (!class_exists('ZipArchive')) error('ZIP support is not available on this server.');

	$can_raw   = hasPermission($config['mod']['fp_raw']);
	$role_name = $config['mod']['groups'][$mod['type']] ?? ('level ' . $mod['type']);

	$not_staff = imgboard_staff_fp_where();
	$hashes = [];
	if ($q = query("SELECT `stable_hash` FROM ``imgboard_fp`` WHERE $not_staff GROUP BY `stable_hash` ORDER BY MAX(`last_seen`) DESC")) {
		$hashes = $q->fetchAll(PDO::FETCH_COLUMN);
	}

	$tmp = tempnam(sys_get_temp_dir(), 'fpzip');
	$zip = new ZipArchive();
	if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		@unlink($tmp);
		error('Could not create the zip archive.');
	}
	$count = 0;
	foreach ($hashes as $h) {
		$txt = imgboard_fp_report_text((string)$h, $can_raw, $role_name);
		if ($txt !== null) {
			$zip->addFromString('fingerprint-' . preg_replace('/[^a-f0-9]/', '', (string)$h) . '.txt', $txt);
			$count++;
		}
	}
	$zip->addFromString('README.txt',
		"example.com user fingerprint export\n" .
		"Generated: " . gmdate('Y-m-d H:i') . " UTC by " . $role_name . "\n" .
		"Visitors:  " . $count . "\n" .
		"One .txt per device fingerprint (stable_hash) — posters and lurkers alike.\n");
	$zip->close();

	modLog('Downloaded all user fingerprint data (' . $count . ' visitors, zip)');

	header('Content-Type: application/zip');
	header('Content-Disposition: attachment; filename="imgboard-fingerprints-' . date('Ymd-His') . '.zip"');
	header('Content-Length: ' . filesize($tmp));
	header('X-Content-Type-Options: nosniff');
	readfile($tmp);
	@unlink($tmp);
	exit;
}

/* ----------------------------------------------------------------------- *
 *  Post Pending approval (PPA)
 *  When enabled (admin/root toggle), non-staff posts are held in
 *  imgboard_pending_posts instead of being published, and only appear once a
 *  moderator approves them. Fail-safe: a held post simply does not exist in
 *  the posts table, so it can never leak to the public board.
 * ----------------------------------------------------------------------- */

function imgboard_ppa_table(): string {
	imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``imgboard_pending_posts`` (
		`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`board` varchar(58) NOT NULL,
		`thread` int(11) DEFAULT NULL,
		`is_op` tinyint(1) NOT NULL DEFAULT 0,
		`subject` varchar(100) DEFAULT NULL,
		`name` varchar(35) DEFAULT NULL,
		`body_preview` varchar(255) DEFAULT NULL,
		`num_files` int(11) NOT NULL DEFAULT 0,
		`ip` varchar(45) CHARACTER SET ascii DEFAULT NULL,
		`cookie_id` varchar(64) CHARACTER SET ascii DEFAULT NULL,
		`fp_hash` varchar(32) CHARACTER SET ascii DEFAULT NULL,
		`data` mediumtext NOT NULL,
		`time` int(11) NOT NULL,
		PRIMARY KEY (`id`),
		KEY `board` (`board`),
		KEY `time` (`time`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());
	return 'imgboard_pending_posts';
}

// Boards that hold new posts for approval (comma list in the `ppa_boards` setting). The old
// sitewide `ppa` switch, if still on, means "every board".
function imgboard_ppa_boards(): array {
	if (!function_exists('imgboard_setting_get')) return [];
	return array_values(array_filter(array_map('trim', explode(',', imgboard_setting_get('ppa_boards', '')))));
}

// With a board: does that board hold new posts? Without: is any board holding?
function imgboard_ppa_enabled(?string $board = null): bool {
	if (!function_exists('imgboard_setting_get')) return false;
	if (imgboard_setting_get('ppa', '0') === '1') return true;
	$list = imgboard_ppa_boards();
	return $board === null ? count($list) > 0 : in_array($board, $list, true);
}

function imgboard_ppa_count(): int {
	imgboard_ppa_table();
	$q = query("SELECT COUNT(*) AS c FROM ``imgboard_pending_posts``");
	$r = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
	return $r ? (int)$r['c'] : 0;
}

// Called from post.php right before the post would be inserted. Stashes the
// fully-prepared post (its files are already on disk) and shows the poster an
// "awaiting approval" page, then stops — the post is never inserted or built.
function imgboard_ppa_hold(string $board_uri, array $post): void {
	global $config;
	imgboard_ppa_table();
	$preview = mb_substr(trim((string)($post['body_nomarkup'] ?? '')), 0, 255);
	$is_op = !empty($post['op']);
	$q = prepare("INSERT INTO ``imgboard_pending_posts``
		(`board`,`thread`,`is_op`,`subject`,`name`,`body_preview`,`num_files`,`ip`,`cookie_id`,`fp_hash`,`data`,`time`)
		VALUES (:b,:t,:op,:s,:n,:bp,:nf,:ip,:ck,:fp,:d,:time)");
	$q->bindValue(':b', $board_uri);
	if ($is_op || empty($post['thread'])) { $q->bindValue(':t', null, PDO::PARAM_NULL); }
	else { $q->bindValue(':t', (int)$post['thread'], PDO::PARAM_INT); }
	$q->bindValue(':op', $is_op ? 1 : 0, PDO::PARAM_INT);
	$q->bindValue(':s', ($post['subject'] ?? '') !== '' ? $post['subject'] : null);
	$q->bindValue(':n', ($post['name'] ?? '') !== '' ? $post['name'] : null);
	$q->bindValue(':bp', $preview);
	$q->bindValue(':nf', (int)($post['num_files'] ?? 0), PDO::PARAM_INT);
	$q->bindValue(':ip', $post['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null));
	$q->bindValue(':ck', $post['cookie_id'] ?? null);
	$q->bindValue(':fp', $post['fp_hash'] ?? null);
	$q->bindValue(':d', json_encode($post));
	$q->bindValue(':time', time(), PDO::PARAM_INT);
	$q->execute() or error(db_error($q));

	$idx = $config['root'] . $board_uri . '/' . $config['file_index'];
	$body = '<div class="ppa-held" style="text-align:center;margin:50px auto;max-width:620px;">'
		. '<h2>Post submitted</h2>'
		. '<p>Your post is awaiting staff approval. It will appear on the board once a moderator approves it.</p>'
		. '<p><a href="' . htmlspecialchars($idx, ENT_QUOTES) . '">Return to /' . htmlspecialchars($board_uri, ENT_QUOTES) . '/</a></p>'
		. '</div>';
	die(Element('page.html', [
		'config'    => $config,
		'title'     => 'Awaiting approval',
		'subtitle'  => '',
		'body'      => $body,
		'boardlist' => createBoardlist(false),
	]));
}

// Publish a previously-held post verbatim (on approval). Mirrors the tail of
// post.php: insert, backlinks, bump, and rebuild the thread/index/themes.
function imgboard_pending_commit(string $board_uri, array $post): int {
	global $board, $config, $pdo;
	if (!openBoard($board_uri)) {
		return 0;
	}
	$post['id'] = $id = post($post);
	$post['slug'] = slugify($post);

	if (!empty($post['tracked_cites']) && is_array($post['tracked_cites'])) {
		$rows = [];
		foreach ($post['tracked_cites'] as $cite) {
			$rows[] = '(' . $pdo->quote($board['uri']) . ', ' . (int)$id . ', ' . $pdo->quote($cite[0]) . ', ' . (int)$cite[1] . ')';
		}
		if ($rows) { query('INSERT INTO ``cites`` VALUES ' . implode(', ', $rows)); }
	}

	if (empty($post['op']) && strtolower((string)($post['email'] ?? '')) !== 'sage') {
		bumpThread($post['thread']);
	}

	buildThread(!empty($post['op']) ? $id : $post['thread']);
	buildIndex();
	Vichan\Functions\Theme\rebuild_themes(!empty($post['op']) ? 'post-thread' : 'post', $board['uri']);
	return $id;
}

// The held-posts queue lives on the combined /pending page (mod_pending_all);
// keep ?/ppa working by redirecting there.
function mod_ppa(Context $ctx) {
	global $config;
	header('Location: ?/pending', true, $config['redirect_http']);
}

function mod_ppa_toggle(Context $ctx, $board) {
	global $mod, $config;
	if (!hasPermission($config['mod']['ppa_toggle'] ?? 30))
		error($config['error']['noaccess']);
	$all = array_column(listBoards(), 'uri');
	if (!in_array($board, $all, true))
		error($config['error']['noboard']);
	// The old sitewide switch becomes an explicit list so one board can be switched on its own.
	$list = imgboard_setting_get('ppa', '0') === '1' ? $all : imgboard_ppa_boards();
	if (imgboard_setting_get('ppa', '0') === '1') imgboard_setting_set('ppa', '0');
	$on = !in_array($board, $list, true);
	$list = $on ? array_merge($list, [$board]) : array_values(array_diff($list, [$board]));
	imgboard_setting_set('ppa_boards', implode(',', array_unique($list)));
	modLog('Turned Post Pending approval ' . ($on ? 'ON' : 'OFF') . ' for /' . $board . '/', $board);
	header('Location: ?/pending', true, $config['redirect_http']);
}

function mod_ppa_approve(Context $ctx, $id) {
	global $config;
	if (!hasPermission($config['mod']['ppa_approve'] ?? 10))
		error($config['error']['noaccess']);
	imgboard_ppa_table();
	$id = (int)$id;
	$q = prepare("SELECT `board`,`data` FROM ``imgboard_pending_posts`` WHERE `id` = :id");
	$q->bindValue(':id', $id, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
		$post = json_decode($row['data'], true);
		if (is_array($post)) {
			imgboard_pending_commit($row['board'], $post);
		}
		$d = prepare("DELETE FROM ``imgboard_pending_posts`` WHERE `id` = :id");
		$d->bindValue(':id', $id, PDO::PARAM_INT);
		$d->execute();
		modLog('Approved pending post #' . $id . ' on /' . $row['board'] . '/');
	}
	header('Location: ?/pending', true, $config['redirect_http']);
}

function mod_ppa_reject(Context $ctx, $id) {
	global $config;
	if (!hasPermission($config['mod']['ppa_approve'] ?? 10))
		error($config['error']['noaccess']);
	imgboard_ppa_table();
	$id = (int)$id;
	$q = prepare("SELECT `board`,`data` FROM ``imgboard_pending_posts`` WHERE `id` = :id");
	$q->bindValue(':id', $id, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
		// Delete the files that were already written to disk for the held post.
		$post = json_decode($row['data'], true);
		if (is_array($post) && !empty($post['files']) && is_array($post['files'])) {
			foreach ($post['files'] as $f) {
				if (!empty($f['file_path']) && @is_file($f['file_path'])) { @unlink($f['file_path']); }
				if (!empty($f['thumb_path']) && basename((string)$f['thumb_path']) !== 'spoiler' && @is_file($f['thumb_path'])) { @unlink($f['thumb_path']); }
			}
		}
		$d = prepare("DELETE FROM ``imgboard_pending_posts`` WHERE `id` = :id");
		$d->bindValue(':id', $id, PDO::PARAM_INT);
		$d->execute();
		modLog('Rejected pending post #' . $id . ' on /' . $row['board'] . '/');
	}
	header('Location: ?/pending', true, $config['redirect_http']);
}
