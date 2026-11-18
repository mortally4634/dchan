<?php
/*
 *  Steganography check (?/steg/<board>/<file>) - read-only staff tool.
 *
 *  Looks for data hidden in an uploaded image:
 *    - bytes appended after the image's real end (PNG/JPEG/GIF/WebP parsed properly),
 *    - other files embedded in it (ZIP, RAR, 7z, gzip, PDF ... signatures),
 *    - odd metadata: comments, text chunks, XMP, unusual PNG chunks,
 *    - readable text / files hidden in the lowest bits of the pixels (LSB),
 *    - a bit-plane viewer (each colour channel, each bit) to spot hidden patterns by eye.
 *
 *  The analysis functions are pure (a string or array in, an array out) so tools/selfcheck-steg.php
 *  can test them. Pixel work uses ImageMagick; formats are forced (png:/jpeg:/gif:/...) so an
 *  upload can never make it pick a risky coder.
 */
use Vichan\Context;

defined('TINYBOARD') or exit;

const IMGBOARD_STEG_MAX_BYTES   = 30 * 1024 * 1024;
const IMGBOARD_STEG_MAX_PIXELS  = 9000000;    // refuse to decode anything bigger (memory: Q16-HDRI, ~16 bytes/pixel x2)
const IMGBOARD_STEG_SCAN_PIXELS = 262144;     // how many pixels (from the top) are searched for LSB data

// Permission level for the tool. Read with a fallback because settings made in pending.php do not
// survive a per-board config reload (see the notes in inc/instance-config.php).
function imgboard_steg_level(): int {
	global $config;
	return (int)($config['mod']['steg'] ?? 5);
}

/* ------------------------------ file formats ------------------------------ */

// Magic numbers searched for inside a file. [name => bytes]
function imgboard_steg_signatures(): array {
	return [
		'ZIP archive'        => "PK\x03\x04",
		'RAR archive'        => "Rar!\x1a\x07",
		'7-Zip archive'      => "7z\xbc\xaf\x27\x1c",
		'GZIP data'          => "\x1f\x8b\x08",
		'bzip2 data'         => "BZh",
		'XZ data'            => "\xfd7zXZ\x00",
		'PNG image'          => "\x89PNG\r\n\x1a\n",
		'JPEG image'         => "\xff\xd8\xff",
		'GIF image'          => "GIF8",
		'PDF document'       => "%PDF-",
		'ELF program'        => "\x7fELF",
		'Windows program'    => "This program cannot be run in DOS mode",
		'Ogg media'          => "OggS",
		'MP3 (ID3 tag)'      => "ID3",
		'SQLite database'    => "SQLite format 3\x00",
		'Matroska/WebM'      => "\x1a\x45\xdf\xa3",
	];
}

// The type of the image: png / jpeg / gif / webp / bmp, or 'other' (video, unknown).
function imgboard_steg_type(string $d): string {
	if (strncmp($d, "\x89PNG\r\n\x1a\n", 8) === 0) return 'png';
	if (strncmp($d, "\xff\xd8\xff", 3) === 0)       return 'jpeg';
	if (strncmp($d, 'GIF8', 4) === 0)               return 'gif';
	if (strncmp($d, 'RIFF', 4) === 0 && substr($d, 8, 4) === 'WEBP') return 'webp';
	if (strncmp($d, 'BM', 2) === 0)                 return 'bmp';
	return 'other';
}

// Chunk names a normal PNG uses. Anything else is "unusual" - a favourite place to hide data.
function imgboard_steg_known_png_chunks(): array {
	return ['IHDR','PLTE','IDAT','IEND','cHRM','gAMA','iCCP','sBIT','sRGB','bKGD','hIST','tRNS','pHYs',
		'sPLT','tIME','iTXt','tEXt','zTXt','eXIf','acTL','fcTL','fdAT','oFFs','pCAL','sCAL','sTER','cICP','mDCV','cLLI'];
}

