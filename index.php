<?php
/*
 *  example.com front page
 *  -----------------------
 *  A dynamic homepage styled after the classic vichan front page. Rendered
 *  live (not via a static theme) so it works with zero boards and always
 *  reflects the current boards and recent activity. The actual rendering lives
 *  in inc/imgboard-home.php so the mod ?/ route can serve the same page.
 */

require_once 'inc/bootstrap.php';
require_once 'inc/mod/auth.php';
require_once 'inc/imgboard-home.php';

// Detect a logged-in mod/admin (so the page can show the mod bar + edit links).
$ctx = Vichan\build_context($config);
$mod = false;
if (isset($_COOKIE[$config['cookies']['mod']])) {
	check_login($ctx, false);
}

// Anonymous visitors share one cached copy for 30s, so a traffic flood costs one
// round of database queries per 30s instead of one per visit. Staff always get a
// live page. shortcut: 30s staleness on the public homepage; fine for stats/news.
if ($mod) {
	echo imgboard_render_home($mod);
} else {
	$html = $config['cache']['enabled'] ? cache::get('imgboard_home_public') : false;
	if (!$html) {
		$html = imgboard_render_home(false);
		if ($config['cache']['enabled']) cache::set('imgboard_home_public', $html, 30);
	}
	echo $html;
}
