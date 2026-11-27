<?php
/*
 *  imgboard-home.php
 *  ---------------
 *  The example.com front page renderer, shared by:
 *    - index.php        (the public homepage at /)
 *    - the mod ?/ route (mod.php?/ - the same page, but with the mod bar and
 *      mod-only features, so a logged-in mod lands on the homepage while
 *      keeping mod.php in the URL)
 *
 *  Recent Images only shows approved files, so the File Pending approval system
 *  holds on the homepage too: unapproved uploads never surface here.
 */

// Human-readable byte formatter (B / KB / MB / GB / TB) for the Statistics box.
if (!function_exists('imgboard_format_bytes')) {
	function imgboard_format_bytes($bytes) {
		$bytes = max((int)$bytes, 0);
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$pow = $bytes > 0 ? (int)floor(log($bytes, 1024)) : 0;
		$pow = min($pow, count($units) - 1);
		$val = $bytes / pow(1024, $pow);
		return round($val, $pow > 1 ? 2 : 0) . ' ' . $units[$pow];
	}
}

// Public names of the Tor / I2P mirrors, written by the tor and i2pd containers to the
// read-only /mirrors share. Format-checked so a bad file can never inject markup.
function imgboard_hidden_mirrors(): array {
	$out = [];
	$o = trim((string)@file_get_contents('/mirrors/onion'));
	if (preg_match('/^[a-z2-7]{56}\.onion$/', $o)) $out['Tor'] = $o;
	$i = trim((string)@file_get_contents('/mirrors/i2p'));
	if (preg_match('/^[a-z2-7]{52}\.b32\.i2p$/', $i)) $out['I2P'] = $i;
	return $out;
}