// PNG: where the image really ends, text chunks, unusual chunks.
function imgboard_steg_png(string $d): array {
	$out = ['end' => null, 'text' => [], 'unusual' => []];
	$n = strlen($d);
	$o = 8;
	while ($o + 12 <= $n) {
		$len  = unpack('N', substr($d, $o, 4))[1];
		$type = substr($d, $o + 4, 4);
		if ($o + 12 + $len > $n) break;               // chunk runs past the file: stop, don't guess
		$data = substr($d, $o + 8, $len);
		if ($type === 'tEXt') {
			$p = strpos($data, "\0");
			if ($p !== false) $out['text'][] = ['PNG tEXt: ' . substr($data, 0, $p), substr($data, $p + 1)];
		} elseif ($type === 'zTXt') {
			$p = strpos($data, "\0");
			if ($p !== false) {
				$t = @gzuncompress(substr($data, $p + 2));
				$out['text'][] = ['PNG zTXt: ' . substr($data, 0, $p), $t === false ? '(could not decompress)' : $t];
			}
		} elseif ($type === 'iTXt') {
			$p = strpos($data, "\0");
			if ($p !== false) {
				$comp = ord($data[$p + 1] ?? "\0");
				$rest = substr($data, $p + 3);
				$q1 = strpos($rest, "\0");                                   // language tag
				$q2 = $q1 === false ? false : strpos($rest, "\0", $q1 + 1);  // translated keyword
				$t = $q2 === false ? '' : substr($rest, $q2 + 1);
				if ($comp === 1) { $t2 = @gzuncompress($t); $t = $t2 === false ? '(could not decompress)' : $t2; }
				$out['text'][] = ['PNG iTXt: ' . substr($data, 0, $p), $t];
			}
		} elseif (!in_array($type, imgboard_steg_known_png_chunks(), true)) {
			$out['unusual'][] = ['name' => preg_replace('/[^A-Za-z0-9]/', '?', $type), 'offset' => $o, 'length' => $len, 'head' => substr($data, 0, 48)];
		}
		$o += 12 + $len;
		if ($type === 'IEND') { $out['end'] = $o; break; }
	}
	return $out;
}

// JPEG: where the image really ends (final EOI), comments, application segments, XMP.
function imgboard_steg_jpeg(string $d): array {
	$out = ['end' => null, 'comments' => [], 'apps' => [], 'xmp' => null];
	$n = strlen($d);
	$o = 2;
	while ($o + 4 <= $n) {
		if ($d[$o] !== "\xff") break;
		$m = ord($d[$o + 1]);
		if ($m === 0xff) { $o++; continue; }                       // fill byte
		if ($m === 0xd9) { $out['end'] = $o + 2; break; }          // EOI
		if ($m === 0x01 || ($m >= 0xd0 && $m <= 0xd7)) { $o += 2; continue; }
		$len = unpack('n', substr($d, $o + 2, 2))[1];
		if ($len < 2) break;
		$seg = substr($d, $o + 4, $len - 2);
		if ($m === 0xfe) {
			$out['comments'][] = $seg;
		} elseif ($m >= 0xe0 && $m <= 0xef) {
			$id = strstr($seg, "\0", true);
			$id = $id === false ? substr($seg, 0, 16) : $id;
			$out['apps'][] = 'APP' . ($m - 0xe0) . ' ' . preg_replace('/[^\x20-\x7e]/', '?', substr($id, 0, 40)) . ' (' . $len . ' bytes)';
			if ($m === 0xe1 && strncmp($seg, 'http://ns.adobe.com/xap/1.0/', 28) === 0) $out['xmp'] = substr($seg, 29);
		}
		$o += 2 + $len;
		if ($m === 0xda) {                  // start of scan: skip the compressed data to the next real marker
			while ($o + 1 < $n) {
				if ($d[$o] === "\xff") {
					$nx = ord($d[$o + 1]);
					if ($nx !== 0 && $nx !== 0xff && !($nx >= 0xd0 && $nx <= 0xd7)) break;
				}
				$o++;
			}
		}
	}
	return $out;
}

