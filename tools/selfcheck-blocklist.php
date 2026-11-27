<?php
// Self-check for the blocklist detector/matcher (inc/mod/pending.php).
// Run: docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php
define('TINYBOARD', 1);
$config = ['cache' => ['enabled' => false]];
require __DIR__ . '/../inc/mod/pending.php';
require __DIR__ . '/../inc/display.php';
// same as inc/functions.php
function remove_modifiers($b) { return $b ? preg_replace('@<tinyboard ([\w\s]+)>(.+?)</tinyboard>@usm', '', $b) : null; }

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

// --- link evasions the pending queue must catch ---
$evasions = [
	'bracket dot' => 'evil[.]com', 'spaced bracket dot' => 'evil [dot] com', 'worded dot' => 'evil dot com',
	'spaced dot' => 'evil . com', 'full-width' => "\xEF\xBD\x85\xEF\xBD\x96\xEF\xBD\x89\xEF\xBD\x8C\xEF\xBC\x8E\xEF\xBD\x83\xEF\xBD\x8F\xEF\xBD\x8D",
	'ideographic dot' => "evil\xE3\x80\x82com", 'middle dot' => "evil\xC2\xB7com", 'zero width' => "ev\xE2\x80\x8Bil.co\xE2\x81\xA3m",
	'combining mark' => "evil.co\xCD\x8Fm", 'entity dot' => 'evil&#46;com', 'double entity' => 'evil&amp;#46;com',
	'percent dot' => 'evil%2Ecom', 'any gtld' => 'visit evil.ninja', 'new cctld' => 'go to shop.nz',
	'punycode tld' => 'site.xn--p1ai', 'unicode domain' => "\xD0\xBF\xD1\x80\xD0\xB8\xD0\xBC\xD0\xB5\xD1\x80.\xD1\x80\xD1\x84",
	'glued to cjk' => "\xE7\x9C\x8Bevil.com\xE5\x90\xA7", 'homoglyph start' => "\xD0\xB5vil.com", 'fake extension' => 'evil.com.jpg',
	'trailing underscore' => 'evil.com_', 'bare ip' => 'go 185.12.3.4', 'ip and port' => '185.12.3.4:8080/x', 'ipv6' => 'at [2001:db8::1]/x',
	'www unknown tld' => 'www.evil.localdomain', 'spaced scheme' => 'h t t p : / / evilhost/x', 'star scheme' => 'h**ps://evilhost',
	'backslash scheme' => 'http:\\\\evilhost', 'magnet' => 'magnet:?xt=urn:btih:abc', 'ftp' => 'ftp://files', 'tg' => 'tg:resolve?domain=x',
	'data url' => 'data:text/html,hi', 'i2p' => 'abc.b32.i2p', 'zip with path' => 'evil.zip/login', 'short domain' => 't.me/channel',
];
foreach ($evasions as $name => $body) {
	chk(imgboard_detect_links($body) !== [], "evasion should be detected: $name");
}
foreach (['see you at 2 p.m. today', 'version 1.2.3 is out', 'file main.rs and script.py', 'he said... nothing',
		'quote >>123 and >>>/b/5', 'wait . 5 more', 'ratio 3:4 // note to self', 'e.g. this, i.e. that', 'image.png and photo.jpeg'] as $body) {
	chk(imgboard_detect_links($body) === [], "ordinary text should pass: $body " . json_encode(imgboard_detect_links($body)));
}

// long dotted text must neither hide a domain nor make the scan give up
$long = str_repeat('a.', 20000) . ' evil.com';
chk(imgboard_detect_links($long) !== [], 'domain after long dotted text is detected');
chk(in_array('evil.com', imgboard_detect_links($long), true), 'long dotted text is really scanned');

