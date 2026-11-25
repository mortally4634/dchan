<?php
// Self-check for the fingerprint-ban matcher (inc/mod/pending.php).
// Run: docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-fpban.php
define('TINYBOARD', 1);
$config = ['cache' => ['enabled' => false]];
require __DIR__ . '/../inc/mod/pending.php';

$now = 1000;
$fail = 0;
function chk($cond, $msg) { global $fail; if (!$cond) { echo "FAIL $msg\n"; $fail++; } }

$row = ['fp_hash' => 'abc123', 'cookie_id' => 'deadbeef', 'hwid' => 'hw00', 'expires' => null];

// matches on fp_hash
chk(imgboard_fp_ban_row_matches($row, 'abc123', null, $now) === true, 'fp_hash match');
// matches on cookie_id
chk(imgboard_fp_ban_row_matches($row, null, 'deadbeef', $now) === true, 'cookie_id match');
// no match on different values
chk(imgboard_fp_ban_row_matches($row, 'other', 'nope', $now) === false, 'no false match');
// hwid is NEVER matched (collision-prone) - visitor has the hwid but no fp/cookie
chk(imgboard_fp_ban_row_matches($row, null, null, $now) === false, 'hwid alone must not match');
// empty visitor identifiers do not match empty stored fields
$blank = ['fp_hash' => '', 'cookie_id' => '', 'hwid' => '', 'expires' => null];
chk(imgboard_fp_ban_row_matches($blank, '', '', $now) === false, 'empty identifiers never match');
chk(imgboard_fp_ban_row_matches($blank, null, null, $now) === false, 'null identifiers never match');
// expiry respected
$expired = ['fp_hash' => 'abc123', 'cookie_id' => '', 'hwid' => '', 'expires' => 500];
chk(imgboard_fp_ban_row_matches($expired, 'abc123', null, $now) === false, 'expired ban does not match');
$active = ['fp_hash' => 'abc123', 'cookie_id' => '', 'hwid' => '', 'expires' => 1500];
chk(imgboard_fp_ban_row_matches($active, 'abc123', null, $now) === true, 'unexpired ban matches');
// permanent (null expires) matches
chk(imgboard_fp_ban_row_matches($row, 'abc123', null, $now) === true, 'permanent ban matches');

echo $fail ? "FAILED $fail\n" : "all fp-ban checks passed\n";
exit($fail ? 1 : 0);