// GIF: where the image really ends (the trailer) and comment blocks.
function imgboard_steg_gif(string $d): array {
	$out = ['end' => null, 'comments' => []];
	$n = strlen($d);
	if ($n < 14) return $out;
	$flags = ord($d[10]);
	$o = 13;
	if ($flags & 0x80) $o += 3 * (1 << (($flags & 7) + 1));
	while ($o < $n) {
		$b = ord($d[$o]);
		if ($b === 0x3b) { $out['end'] = $o + 1; break; }
		if ($b === 0x21) {                                      // extension block
			$label = ord($d[$o + 1] ?? "\0");
			$o += 2; $buf = '';
			while ($o < $n && ($sz = ord($d[$o])) !== 0) { $buf .= substr($d, $o + 1, $sz); $o += 1 + $sz; }
			$o++;
			if ($label === 0xfe) $out['comments'][] = $buf;
		} elseif ($b === 0x2c) {                                // image block
			$f = ord($d[$o + 9] ?? "\0");
			$o += 10;
			if ($f & 0x80) $o += 3 * (1 << (($f & 7) + 1));
			$o++;                                               // LZW minimum code size
			while ($o < $n && ord($d[$o]) !== 0) $o += 1 + ord($d[$o]);
			$o++;
		} else {
			break;
		}
	}
	return $out;
}

// WebP: the RIFF header declares the file length; anything after it is appended.
function imgboard_steg_webp(string $d): array {
	$end = strlen($d) >= 8 ? 8 + unpack('V', substr($d, 4, 4))[1] : null;
	return ['end' => ($end !== null && $end <= strlen($d)) ? $end : null];
}

// Every place one of the signatures appears (offset 0 is the file's own type and is skipped).
function imgboard_steg_find_signatures(string $d): array {
	$found = [];
	foreach (imgboard_steg_signatures() as $name => $magic) {
		$from = 1;
		$hits = 0;
		while (($p = strpos($d, $magic, $from)) !== false && $hits < 5) {
			$note = '';
			if ($name === 'JPEG image') $note = 'may just be an embedded EXIF thumbnail';
			if ($name === 'MP3 (ID3 tag)' || $name === 'GIF image') $note = 'short signature, could be a coincidence';
			$found[] = ['name' => $name, 'offset' => $p, 'note' => $note];
			$from = $p + 1;
			$hits++;
		}
	}
	usort($found, fn($a, $b) => $a['offset'] <=> $b['offset']);
	return array_slice($found, 0, 40);
}

// Printable strings of at least $min characters.
function imgboard_steg_strings(string $d, int $min, int $limit): array {
	if (!preg_match_all('/[\x20-\x7e]{' . $min . ',}/', $d, $m)) return [];
	return array_slice($m[0], 0, $limit);
}

// Bytes -> a safe one-line preview (non-printable shown as a dot).
function imgboard_steg_preview(string $s, int $max = 200): string {
	return preg_replace('/[^\x20-\x7e]/', '·', substr($s, 0, $max));
}

/* ------------------------------ pixel (LSB) work ------------------------------ */

// Pack a list of bits (0/1) into bytes, either first-bit-is-most-significant or the reverse.
function imgboard_steg_pack_bits(array $bits, bool $msb_first, int $max_bytes): string {
	$out = '';
	$n = min(count($bits), $max_bytes * 8);
	for ($i = 0; $i + 8 <= $n; $i += 8) {
		$v = 0;
		for ($k = 0; $k < 8; $k++) $v |= $bits[$i + $k] << ($msb_first ? 7 - $k : $k);
		$out .= chr($v);
	}
	return $out;
}

// Share of bytes that look like text.
function imgboard_steg_printable_ratio(string $s): float {
	$n = strlen($s);
	if ($n === 0) return 0.0;
	$ok = 0;
	for ($i = 0; $i < $n; $i++) {
		$c = ord($s[$i]);
		if (($c >= 32 && $c <= 126) || $c === 9 || $c === 10 || $c === 13) $ok++;
	}
	return $ok / $n;
}

