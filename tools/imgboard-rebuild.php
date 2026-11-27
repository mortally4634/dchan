<?php
/*
 *  imgboard-rebuild.php - credential-free full static rebuild.
 *
 *  Regenerates main.js, all themes (incl. the public ban list), every board
 *  index and every thread. Unlike tools/rebuild.php it bootstraps the normal
 *  way (inc/bootstrap.php), which keeps the global $board intact across the
 *  theme/JS build, and it re-opens each board before building its threads.
 *
 *  Run from the web root, as www-data so file ownership stays writable by the
 *  web process:
 *     docker compose exec -T -u www-data -w /var/www php php tools/imgboard-rebuild.php
 */

require 'inc/bootstrap.php';

/* --- Setup (same as the ?/upgrade-db mod page, but credential-free): ensure
 *     every board's posts table has the columns this build expects, activate
 *     the public ban list theme, and seed the rules/news page content. --- */
$required = [
	'approved'       => "tinyint(1) NOT NULL DEFAULT 1",
	'approved_link'  => "tinyint(1) NOT NULL DEFAULT 1",
	'cookie_id'      => "varchar(64) CHARACTER SET ascii DEFAULT NULL",
	'hwid'           => "varchar(64) CHARACTER SET ascii DEFAULT NULL",
	'is_vpn'         => "tinyint(1) NOT NULL DEFAULT 0",
	'show_poster_id' => "tinyint(1) NOT NULL DEFAULT 0",
	'mod_id'         => "int(11) DEFAULT NULL",
	// Appended last so it matches posts.sql column order (the post INSERT is
	// positional, so a new column must be the final one on every board).
	// Client browser fingerprint (js/imgboard-fp.js -> imgboard_fp cookie).
	'fp_hash'        => "varchar(32) CHARACTER SET ascii DEFAULT NULL",
];
foreach (listBoards() as $b) {
	// A fresh database lists boards (install.sql) that have no posts table yet.
	openBoard($b['uri']);
	query(Element('posts.sql', [ 'board' => $b['uri'] ])) or error(db_error());
	$table = 'posts_' . $b['uri'];
	$q = prepare("SELECT `COLUMN_NAME` FROM information_schema.columns WHERE table_schema = :db AND table_name = :t");
	$q->bindValue(':db', $config['db']['database']);
	$q->bindValue(':t', $table);
	$q->execute() or error(db_error($q));
	$have = array_map(fn($r) => $r['COLUMN_NAME'], $q->fetchAll(PDO::FETCH_ASSOC));
	foreach ($required as $col => $def) {
		if (!in_array($col, $have, true)) {
			query(sprintf("ALTER TABLE ``%s`` ADD COLUMN ``%s`` %s", $table, $col, $def)) or error(db_error());
		}
	}
}
// Browser-fingerprint store (js/imgboard-fp.js -> fp.php). One row per
// (stable_hash, cookie_id, ip) sighting; `components` keeps the raw signal
// values so staff can read the fingerprint un-hashed. Keyed on cookie_id so a
// fresh fingerprint bridges to a visitor's past posts.
$fp_sql = "CREATE TABLE IF NOT EXISTS ``imgboard_fp`` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (mysql_version() < 50503) {
	$fp_sql = str_replace('utf8mb4', 'utf8', $fp_sql);
}
query($fp_sql) or error(db_error());

