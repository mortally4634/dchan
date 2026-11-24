<?php
// Self-check for the blocklist detector/matcher (inc/mod/pending.php).
// Run: docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php
define('TINYBOARD', 1);
$config = ['cache' => ['enabled' => false]];
require __DIR__ . '/../inc/mod/pending.php';

$fail = 0;
function chk($cond, $msg) { global $fail; if (!$cond) { echo "FAIL $msg\n"; $fail++; } }

// --- imgboard_detect_links: must detect ---
$detect_hit = [
	'bare domain'       => 'check evil.com now',
	'dot-obfuscated'    => 'go to evil(dot)com',
	'hxxp scheme'       => 'hxxp://evil.top/path',
	'https url'         => 'see https://evil.io/a',
	'ipv4 url'          => 'http://185.0.0.1/x',
	'protocol-relative' => 'visit //evil.top/x',
	'punycode host'     => 'xn--80ak6aa92e.com here',
	'email host'        => 'mail me at bad@evil.com', // over-block: expected
];
foreach ($detect_hit as $name => $body) {
	chk(count(imgboard_detect_links($body)) > 0, "detect should hit: $name [$body]");
}

// --- imgboard_detect_links: must NOT detect (allowlist omits these) ---
$detect_miss = [
	'zip filename' => 'here is photo.zip for you',
	'mov filename' => 'clip.mov attached',
	'md filename'  => 'read readme.md',
	'plain text'   => 'i went there etc then left',
];
foreach ($detect_miss as $name => $body) {
	chk(count(imgboard_detect_links($body)) === 0, "detect should miss: $name [$body]");
}

// --- word matching through bypasses ---
$words = ['bad'];
$links = ['evil.com'];
$word_hit = ['bad', 'b a d', 'b.a.d', "b\xE2\x80\x8Ba\xE2\x80\x8Bd", 'XbadX'];
foreach ($word_hit as $b) {
	chk(imgboard_blocklist_match_core($b, $words, $links) === 'Your post contains a blocked word.', "word should hit [$b]");
}
chk(imgboard_blocklist_match_core('totally fine text', $words, $links) === null, 'clean body should pass');

// --- link matching via the shared detector ---
$link_hit = ['go to evil.com', 'evil(dot)com', 'hxxp://evil.com/x', 'EVIL.COM/'];
foreach ($link_hit as $b) {
	chk(imgboard_blocklist_match_core($b, [], $links) === 'Your post contains a blocked link.', "link should hit [$b]");
}
chk(imgboard_blocklist_match_core('clean.example-not-listed', [], $links) === null, 'unlisted link should pass match');

echo $fail ? "FAILED $fail\n" : "all blocklist checks passed\n";
exit($fail ? 1 : 0);