// $px = flat list of 8-bit samples (R,G,B[,A] per pixel); $channels = 3 or 4.
// Reads the chosen bit of each sample in several orders and reports the streams that look like
// text or start with a known file signature.
function imgboard_steg_lsb_candidates(array $px, int $channels, int $bit = 0): array {
	$count = intdiv(count($px), $channels);
	$names = [0 => 'R', 1 => 'G', 2 => 'B', 3 => 'A'];
	$streams = [];
	for ($c = 0; $c < $channels; $c++) {
		$bits = [];
		for ($i = 0; $i < $count; $i++) $bits[] = ($px[$i * $channels + $c] >> $bit) & 1;
		$streams[$names[$c]] = $bits;
	}
	$rgb = [];
	for ($i = 0; $i < $count; $i++) for ($c = 0; $c < 3; $c++) $rgb[] = ($px[$i * $channels + $c] >> $bit) & 1;
	$streams['RGB'] = $rgb;

	$sigs = imgboard_steg_signatures();
	$cands = [];
	foreach ($streams as $label => $bits) {
		foreach ([true, false] as $msb) {
			$bytes = imgboard_steg_pack_bits($bits, $msb, 8192);
			if (strlen($bytes) < 16) continue;
			// A hidden message is a run of readable characters at the start, usually followed by padding.
			$run = preg_match('/^[\x20-\x7e\t\r\n]{8,}/', $bytes, $mm) ? strlen($mm[0]) : 0;
			$magic = null;
			foreach ($sigs as $name => $m) if (strncmp($bytes, $m, strlen($m)) === 0) { $magic = $name; break; }
			if ($run >= 8 || $magic !== null) {
				$cands[] = [
					'stream' => $label, 'bit' => $bit, 'order' => $msb ? 'MSB first' : 'LSB first',
					'run'    => $run, 'magic' => $magic,
					'text'   => imgboard_steg_preview($bytes, 300),
					'hex'    => bin2hex(substr($bytes, 0, 16)),
				];
			}
		}
	}
	usort($cands, fn($a, $b) => $b['run'] <=> $a['run']);
	return array_slice($cands, 0, 6);
}

// Percentage of 1s in each of the lowest bits, per channel (random-looking data sits near 50%).
function imgboard_steg_bit_stats(array $px, int $channels): array {
	$count = intdiv(count($px), $channels);
	$names = [0 => 'R', 1 => 'G', 2 => 'B', 3 => 'A'];
	$stats = [];
	if ($count === 0) return $stats;
	for ($c = 0; $c < $channels; $c++) {
		for ($b = 0; $b < 3; $b++) {
			$ones = 0;
			for ($i = 0; $i < $count; $i++) $ones += ($px[$i * $channels + $c] >> $b) & 1;
			$stats[$names[$c]][$b] = round(100 * $ones / $count, 1);
		}
	}
	return $stats;
}

/* ------------------------------ the full report ------------------------------ */

