<?php
/*
 *  One-off: make every EXISTING ban a fingerprint ban too, wherever a fingerprint
 *  is knowable - from the banned post stored on the ban, and from fingerprints seen
 *  at a single-IP ban's address (imgboard_fp). Range bans with no post are skipped
 *  (no single fingerprint to capture). Idempotent: re-running just de-dupes.
 *
 *  Run: docker compose exec -T -u www-data -w /var/www php php tools/fpban-backfill.php
 */

require 'inc/bootstrap.php';

if (!function_exists('imgboard_fp_ban_add')) {
	fwrite(STDERR, "fp-ban functions not loaded (inc/mod/pending.php)\n");
	exit(1);
}

$q = query("SELECT `ipstart`, `ipend`, `expires`, `reason`, `post` FROM ``bans``") or error(db_error());
$bans = $q->fetchAll(PDO::FETCH_ASSOC);

$now = time();
$seen = 0;
$added = 0;

foreach ($bans as $b) {
	$expires = $b['expires'] !== null ? (int)$b['expires'] : null;
	if ($expires !== null && $expires < $now) continue; // skip already-expired bans
	$reason = ($b['reason'] !== null && $b['reason'] !== '') ? $b['reason'] : null;
	$seen++;

	// From the fingerprint stored on the banned post.
	if (!empty($b['post'])) {
		$p = json_decode($b['post'], true);
		if (is_array($p) && (!empty($p['fp_hash']) || !empty($p['cookie_id']))) {
			imgboard_fp_ban_add($p['fp_hash'] ?? null, $p['cookie_id'] ?? null, $p['hwid'] ?? null, $reason, $expires, 'backfill');
			$added++;
		}
	}

	// From fingerprints seen at a single-IP ban's address.
	if ($b['ipend'] === null || $b['ipstart'] === $b['ipend']) {
		$ip = @inet_ntop($b['ipstart']);
		if ($ip !== false) {
			$added += imgboard_fp_ban_capture_from_ip($ip, $reason, $expires, 'backfill');
		}
	}
}

echo "fp-ban backfill: scanned " . count($bans) . " bans, " . $seen . " active, added up to $added fingerprint bans\n";