function imgboard_render_home($mod = false): string {
	global $config, $board;

	// Detect a logged-in admin so we can offer "Edit rules/news" (needs jail=false).
	$is_admin = $mod && $mod['type'] >= $config['mod']['edit_pages_content'];

	// Staff-only boards (and their posts) are hidden from non-staff on the homepage.
	$boards = function_exists('imgboard_visible_boards') ? imgboard_visible_boards($mod) : listBoards();

	// Per-board stats: PPH (last hour), PPD (last 24h), unique posters, totals.
	$stats_since     = time() - 3600;
	$stats_since_day = time() - 86400;
	foreach ($boards as &$b) {
		$uri = $b['uri'];
		$q = query(sprintf("SELECT COUNT(*) FROM ``posts_%s``", $uri));
		$b['posts'] = $q ? (int)$q->fetchColumn() : 0;
		$q = query(sprintf("SELECT COUNT(DISTINCT `ip`) FROM ``posts_%s``", $uri));
		$b['posters'] = $q ? (int)$q->fetchColumn() : 0;
		$q = prepare(sprintf("SELECT COUNT(*) FROM ``posts_%s`` WHERE `time` >= :t", $uri));
		$q->bindValue(':t', $stats_since, PDO::PARAM_INT);
		$b['pph'] = $q->execute() ? (int)$q->fetchColumn() : 0;
		// PPD: posts in the last 24 hours.
		$q = prepare(sprintf("SELECT COUNT(*) FROM ``posts_%s`` WHERE `time` >= :t", $uri));
		$q->bindValue(':t', $stats_since_day, PDO::PARAM_INT);
		$b['ppd'] = $q->execute() ? (int)$q->fetchColumn() : 0;
		// Active (non-deleted) and total (incl. since-deleted) stored bytes.
		$csize = 0; $tsize = 0;
		if ($q = query(sprintf("SELECT `files` FROM ``posts_%s`` WHERE `files` IS NOT NULL", $uri))) {
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fjson) {
				$fs = json_decode($fjson);
				if (is_array($fs)) {
					foreach ($fs as $f) {
						if (isset($f->size)) {
							$tsize += (int)$f->size;
							if (!isset($f->file) || $f->file !== 'deleted') {
								$csize += (int)$f->size;
							}
						}
					}
				}
			}
		}
		$b['content_size'] = $csize;
		$b['total_content_size'] = $tsize;
	}
	unset($b);

	// The public Boards directory never lists staff-only boards (e.g. /j/).
	$board_table = array_values(array_filter($boards, function ($b) {
		return !(function_exists('imgboard_is_staff_board') && imgboard_is_staff_board($b['uri']));
	}));

	// Busiest boards first: PPH, then PPD, then posters, then total posts (all high to low).
	usort($board_table, function ($a, $b) {
		return [$b['pph'], $b['ppd'], $b['posters'], $b['posts']] <=> [$a['pph'], $a['ppd'], $a['posters'], $a['posts']];
	});

	// Recent native news entries (posted via ?/edit_news) for the News box.
	$latest_news = [];
	if ($q = query("SELECT * FROM ``news`` ORDER BY `id` DESC LIMIT 5")) {
		$latest_news = $q->fetchAll(PDO::FETCH_ASSOC);
	}

	// Site-wide statistics (public boards only).
	$stats = [ 'total_posts' => 0, 'unique_posters' => 0, 'boards' => count($board_table), 'content_size' => 0, 'total_content_size' => 0 ];
	foreach ($board_table as $b) {
		$stats['total_posts']        += (int)$b['posts'];
		$stats['unique_posters']     += (int)$b['posters'];
		$stats['content_size']       += (int)$b['content_size'];
		$stats['total_content_size'] += (int)$b['total_content_size'];
	}
	$stats['content_size_human']       = imgboard_format_bytes($stats['content_size']);
	$stats['total_content_size_human'] = imgboard_format_bytes($stats['total_content_size']);

	$recent_images = [];
	$recent_posts  = [];

	if (!empty($boards)) {
		// --- Recent approved images across all boards ---
		$parts = [];
		foreach ($boards as $b) {
			$parts[] = sprintf(
				"SELECT *, '%s' AS `board` FROM ``posts_%s`` WHERE `files` IS NOT NULL AND `approved` = 1",
				$b['uri'], $b['uri']
			);
		}
		$sql = implode(' UNION ALL ', $parts) . ' ORDER BY `time` DESC LIMIT 12';
		if ($q = query($sql)) {
			while ($p = $q->fetch(PDO::FETCH_ASSOC)) {
				$files = json_decode($p['files']);
				if (!$files || !isset($files[0]) || $files[0]->file === 'deleted' || $files[0]->thumb === 'file') {
					continue;
				}
				openBoard($p['board']);
				$p['thumb'] = $config['uri_thumb'] . $files[0]->thumb;
				$p['link']  = $config['root'] . $board['dir'] . $config['dir']['res'] . link_for($p) . '#' . $p['id'];
				$recent_images[] = $p;
			}
		}

		// --- Latest posts across all boards (text only) ---
		$parts = [];
		foreach ($boards as $b) {
			$parts[] = sprintf("SELECT *, '%s' AS `board` FROM ``posts_%s`` WHERE `approved_link` = 1", $b['uri'], $b['uri']);
		}
		$sql = implode(' UNION ALL ', $parts) . ' ORDER BY `time` DESC LIMIT 15';
		if ($q = query($sql)) {
			while ($p = $q->fetch(PDO::FETCH_ASSOC)) {
				openBoard($p['board']);
				$p['link'] = $config['root'] . $board['dir'] . $config['dir']['res'] . link_for($p) . '#' . $p['id'];
				$text = trim(strip_tags(isset($p['body_nomarkup']) ? $p['body_nomarkup'] : $p['body']));
				$p['snippet'] = $text !== '' ? (mb_substr($text, 0, 90) . (mb_strlen($text) > 90 ? '…' : '')) : '';
				$recent_posts[] = $p;
			}
		}
	}

	return Element('imgboard_home.html', [
		'config'         => $config,
		'boards'         => $boards,
		'board_table'    => $board_table,
		'latest_news'    => $latest_news,
		'stats'          => $stats,
		'recent_images'  => $recent_images,
		'recent_posts'   => $recent_posts,
		'mirrors'        => imgboard_hidden_mirrors(),
		// Mod gets the mod bar (staff boards + dashboard + pending); everyone
		// else gets the plain public bar.
		'boardlist'      => createBoardlist($mod ? true : false),
		'is_admin'       => $is_admin,
		'edit_rules_url' => $config['root'] . $config['file_mod'] . '?/edit-rules',
		'edit_news_url'  => $config['root'] . $config['file_mod'] . '?/edit-news',
	]);
}