// Everything about one file. $path must already be a validated file inside a board's src/.
function imgboard_steg_analyze(string $path): array {
	$d = (string)file_get_contents($path);
	$n = strlen($d);
	$type = imgboard_steg_type($d);
	$r = [
		'size' => $n, 'type' => $type, 'md5' => md5($d), 'sha1' => sha1($d),
		'width' => null, 'height' => null,
		'appended' => null, 'signatures' => [], 'metadata' => [], 'unusual' => [],
		'strings' => [], 'appended_strings' => [], 'lsb' => null, 'flags' => [],
	];
	$info = @getimagesize($path);
	if ($info) { $r['width'] = $info[0]; $r['height'] = $info[1]; }

	// Where the image ends, and what else the format tells us.
	$end = null;
	if ($type === 'png') {
		$p = imgboard_steg_png($d); $end = $p['end']; $r['metadata'] = $p['text']; $r['unusual'] = $p['unusual'];
	} elseif ($type === 'jpeg') {
		$j = imgboard_steg_jpeg($d); $end = $j['end'];
		foreach ($j['comments'] as $c) $r['metadata'][] = ['JPEG comment', $c];
		foreach ($j['apps'] as $a) $r['metadata'][] = ['JPEG segment', $a];
		if ($j['xmp']) $r['metadata'][] = ['XMP packet', substr($j['xmp'], 0, 3000)];
	} elseif ($type === 'gif') {
		$g = imgboard_steg_gif($d); $end = $g['end'];
		foreach ($g['comments'] as $c) $r['metadata'][] = ['GIF comment', $c];
	} elseif ($type === 'webp') {
		$end = imgboard_steg_webp($d)['end'];
	}
	if ($end !== null && $end < $n) {
		$tail = substr($d, $end);
		$r['appended'] = [
			'offset' => $end, 'length' => $n - $end,
			'hex' => bin2hex(substr($tail, 0, 64)), 'preview' => imgboard_steg_preview($tail, 300),
			'kind' => null,
		];
		foreach (imgboard_steg_signatures() as $name => $m) if (strncmp($tail, $m, strlen($m)) === 0) { $r['appended']['kind'] = $name; break; }
		$r['appended_strings'] = imgboard_steg_strings($tail, 6, 60);
		$r['flags'][] = ['warn', number_format($n - $end) . ' bytes are appended after the end of the image' . ($r['appended']['kind'] ? ' (starts like a ' . $r['appended']['kind'] . ')' : '') . '.'];
	}
	$r['signatures'] = imgboard_steg_find_signatures($d);
	foreach ($r['signatures'] as $s) {
		if ($s['note'] === '') $r['flags'][] = ['warn', 'A ' . $s['name'] . ' signature appears at byte ' . number_format($s['offset']) . '.'];
	}
	foreach ($r['unusual'] as $u) $r['flags'][] = ['warn', 'Unusual PNG chunk "' . $u['name'] . '" (' . number_format($u['length']) . ' bytes) at byte ' . number_format($u['offset']) . '.'];
	foreach ($r['metadata'] as $m) if (strpos($m[0], 'comment') !== false || strpos($m[0], 'PNG') === 0) $r['flags'][] = ['info', $m[0] . ' present.'];

	// Whole-file strings (long ones only: short ones in compressed data are noise).
	$r['strings'] = imgboard_steg_strings($d, 16, 40);

	// Pixel LSB search.
	if (in_array($type, ['png', 'jpeg', 'gif', 'webp', 'bmp'], true) && $info && class_exists('Imagick')) {
		$r['lsb'] = imgboard_steg_lsb($path, $type, $info[0], $info[1]);
		if (!empty($r['lsb']['candidates'])) $r['flags'][] = ['warn', 'The lowest bit of the pixels contains data that looks like text or a file.'];
	}
	return $r;
}

// The ImageMagick coder to force for each type.
function imgboard_steg_coder(string $type): string {
	return ['png' => 'png', 'jpeg' => 'jpeg', 'gif' => 'gif', 'webp' => 'webp', 'bmp' => 'bmp'][$type] ?? '';
}

function imgboard_steg_lsb(string $path, string $type, int $w, int $h): array {
	$res = ['supported' => true, 'note' => '', 'scanned' => 0, 'stats' => [], 'candidates' => []];
	if ($w * $h > IMGBOARD_STEG_MAX_PIXELS) return ['supported' => false, 'note' => 'Image is too large to decode (' . number_format($w * $h) . ' pixels).'] + $res;
	if ($type === 'jpeg') $res['note'] = 'JPEG stores compressed blocks, so pixel LSBs are noise; tools like steghide/outguess hide data in the compressed coefficients, which this check cannot see.';
	try {
		$im = new Imagick();
		$im->readImage(imgboard_steg_coder($type) . ':' . $path);
		$im->setIteratorIndex(0);
		$im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
		$alpha = false;
		try { $alpha = (bool)$im->getImageAlphaChannel(); } catch (Throwable $e) {}
		$channels = $alpha ? 4 : 3;
		$rows = max(1, min($h, (int)ceil(IMGBOARD_STEG_SCAN_PIXELS / max(1, $w))));
		$px = $im->exportImagePixels(0, 0, $w, $rows, $alpha ? 'RGBA' : 'RGB', Imagick::PIXEL_CHAR);
		$res['scanned'] = $w * $rows;
		$res['stats'] = imgboard_steg_bit_stats($px, $channels);
		$res['candidates'] = imgboard_steg_lsb_candidates($px, $channels, 0);
		$im->clear();
	} catch (Throwable $e) {
		$res['supported'] = false;
		$res['note'] = 'Could not decode the pixels: ' . $e->getMessage();
	}
	return $res;
}

