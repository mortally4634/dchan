<?php
/*
 *  fp.php  --  browser-fingerprint collection sink (example.com)
 *  -------------------------------------------------------------
 *  Receives the JSON payload produced by DTEG (js/dteg/) on each browsing
 *  session and records it in `imgboard_fp`, keyed to the visitor's persistent
 *  cookie id (imgboard_uid) and IP. Storing the raw component values (not just
 *  the hash) is what lets staff read the fingerprint un-hashed in the mod
 *  panel, and keying on cookie_id bridges a fresh fingerprint to the
 *  visitor's past posts (which already carry that cookie id).
 *
 *  Boards are static files, so this is also where a banned visitor is noticed on a
 *  plain page view. Answers 204 with no body, except to a banned visitor, who gets
 *  {"banned":1,"url":"/banned.php"}. Never discloses errors.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	http_response_code(405);
	exit;
}

// Read the body before the heavy bootstrap, and bail cheaply on anything
// oversized or obviously not our payload.
// Only this site's own script may report: a cross-site page cannot send this content type
// without a CORS preflight, so it cannot plant data under a visitor's address.
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
	http_response_code(204);
	exit;
}
// 256 KiB, same as IMGBOARD_FP_MAX_BYTES (not loaded yet). Larger is refused, never cut short.
$raw = file_get_contents('php://input', false, null, 0, 262145);
if ($raw === false || strlen($raw) < 2 || strlen($raw) > 262144) {
	http_response_code(204);
	exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
	http_response_code(204);
	exit;
}

require 'inc/bootstrap.php';
require_once 'inc/mod/auth.php'; // the staff-session check below

$hex = function ($v, $max) {
	return (is_string($v) && preg_match('/\A[a-f0-9]{8,' . $max . '}\z/', $v)) ? $v : null;
};

$fp_hash     = $hex($data['fp'] ?? null, 32);
$stable_hash = $hex($data['stable'] ?? null, 32);
if ($stable_hash === null) {        // the stable hash is the primary key signal
	http_response_code(204);
	exit;
}
if ($fp_hash === null) { $fp_hash = $stable_hash; }

// Persistent per-visitor cookie id (same cookie post.php stamps on posts).
// Use '' (not NULL) when absent so the unique key dedups cookieless lurkers.
list($cookie_id) = imgboard_visitor_ids($_COOKIE);
$ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
$now = time();
$is_staff = false;
$banned = false;

try {
	// Is this page load from a logged-in staff member? The same test as the mod panel
	// (not check_login(), whose side effects are unsuitable for this silent sink).
	$base = $config['cookies']['mod'];
	foreach ([$base, '__Secure-' . $base, '__Host-' . $base] as $cn) {
		if (empty($_COOKIE[$cn]) || !is_string($_COOKIE[$cn])) { continue; }
		$parts = explode(':', $_COOKIE[$cn]);
		if (count($parts) !== 3) { continue; }
		$mq = prepare("SELECT `id`, `password` FROM ``mods`` WHERE `username` = :u");
		$mq->bindValue(':u', $parts[0]);
		if ($mq->execute() && imgboard_session_valid($parts, $mq->fetch(PDO::FETCH_ASSOC))) {
			$is_staff = true;
			break;
		}
	}

	// Entry ban check, before any flood limit so that flooding cannot switch it off.
	// Only site-wide bans send a visitor away from every page. Best-effort: with JS off
	// they can still view, but checkBan() still blocks their posting.
	if (!$is_staff) {
		foreach (Bans::find($ip, '', false, null, false) as $b) {
			if (empty($b['board']) && (empty($b['expires']) || (int)$b['expires'] >= $now)) { $banned = true; break; }
		}
		if (!$banned) {
			$banned = imgboard_fp_ban_match($cookie_id, $stable_hash, $ip, '') !== null;
		}
	}
} catch (\Throwable $e) {
	error_log('fingerprint ban check failed: ' . $e->getMessage());
}

try {
	$trust = isset($data['trust']) ? max(0, min(100, (int)$data['trust'])) : null;

	$lies = '[]';
	if (isset($data['lies']) && is_array($data['lies'])) {
		$encoded = json_encode(array_values($data['lies']));
		// the summary column holds 64 KB; the history log below keeps the full payload either way
		if ($encoded !== false && strlen($encoded) <= 65000) $lies = $encoded;
	}

	$components = '{}';
	if (isset($data['components']) && is_array($data['components'])) {
		$components = json_encode($data['components']); // whole: cut-off JSON cannot be read back
	}

	// Header-derived hwid, computed exactly as post.php does, for cross-ref.
	$hwid = substr(sha1('imgboard-hwid' . $config['cookies']['salt']
		. ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|'
		. ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . '|'
		. ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 0, 16);

	$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

	imgboard_fp_log('fp', [
		'cookie_id' => $cookie_id, 'hwid' => $hwid, 'fp_hash' => $fp_hash, 'stable_hash' => $stable_hash, 'payload' => $raw,
	]);

	// Flood guard: this endpoint is public, so cap how many NEW fingerprints one
	// IP can add per hour (a script posting random hashes would otherwise fill the
	// table). shortcut: fixed 60/hour; raise if a big shared NAT loses real data.
	$q = prepare("SELECT COUNT(*) FROM ``imgboard_fp`` WHERE `ip` = :ip AND `first_seen` > :since");
	$q->bindValue(':ip', $ip);
	$q->bindValue(':since', $now - 3600, PDO::PARAM_INT);
	$q->execute();
	$flooded = (int)$q->fetchColumn() >= 60;

	// Addresses are cheap to rotate (IPv6), so new rows are also capped site-wide.
	// shortcut: fixed 1000/hour for all visitors together; raise it if a busy site loses real data.
	$q = query("SELECT `first_seen` FROM ``imgboard_fp`` ORDER BY `id` DESC LIMIT 1 OFFSET 999");
	$flooded = $flooded || ($q && (int)$q->fetchColumn() > $now - 3600);

	if (!$flooded) {
		$q = prepare("INSERT INTO ``imgboard_fp``
			(`fp_hash`, `stable_hash`, `cookie_id`, `hwid`, `ip`, `trust`, `lies`, `components`, `user_agent`, `first_seen`, `last_seen`, `hits`)
			VALUES (:fp, :stable, :cookie, :hwid, :ip, :trust, :lies, :components, :ua, :now, :now, 1)
			ON DUPLICATE KEY UPDATE
				`fp_hash` = :fp2,
				`hwid` = :hwid2,
				`trust` = :trust2,
				`lies` = :lies2,
				`components` = :components2,
				`user_agent` = :ua2,
				`last_seen` = :now2,
				`hits` = `hits` + 1");
		$q->bindValue(':fp', $fp_hash);
		$q->bindValue(':stable', $stable_hash);
		$q->bindValue(':cookie', $cookie_id);
		$q->bindValue(':hwid', $hwid);
		$q->bindValue(':ip', $ip);
		$q->bindValue(':trust', $trust, $trust === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
		$q->bindValue(':lies', $lies);
		$q->bindValue(':components', $components);
		$q->bindValue(':ua', $ua);
		$q->bindValue(':now', $now, PDO::PARAM_INT);
		$q->bindValue(':fp2', $fp_hash);
		$q->bindValue(':hwid2', $hwid);
		$q->bindValue(':trust2', $trust, $trust === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
		$q->bindValue(':lies2', $lies);
		$q->bindValue(':components2', $components);
		$q->bindValue(':ua2', $ua);
		$q->bindValue(':now2', $now, PDO::PARAM_INT);
		$q->execute();
	}

	// Staff identifiers are recorded so they can be left out of the user fingerprint
	// list / .zip / .txt - including staff who only lurk and never post.
	if ($is_staff) {
		imgboard_ddl_once("CREATE TABLE IF NOT EXISTS ``imgboard_staff_fp`` (
			`stable_hash` varchar(32) CHARACTER SET ascii NOT NULL DEFAULT '',
			`cookie_id` varchar(64) CHARACTER SET ascii NOT NULL DEFAULT '',
			`ip` varchar(45) CHARACTER SET ascii NOT NULL DEFAULT '',
			`time` int(11) NOT NULL,
			UNIQUE KEY `ident` (`stable_hash`,`cookie_id`,`ip`),
			KEY `k_cookie` (`cookie_id`), KEY `k_ip` (`ip`), KEY `k_stable` (`stable_hash`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$s = prepare("INSERT INTO ``imgboard_staff_fp`` (`stable_hash`,`cookie_id`,`ip`,`time`)
			VALUES (:s,:c,:i,:t) ON DUPLICATE KEY UPDATE `time` = :t2");
		$s->bindValue(':s', $stable_hash);
		$s->bindValue(':c', $cookie_id);
		$s->bindValue(':i', $ip);
		$s->bindValue(':t', $now, PDO::PARAM_INT);
		$s->bindValue(':t2', $now, PDO::PARAM_INT);
		$s->execute();
	}

	if (mt_rand(1, 200) === 1) imgboard_fp_prune();
} catch (\Throwable $e) {
	// Never leak anything to an unauthenticated poster.
}

if ($banned) {
	header('Content-Type: application/json');
	echo json_encode([ 'banned' => 1, 'url' => '/banned.php' ]);
	exit;
}
http_response_code(204);
