<?php
/*
 * captcha.php - example.com no-JS captcha (dynamic image endpoint)
 *
 * GET /captcha.php renders a GD PNG of a short random code, stores the answer
 * in the shared `captchas` table keyed by a cookie it sets (imgboard_captcha_id),
 * and streams the image with no-store headers. The post form embeds
 * <img src="/captcha.php"> plus a text field; post.php verifies the typed code
 * against the stored answer and consumes it (one-shot). No JavaScript and no
 * third-party service - the challenge is a rasterised image, so the answer is
 * never present in the page markup.
 *
 * (The bundled vichan captcha is unusable here: inc/captcha/entrypoint.php is
 * JS-only with placeholder DB credentials. This is a self-contained replacement.)
 */
require_once 'inc/bootstrap.php';

const IMGBOARD_CAPTCHA_EXTRA = 'imgboard';
const IMGBOARD_CAPTCHA_TTL   = 600; // seconds a code stays valid

// The schema ships `captchas`; create defensively so a fresh DB still works.
query("CREATE TABLE IF NOT EXISTS ``captchas`` (
	`cookie` VARCHAR(50), `extra` VARCHAR(200), `text` VARCHAR(255), `created_at` INT(11),
	PRIMARY KEY (`cookie`,`extra`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or error(db_error());

// Expire old codes in this namespace.
$cl = prepare("DELETE FROM ``captchas`` WHERE `extra` = :e AND `created_at` < :t");
$cl->bindValue(':e', IMGBOARD_CAPTCHA_EXTRA);
$cl->bindValue(':t', time() - IMGBOARD_CAPTCHA_TTL, PDO::PARAM_INT);
$cl->execute();

// Generate a code (no ambiguous 0/O/1/I) and a cookie id.
$charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$len = 5;
$code = '';
for ($i = 0; $i < $len; $i++) {
	$code .= $charset[random_int(0, strlen($charset) - 1)];
}
$cid = bin2hex(random_bytes(16));

$ins = prepare("REPLACE INTO ``captchas`` (`cookie`, `extra`, `text`, `created_at`) VALUES (:c, :e, :t, :ts)");
$ins->bindValue(':c', $cid);
$ins->bindValue(':e', IMGBOARD_CAPTCHA_EXTRA);
$ins->bindValue(':t', $code);
$ins->bindValue(':ts', time(), PDO::PARAM_INT);
$ins->execute() or error(db_error($ins));

setcookie('imgboard_captcha_id', $cid, [
	'expires'  => time() + IMGBOARD_CAPTCHA_TTL,
	'path'     => '/',
	'httponly' => true,
	'samesite' => 'Lax',
]);

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// --- Render -------------------------------------------------------------
$w = 200; $h = 70;
$im = imagecreatetruecolor($w, $h);
$bg = imagecolorallocate($im, 246, 246, 242);
imagefilledrectangle($im, 0, 0, $w, $h, $bg);

// Speckle noise.
for ($i = 0; $i < 600; $i++) {
	$c = imagecolorallocate($im, random_int(180, 225), random_int(180, 225), random_int(180, 225));
	imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
}

// Draw each glyph scaled up from the built-in bitmap font, with vertical jitter.
$font  = 5;
$fw    = imagefontwidth($font);
$fh    = imagefontheight($font);
$scale = 3;
$gw    = $fw * $scale;
$gh    = $fh * $scale;
$step  = (int) (($w - 24) / $len);
for ($i = 0; $i < $len; $i++) {
	$tmp = imagecreatetruecolor($fw, $fh);
	$tbg = imagecolorallocate($tmp, 246, 246, 242);
	imagefilledrectangle($tmp, 0, 0, $fw, $fh, $tbg);
	$fg = imagecolorallocate($tmp, random_int(0, 70), random_int(0, 70), random_int(0, 90));
	imagestring($tmp, $font, 0, 0, $code[$i], $fg);

	$x = 14 + $i * $step;
	$y = random_int(4, max(5, $h - $gh - 4));
	imagecopyresampled($im, $tmp, $x, $y, 0, 0, $gw, $gh, $fw, $fh);
	imagedestroy($tmp);
}

// A few wavy strokes across the text.
for ($i = 0; $i < 3; $i++) {
	$c = imagecolorallocate($im, random_int(90, 150), random_int(90, 150), random_int(90, 150));
	$ly = random_int(10, $h - 10);
	$px = 0; $py = $ly;
	for ($x = 0; $x <= $w; $x += 8) {
		$yy = $ly + (int) (sin($x / 18 + $i) * 8);
		imageline($im, $px, $py, $x, $yy, $c);
		$px = $x; $py = $yy;
	}
}

imagepng($im);
imagedestroy($im);