// One bit-plane as a PNG: white = the bit is set. $channel is R, G, B or RGB (colour-coded:
// red/green/blue show which channels have the bit set).
function imgboard_steg_plane(string $path, string $type, string $channel, int $bit): string {
	$im = new Imagick();
	$im->readImage(imgboard_steg_coder($type) . ':' . $path);
	$im->setIteratorIndex(0);
	$im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
	$map = ['R' => Imagick::CHANNEL_RED, 'G' => Imagick::CHANNEL_GREEN, 'B' => Imagick::CHANNEL_BLUE];
	if (isset($map[$channel])) $im->separateImageChannel($map[$channel]);
	$bit = max(0, min(7, $bit));
	// fxImage returns a NEW image. value = (floor(v / 2^bit)) mod 2, with v the 0..255 sample.
	$plane = $im->fxImage('mod(floor(floor(u*255+0.5)/pow(2,' . $bit . ')),2)');
	$plane->setImageFormat('png');
	$blob = $plane->getImageBlob();
	$plane->clear(); $im->clear();
	return $blob;
}

/* ------------------------------ the page ------------------------------ */

function mod_steg(Context $ctx, $uri, $file) {
	global $mod, $config, $board;
	if (!hasPermission(imgboard_steg_level()))
		error($config['error']['noaccess']);
	if (!openBoard($uri))
		error($config['error']['noboard']);
	if (!hasPermission(imgboard_steg_level(), $board['uri']))
		error($config['error']['noaccess']);

	// Only a plain file name inside this board's src/ directory.
	if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,100}$/', (string)$file) || strpos($file, '..') !== false)
		error('Invalid file name.');
	$path = $board['dir'] . $config['dir']['img'] . $file;
	if (!is_file($path)) error('File not found.');
	if (filesize($path) > IMGBOARD_STEG_MAX_BYTES) error('File is too large to analyse.');
	@set_time_limit(120);
	@ini_set('memory_limit', '512M');

	$d = (string)file_get_contents($path, false, null, 0, 16);
	$type = imgboard_steg_type($d);

	// Bit-plane image request.
	if (isset($_GET['plane'])) {
		$channel = in_array($_GET['plane'], ['R', 'G', 'B', 'RGB'], true) ? $_GET['plane'] : 'RGB';
		$bit = isset($_GET['bit']) ? (int)$_GET['bit'] : 0;
		$info = @getimagesize($path);
		if (!$info || imgboard_steg_coder($type) === '' || !class_exists('Imagick') || $info[0] * $info[1] > IMGBOARD_STEG_MAX_PIXELS)
			error('Bit planes are not available for this file.');
		try {
			$blob = imgboard_steg_plane($path, $type, $channel, $bit);
		} catch (Throwable $e) {
			error('Could not build the bit plane.');
		}
		header('Content-Type: image/png');
		header('Cache-Control: private, max-age=300');
		header('X-Content-Type-Options: nosniff');
		echo $blob;
		return;
	}

	$report = imgboard_steg_analyze($path);
	$view_channel = in_array($_GET['v'] ?? '', ['R', 'G', 'B', 'RGB'], true) ? $_GET['v'] : 'RGB';
	$view_bit = isset($_GET['b']) ? max(0, min(7, (int)$_GET['b'])) : 0;
	$base = '?/steg/' . $board['uri'] . '/' . $file;

	echo mod_page('Steganography check', 'mod/steg.html', [
		'report'       => $report,
		'board'        => $board['uri'],
		'file'         => $file,
		'file_url'     => $config['root'] . $board['uri'] . '/' . $config['dir']['img'] . $file,
		'thumb_ok'     => in_array($type, ['png', 'jpeg', 'gif', 'webp', 'bmp'], true),
		'planes_ok'    => $report['lsb'] !== null && $report['lsb']['supported'],
		'base'         => $base,
		'view_channel' => $view_channel,
		'view_bit'     => $view_bit,
		'plane_url'    => $base . '&plane=' . $view_channel . '&bit=' . $view_bit,
	], $mod);
}
