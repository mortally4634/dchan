<?php
// Self-check for inc/mod/steg.php. Run inside the php container: php tools/selfcheck-steg.php
define('TINYBOARD', 1);
require __DIR__ . '/../inc/mod/steg.php';
function ok($c, $m) { echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $GLOBALS['bad'] = 1; }

// PNG with a hidden message in the red LSB of its first pixels, plus data appended after IEND.
$msg = "HIDDEN-MESSAGE-123 hello steg world";
$bits = '';
foreach (str_split($msg) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
$w = 64; $h = 64;
$im = imagecreatetruecolor($w, $h);
for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
	$i = $y * $w + $x;
	$r = 100; if ($i < strlen($bits)) $r = ($r & ~1) | (int)$bits[$i];
	imagesetpixel($im, $x, $y, ($r << 16) | (120 << 8) | 140);
}
$f = sys_get_temp_dir() . '/steg_selfcheck.png';
imagepng($im, $f);
file_put_contents($f, "PK\x03\x04 secret payload here", FILE_APPEND);

$r = imgboard_steg_analyze($f);
ok($r['type'] === 'png', 'detects png');
ok($r['appended'] && $r['appended']['length'] === 24 && $r['appended']['kind'] === 'ZIP archive', 'finds appended zip');
ok(count(array_filter($r['flags'], fn($x) => $x[0] === 'warn')) >= 1, 'raises a warning');
if (class_exists('Imagick')) {
	$c = $r['lsb']['candidates'] ?? [];
	ok($c && $c[0]['stream'] === 'R' && strpos($c[0]['text'], 'HIDDEN-MESSAGE-123') === 0, 'recovers the LSB message from red');
	$blob = imgboard_steg_plane($f, 'png', 'R', 0);
	ok(strncmp($blob, "\x89PNG", 4) === 0, 'bit plane is a png');
}
// Clean image: no appended data, no LSB text.
imagepng($im = imagecreatetruecolor(32, 32), $g = sys_get_temp_dir() . '/steg_clean.png');
$r2 = imgboard_steg_analyze($g);
ok($r2['appended'] === null && empty($r2['lsb']['candidates']), 'clean image is clean');
@unlink($f); @unlink($g);
exit(empty($GLOBALS['bad']) ? 0 : 1);