$q = query("SELECT 1 FROM ``theme_settings`` WHERE `theme` = 'public_banlist' AND `name` IS NULL AND `value` IS NULL");
if ($q && !$q->fetch()) {
	query("INSERT INTO ``theme_settings`` (`theme`, `name`, `value`) VALUES
		('public_banlist', NULL, NULL),
		('public_banlist', 'file_json', 'bans.json'),
		('public_banlist', 'file_bans', 'bans.html')") or error(db_error());
	cache::delete('themes');
}
if (imgboard_setting_get('rules', '') === '') { imgboard_setting_set('rules', IMGBOARD_DEFAULT_RULES); }
if (imgboard_setting_get('news', '')  === '') { imgboard_setting_set('news',  IMGBOARD_DEFAULT_NEWS); }

// Ensure the /j/ staff board exists (created credential-free here, like the
// mod "new board" page does). Name field on + board-wide permanent Staff IDs.
$q = query("SELECT 1 FROM ``boards`` WHERE `uri` = 'j'");
if ($q && !$q->fetch()) {
	$ins = prepare("INSERT INTO ``boards`` VALUES (:uri, :title, :subtitle)");
	$ins->bindValue(':uri', 'j');
	$ins->bindValue(':title', 'Staff');
	$ins->bindValue(':subtitle', 'Staff only');
	$ins->execute() or error(db_error($ins));
	openBoard('j');
	$sql = Element('posts.sql', [ 'board' => 'j' ]);
	if (mysql_version() < 50503) {
		$sql = preg_replace('/(CHARSET=|CHARACTER SET )utf8mb4/', '$1utf8', $sql);
	}
	query($sql) or error(db_error());
	cache::delete('all_boards');
	echo "created /j/ staff board\n";
}
// Staff board settings (idempotent): name field on + board-wide Staff IDs.
imgboard_setting_set('board_j_name_field', '1');
imgboard_setting_set('board_j_sids', '1');
imgboard_flush_board_config('j');

// Ensure the /test/ staff scratch board exists (staff-only, for posting test
// content). Same staff-only gating as /j/ (imgboard_staff_boards), but plain:
// no name field or Staff IDs.
$q = query("SELECT 1 FROM ``boards`` WHERE `uri` = 'test'");
if ($q && !$q->fetch()) {
	$ins = prepare("INSERT INTO ``boards`` VALUES (:uri, :title, :subtitle)");
	$ins->bindValue(':uri', 'test');
	$ins->bindValue(':title', 'Test');
	$ins->bindValue(':subtitle', 'Staff testing - post whatever');
	$ins->execute() or error(db_error($ins));
	openBoard('test');
	$sql = Element('posts.sql', [ 'board' => 'test' ]);
	if (mysql_version() < 50503) {
		$sql = preg_replace('/(CHARSET=|CHARACTER SET )utf8mb4/', '$1utf8', $sql);
	}
	query($sql) or error(db_error());
	cache::delete('all_boards');
	echo "created /test/ staff board\n";
}

$new_password = secure_admin_account();
if ($new_password !== null) {
	echo "admin account: username admin, password $new_password (shown once - change it after logging in)\n";
}

// Imgboard: make sure the site has a Root owner. Seed Owner as Root only
// when NO account is Root yet, so a Root handover to another account survives
// future deploys (Root is a single, transferable ownership role).
$has_root = query("SELECT 1 FROM ``mods`` WHERE `type` = 40 LIMIT 1");
if ($has_root && !$has_root->fetch()) {
	query("UPDATE ``mods`` SET `type` = 40 WHERE `username` = 'admin'") or error(db_error());
	echo "seeded Root owner (admin)\n";
}

// Moderation log: add the surrogate id column + normalise old approve labels.
if (function_exists('imgboard_modlog_setup')) { imgboard_modlog_setup(); }

/* --- Overboard (/o): enable the ukko theme, which aggregates recent threads
 *     (with reply previews) from every board into one page, rebuilt live on
 *     each post. Staff boards /j/ and /test/ are excluded. --- */
$q = query("SELECT 1 FROM ``theme_settings`` WHERE `theme` = 'ukko' AND `name` IS NULL AND `value` IS NULL");
if ($q && !$q->fetch()) {
	query("INSERT INTO ``theme_settings`` (`theme`, `name`, `value`) VALUES
		('ukko', NULL, NULL),
		('ukko', 'title', 'Overboard'),
		('ukko', 'uri', 'o'),
		('ukko', 'subtitle', 'All boards - %s freshly bumped threads'),
		('ukko', 'exclude', 'j test'),
		('ukko', 'thread_limit', '30')") or error(db_error());
	cache::delete('themes');
	cache::delete('theme_settings_ukko');
	echo "enabled /o/ overboard (ukko)\n";
}
// Keep every staff board out of the public overboard (idempotent): the ukko
// exclude list is space-separated and must cover /j/ and /test/.
query("UPDATE ``theme_settings`` SET `value` = 'j test' WHERE `theme` = 'ukko' AND `name` = 'exclude'") or error(db_error());
cache::delete('theme_settings_ukko');
cache::delete('themes');
// Ensure the overboard dir + its lazy-load script exist (ukko writes o/index.html).
if (!is_dir('o')) { @mkdir('o', 0777); }
file_write('o/ukko.js', Element('themes/ukko/ukko.js', []));

buildJavascript();
// main.js now exists again; recompute the cache-busting version from its fresh
// mtime so the static pages built below (incl. bans.html) reference the new
// JS/CSS. config.php computes this too, but bootstrap ran before buildJavascript
// recreated main.js, so the global still holds the fallback 0 at this point.
$config['resource_version'] = @filemtime('main.js') ?: time();
Vichan\Functions\Theme\rebuild_themes('all');

foreach (listBoards() as $b) {
	openBoard($b['uri']);
	$config['try_smarter'] = false;
	buildIndex();

	$query = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL", $b['uri'])) or error(db_error());
	while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
		openBoard($b['uri']); // ensure $board is fresh before each thread build
		buildThread($post['id']);
	}
	echo "rebuilt /{$b['uri']}/\n";
}

echo "imgboard-rebuild complete\n";
