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
 *  Pure data sink: no output, never discloses errors, responds 204.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	http_response_code(405);
	exit;
}

// Read the body before the heavy bootstrap, and bail cheaply on anything
// oversized or obviously not our payload.
$raw = file_get_contents('php://input', false, null, 0, 262144); // 256 KiB cap (richer DTEG payloads run larger)
if ($raw === false || $raw === '' || strlen($raw) < 2) {
	http_response_code(204);
	exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
	http_response_code(204);
	exit;
}

require 'inc/bootstrap.php';
require_once 'inc/mod/auth.php'; // mkhash() for the staff-session check below

try {
	$hex = function ($v, $max) {
		return (is_string($v) && preg_match('/^[a-f0-9]{8,' . $max . '}$/', $v)) ? $v : null;
	};

	$fp_hash     = $hex($data['fp'] ?? null, 32);
	$stable_hash = $hex($data['stable'] ?? null, 32);
	if ($stable_hash === null) {        // the stable hash is the primary key signal
		http_response_code(204);
		exit;
	}
	if ($fp_hash === null) { $fp_hash = $stable_hash; }

	$trust = isset($data['trust']) ? max(0, min(100, (int)$data['trust'])) : null;

	$lies = '[]';
	if (isset($data['lies']) && is_array($data['lies'])) {
		$lies = substr(json_encode(array_values(array_slice($data['lies'], 0, 40))), 0, 4000);
	}

	$components = '{}';
	if (isset($data['components']) && is_array($data['components'])) {
		$components = substr(json_encode($data['components']), 0, 200000); // raw values for staff (kept whole; column is mediumtext)
	}

	// Persistent per-visitor cookie id (same cookie post.php stamps on posts).
	// Use '' (not NULL) when absent so the unique key dedups cookieless lurkers.
	$cookie_id = '';
	if (isset($_COOKIE['imgboard_uid']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['imgboard_uid'])) {
		$cookie_id = $_COOKIE['imgboard_uid'];
	}

	// Header-derived hwid, computed exactly as post.php does, for cross-ref.
	$hwid = substr(sha1('imgboard-hwid' . $config['cookies']['salt']
		. ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|'
		. ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . '|'
		. ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 0, 16);

	$ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
	$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
	$now = time();

	// Flood guard: this endpoint is public, so cap how many NEW fingerprints one
	// IP can add per hour (a script posting random hashes would otherwise fill the
	// table). shortcut: fixed 60/hour; raise if a big shared NAT loses real data.
	$q = prepare("SELECT COUNT(*) FROM ``imgboard_fp`` WHERE `ip` = :ip AND `first_seen` > :since");
	$q->bindValue(':ip', $ip);
	$q->bindValue(':since', $now - 3600, PDO::PARAM_INT);
	$q->execute();
	if ((int)$q->fetchColumn() >= 60) {
		http_response_code(204);
		exit;
	}

	// Is this page load from a logged-in staff member? If so, still record the sighting
	// (so admins can view it at ?/fp/<hash>), but also stamp the staff member's
	// identifiers into imgboard_staff_fp so they are excluded from the user fingerprint
	// list / .zip / .txt - including staff who only lurk and never post. Validate the mod
	// cookie directly (mkhash); check_login() has side effects (destroyCookies/exit)
	// unsuitable for this silent sink.
	$is_staff = false;
	$base = $config['cookies']['mod'];
	foreach ([$base, '__Secure-' . $base, '__Host-' . $base] as $cn) {
		if (empty($_COOKIE[$cn])) { continue; }
		$parts = explode(':', $_COOKIE[$cn]);
		if (count($parts) !== 3) { continue; }
		$mq = prepare("SELECT `password` FROM ``mods`` WHERE `username` = :u");
		$mq->bindValue(':u', $parts[0]);
		if ($mq->execute() && ($mu = $mq->fetch(PDO::FETCH_ASSOC)) && $parts[1] === mkhash($parts[0], $mu['password'], $parts[2])) {
			$is_staff = true;
			break;
		}
	}

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

	// Entry ban check: boards are static HTML, so this is the only server round-trip
	// on a plain page view. If this (non-staff) visitor is under an IP/range ban or a
	// fingerprint ban, tell the client to show banned.php. Best-effort: a visitor with
	// JS off can still view, but checkBan() still hard-blocks their posting.
	if (!$is_staff) {
		$banned = false;
		if (class_exists('Bans')) {
			foreach (Bans::find($ip, false, false, null, false) as $b) {
				if (empty($b['expires']) || (int)$b['expires'] >= $now) { $banned = true; break; }
			}
		}
		if (!$banned && function_exists('imgboard_fp_ban_match')) {
			$banned = imgboard_fp_ban_match($fp_hash, $cookie_id !== '' ? $cookie_id : null) !== null;
		}
		if ($banned) {
			header('Content-Type: application/json');
			echo json_encode([ 'banned' => 1, 'url' => '/banned.php' ]);
			exit;
		}
	}

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
} catch (Exception $e) {
	// Never leak anything to an unauthenticated poster.
}

http_response_code(204);
