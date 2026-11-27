<?php
// Self-check for the pure security helpers.
// Run: docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-security.php
// (from a source checkout: php tools/selfcheck-security.php)
define('TINYBOARD', 1);
$inc = __DIR__ . '/../inc';
if (!is_file("$inc/salts.php")) { $inc = '/var/www/inc'; }
require "$inc/mod/auth.php";
require "$inc/mod/pages.php";
require "$inc/salts.php";
require "$inc/lib/webm/ffmpeg.php";

$fail = 0;
function chk($cond, $msg) { global $fail; if (!$cond) { echo "FAIL $msg\n"; $fail++; } }

// session issue time, including random parts that look like exponents
chk(imgboard_cookie_issued('1795739253.00e826051bd910e6') === 1795739253, 'exponent-like salt keeps its time');
chk(imgboard_cookie_issued('1795739254.7e427cc44ca1bb80') === 1795739254, 'huge exponent keeps its time');
chk(imgboard_cookie_issued('') === 0 && imgboard_cookie_issued('garbage') === 0, 'junk salt counts as expired');

// account hierarchy
$u = fn($id, $type) => ['id' => $id, 'type' => $type];
chk(!imgboard_can_manage_user($u(2, 30), $u(1, 40)), 'admin cannot manage Root');
chk(!imgboard_can_manage_user($u(2, 30), $u(3, 30)), 'admin cannot manage another admin');
chk(!imgboard_can_manage_user($u(2, 30), $u(3, 35)), 'admin cannot manage a manager');
chk(imgboard_can_manage_user($u(2, 30), $u(2, 30)), 'self is allowed');
chk(imgboard_can_manage_user($u(2, 30), $u(4, 20)), 'lower rank is allowed');
chk(imgboard_can_manage_user($u(1, '40'), $u(5, '40')), 'Root manages everyone');

// log redaction
chk(imgboard_redact_ips('User at 203.0.113.9 deleted #4') === 'User at xxxx deleted #4', 'ipv4');
chk(imgboard_redact_ips('User at 2001:db8::77 deleted #4') === 'User at xxxx deleted #4', 'ipv6');
chk(imgboard_redact_ips('<a href="?/IP/fdd0::1:2">fdd0::1:2</a>') === '<a href="?/IP/xxxx">xxxx</a>', 'ipv6 link');
chk(strpos(imgboard_redact_ips('ban for 2001:db8::/32 added'), 'db8') === false, 'ipv6 range');
chk(imgboard_redact_ips('Deleted post #12 at 10:30') === 'Deleted post #12 at 10:30', 'times are not addresses');

// salts: public ones replaced and stable, private ones kept
$file = tempnam(sys_get_temp_dir(), 'salt'); unlink($file);
$mk = fn() => ['cookies' => ['salt' => 'imgboard-cookie-salt-change-me'], 'secure_trip_salt' => 'my-own-salt',
	'secure_password_salt' => IMGBOARD_PUBLIC_SALTS[2]];
$a = $mk(); imgboard_replace_public_salts($a, $file);
$b = $mk(); imgboard_replace_public_salts($b, $file);
chk(strlen($a['cookies']['salt']) === 64 && $a['cookies']['salt'] !== $a['secure_password_salt'], 'public salts replaced');
chk($a['secure_trip_salt'] === 'my-own-salt', 'own salt kept');
chk($a === $b, 'salts are stable');
chk(strpos(file_get_contents($file), '<?php exit; ?>') === 0, 'salt file cannot print itself');
unlink($file);

// MP4 validation
$config = ['webm' => ['allow_audio' => true, 'max_length' => 120], 'error' => ['invalidwebm' => 'bad', 'genwebmerror' => 'gen']];
$mp4 = fn(array $codecs, string $format = 'mov,mp4,m4a,3gp,3g2,mj2') => ['format' => ['filename' => 'a.mp4', 'format_name' => $format, 'duration' => 1],
	'streams' => array_map(fn($c) => ['codec_type' => $c[0], 'codec_name' => $c[1], 'width' => 8, 'height' => 8], $codecs)];
chk(is_valid_webm($mp4([['video', 'h264'], ['audio', 'aac'], ['data', 'bin']])) === null, 'h264 + aac accepted');
chk(is_valid_webm($mp4([['video', 'mpeg4'], ['audio', 'aac']])) !== null, 'wrong video codec rejected');
chk(is_valid_webm($mp4([['video', 'h264'], ['audio', 'pcm_s16le']])) !== null, 'wrong audio codec rejected');
chk(is_valid_webm($mp4([['video', 'h264']], 'hls')) !== null, 'wrong container rejected');

// deployment files (present in a source checkout only)
$root = __DIR__ . '/..';
if (is_file("$root/compose.yml")) {
	$compose = file_get_contents("$root/compose.yml");
	$env = file_get_contents("$root/.env.example");
	preg_match_all("/getenv\('(VICHAN_[A-Z_]+)'\)/", file_get_contents("$root/inc/instance-config.php"), $m);
	foreach (array_unique($m[1]) as $var) {
		chk((bool)preg_match("/^\s+$var:/m", $compose), "compose.yml passes $var to PHP");
		chk((bool)preg_match("/^#?\s*$var=/m", $env), ".env.example mentions $var");
	}
	foreach (['VICHAN_MYSQL_PASSWORD', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'VICHAN_CACHE_PASSWORD'] as $var) {
		chk((bool)preg_match("/^$var=\s*$/m", $env), ".env.example ships no value for $var");
		chk((bool)preg_match('/\$\{' . $var . ':\?/', $compose), "compose.yml refuses to start without $var");
	}
	chk(strpos(file_get_contents("$root/inc/instance-config.php"), "'vichan!'") === false, 'no fallback database password');
	chk((bool)preg_match('/location ~ \^\/\(\?:[a-z|]*\bvendor\b[a-z|]*\)\//', file_get_contents("$root/docker/nginx/vichan.conf")), 'nginx blocks /vendor/');
	chk(!is_file("$root/securimage.php"), 'unused securimage.php endpoint is gone');
}

echo $fail ? "FAILED $fail\n" : "all security checks passed\n";
exit($fail ? 1 : 0);
