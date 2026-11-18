<?php
/*
 *  exotic.php  --  uploads in image formats PHP's own image functions can't read
 *  (JPEG XL, AVIF, HEIC/HEIF, TIFF, ICO).
 *
 *  They are stored untouched as downloadable files (like WebM) and get a thumbnail made by
 *  ImageMagick. ImageMagick is always given an explicit coder prefix (jxl:, tiff:, ...), so an
 *  upload can never make it pick another decoder (SVG, MSL, ephemeral:, ...), and every run has
 *  hard memory/time limits against decompression bombs. A file ImageMagick can't decode as the
 *  claimed format is rejected.
 */
defined('TINYBOARD') or exit;

// extension => ImageMagick coder
function imgboard_exotic_coders(): array {
	return [
		'jxl' => 'jxl', 'avif' => 'avif', 'heic' => 'heic', 'heif' => 'heif',
		'tif' => 'tiff', 'tiff' => 'tiff', 'ico' => 'ico',
	];
}

function imgboard_exotic_magick(string $args, bool $identify = false): array {
	$out = [];
	$limits = '-limit memory 256MiB -limit map 512MiB -limit disk 1GiB -limit time 20 ';
	// `magick identify` is a subcommand and must come first; plain conversions take the limits first.
	exec('timeout 25 magick ' . ($identify ? 'identify ' . $limits : $limits) . $args . ' 2>/dev/null', $out, $rc);
	return [$rc, $out];
}

function imgboard_exoticImageHandler($post) {
	global $board, $config;
	if (!$post->has_file) return;
	$coders = imgboard_exotic_coders();

	foreach ($post->files as &$file) {
		$ext = strtolower($file->extension);
		if (!isset($coders[$ext])) continue;
		$src = $coders[$ext] . ':' . $file->file_path . '[0]';

		// 1. must decode as that format, and the size must be sane
		[$rc, $out] = imgboard_exotic_magick('-format "%w %h" ' . escapeshellarg($src), true);
		if ($rc !== 0 || !isset($out[0]) || !preg_match('/^(\d+) (\d+)$/', trim($out[0]), $m))
			return $config['error']['invalidimg'];
		$w = (int)$m[1]; $h = (int)$m[2];
		if ($w < 1 || $h < 1 || $w > $config['max_width'] || $h > $config['max_height'] || $w * $h > 60000000)
			return $config['error']['maxsize'];
		$file->width = $w;
		$file->height = $h;

		// 2. thumbnail dimensions (same rule as the other file types)
		$maxw = $post->op ? $config['thumb_op_width'] : $config['thumb_width'];
		$maxh = $post->op ? $config['thumb_op_height'] : $config['thumb_height'];
		if ($w > $maxw || $h > $maxh) {
			$file->thumbwidth = max(1, min($maxw, (int)round($w * $maxh / $h)));
			$file->thumbheight = max(1, min($maxh, (int)round($h * $maxw / $w)));
		} else {
			$file->thumbwidth = $w;
			$file->thumbheight = $h;
		}

		// 3. spoiler, or a real thumbnail
		if ($config['spoiler_images'] && isset($_POST['spoiler'])) {
			$file->thumb = 'spoiler';
			$size = @getimagesize($config['spoiler_image']);
			$file->thumbwidth = $size[0];
			$file->thumbheight = $size[1];
			continue;
		}
		$tn_ext = $config['thumb_ext'] ?: 'png';
		$tn_path = $board['dir'] . $config['dir']['thumb'] . $file->file_id . '.' . $tn_ext;
		[$rc] = imgboard_exotic_magick(escapeshellarg($src) . ' -auto-orient -strip -thumbnail '
			. escapeshellarg($file->thumbwidth . 'x' . $file->thumbheight . '!') . ' ' . escapeshellarg($tn_ext . ':' . $tn_path));
		clearstatcache();
		$file->thumb = ($rc === 0 && @filesize($tn_path) > 0) ? $file->file_id . '.' . $tn_ext : 'file';
	}
}
