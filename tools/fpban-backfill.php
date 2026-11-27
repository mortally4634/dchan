<?php
/*
 *  One-off: attach fingerprint links to EXISTING bans, using the post saved on each
 *  ban (only bans that stored their post have one: $config['ban_show_post']). Bans
 *  typed as a bare address or range are skipped, on purpose: guessing from "who else
 *  used this address" would ban strangers. Safe to run again.
 *
 *  Run: docker compose exec -T -u www-data -w /var/www php php tools/fpban-backfill.php
 */

require 'inc/bootstrap.php';

if (!function_exists('imgboard_fp_ban_capture')) {
	fwrite(STDERR, "fp-ban functions not loaded (inc/mod/pending.php)\n");
	exit(1);
}

$q = query("SELECT `id`, `post` FROM ``bans`` WHERE `post` IS NOT NULL AND (`expires` IS NULL OR `expires` > " . time() . ")") or error(db_error());

$seen = 0;
while ($b = $q->fetch(PDO::FETCH_ASSOC)) {
	$post = json_decode($b['post'], true);
	if (!is_array($post) || (empty($post['cookie_id']) && empty($post['fp_hash']))) continue;
	imgboard_fp_ban_capture((int)$b['id'], $post);
	$seen++;
}

echo "fp-ban backfill: linked identifiers from $seen active ban(s) with a saved post\n";