// --- every visible field counts ---
$base = ['body' => 'hi', 'body_nomarkup' => 'hi'];
chk(!imgboard_post_has_link($base + ['subject' => 'hello', 'name' => 'Anonymous', 'files' => [['filename' => 'cat.jpg']]]), 'clean post not held');
foreach (['subject', 'name', 'email', 'poll_text'] as $field) {
	chk(imgboard_post_has_link($base + [$field => 'evil.com']), "link in $field is held");
}
chk(imgboard_post_has_link($base + ['files' => [['filename' => 'evil.com.jpg']]]), 'link in filename is held');
chk(imgboard_post_has_link($base + ['files' => json_encode([['filename' => 'www.evil.net.png']])]), 'link in stored filename is held');
chk(imgboard_post_has_link($base + ['embed' => 'https://youtu.be/aaaaaaaaaaa']), 'embed is held');
// markup splits the source text, but the reader sees one domain
chk(imgboard_post_has_link(['body' => 'ev<em>il</em>.c<strong>om</strong>', 'body_nomarkup' => "ev''il''.c" . str_repeat("'", 3) . "om"]), 'markup-split domain is held');
chk(in_array('evil.com', imgboard_post_links($base + ['subject' => 'evil[.]com']), true), 'preview lists the decoded link');

// --- covering while pending ---
$post = fn(array $f) => (object)($f + ['approved_link' => 0, 'mod' => false, 'body' => 'plain words', 'body_nomarkup' => 'plain words',
	'subject' => 'topic', 'name' => 'Anonymous', 'email' => '', 'embed' => null, 'files' => []]);
$o = $post(['body' => 'see <a href="http://evil.com" rel="nofollow">http://evil.com</a> ok', 'body_nomarkup' => 'see http://evil.com ok']);
cover_pending_links($o);
chk(strpos($o->body, 'evil.com') === false && strpos($o->body, 'see ') === 0, 'anchor replaced, rest of text kept');
chk(strpos($o->body_nomarkup, 'evil.com') === false, 'raw source covered');
$o = $post(['body' => 'go to evil(dot)com', 'body_nomarkup' => "go to evil(dot)com\n<tinyboard flag>us</tinyboard>",
	'subject' => 'evil.com', 'name' => 'www.evil.net', 'email' => 'x@evil.com', 'embed' => '<iframe src="https://www.youtube.com/embed/x"></iframe>',
	'files' => [(object)['filename' => 'evil.com.jpg', 'file' => '123.jpg']]]);
cover_pending_links($o);
$shown = json_encode($o);
chk(strpos($shown, 'evil') === false && strpos($shown, 'youtube') === false, "nothing pending is shown: $shown");
chk(strpos($o->body_nomarkup, '<tinyboard flag>us</tinyboard>') !== false, 'modifier tags kept');
$o = $post(['body' => 'go to evil.com', 'approved_link' => 1]); cover_pending_links($o);
chk($o->body === 'go to evil.com', 'approved post untouched');
$o = $post(['body' => 'go to evil.com', 'mod' => ['type' => 20]]); cover_pending_links($o);
chk($o->body === 'go to evil.com', 'staff see the real link');

// --- word matching through bypasses ---
$words = ['bad'];
$links = ['evil.com'];
$word_hit = ['bad', 'b a d', 'b.a.d', "b\xE2\x80\x8Ba\xE2\x80\x8Bd", 'XbadX'];
foreach ($word_hit as $b) {
	chk(imgboard_blocklist_match_core($b, $words, $links) === 'Your post contains a blocked word.', "word should hit [$b]");
}
chk(imgboard_blocklist_match_core('totally fine text', $words, $links) === null, 'clean body should pass');

// --- link matching via the shared detector ---
$link_hit = ['go to evil.com', 'evil(dot)com', 'hxxp://evil.com/x', 'EVIL.COM/', 'evil[.]com', 'evil . com',
	"\xEF\xBD\x85\xEF\xBD\x96\xEF\xBD\x89\xEF\xBD\x8C.com", "evil\xE3\x80\x82com"];
foreach ($link_hit as $b) {
	chk(imgboard_blocklist_match_core($b, [], $links) === 'Your post contains a blocked link.', "link should hit [$b]");
}
chk(imgboard_blocklist_match_core('clean.example-not-listed', [], $links) === null, 'unlisted link should pass match');

echo $fail ? "FAILED $fail\n" : "all blocklist checks passed\n";
exit($fail ? 1 : 0);
