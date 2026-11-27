<?php
/*
 *  Per-instance salts. Every salt written in this public repo is known to everyone, so any
 *  that is still in use is swapped for a random one kept in a private file on this server.
 *  Salts you set yourself (inc/secrets.php) are left alone.
 */

defined('TINYBOARD') or exit;

const IMGBOARD_PUBLIC_SALTS = [
	'abcdefghijklmnopqrstuvwxyz09123456789!@#$%^&*()',  // config.php: cookies
	')(*&^%$#@!98765432190zyxwvutsrqponmlkjihgfedcba',  // config.php: tripcodes / poster IDs
	'wKJSb7M5SyzMcFWD2gPO3j2RYUSO9B789!@#$%^&*()',      // config.php: post passwords
	'imgboard-cookie-salt-change-me',                   // old instance-config.php values
	'imgboard-trip-salt-change-me',
];

// Starts the salt file, so a web server that ran it as PHP would print nothing.
const IMGBOARD_SALT_GUARD = "<?php exit; ?>\n";

// Read this instance's salts from $file, creating them on first use. Stops the site if they
// can be neither read nor written: running on public salts is never an option.
function imgboard_instance_salts(string $file): array {
	$parse = function ($raw) {
		$d = json_decode((string)substr((string)$raw, strlen(IMGBOARD_SALT_GUARD)), true);
		return isset($d['cookie'], $d['trip'], $d['password']) ? $d : null;
	};
	if ($salts = $parse(@file_get_contents($file))) {
		return $salts;
	}
	$fail = 'Cannot create ' . basename($file) . '. Make inc/ writable, or set the salts in inc/secrets.php.';
	// Locked, so simultaneous first requests all end up with the same salts.
	$h = @fopen($file, 'c+');
	if (!$h || !flock($h, LOCK_EX)) {
		die($fail);
	}
	if (!($salts = $parse(stream_get_contents($h)))) {
		$salts = [];
		foreach (['cookie', 'trip', 'password'] as $name) {
			$salts[$name] = bin2hex(random_bytes(32));
		}
		ftruncate($h, 0);
		rewind($h);
		if (!fwrite($h, IMGBOARD_SALT_GUARD . json_encode($salts)) || !fflush($h)) {
			die($fail);
		}
	}
	fclose($h);
	return $salts;
}

function imgboard_replace_public_salts(array &$config, string $file): void {
	$slots = [
		'cookie'   => &$config['cookies']['salt'],
		'trip'     => &$config['secure_trip_salt'],
		'password' => &$config['secure_password_salt'],
	];
	foreach ($slots as $name => &$salt) {
		if (empty($salt) || in_array($salt, IMGBOARD_PUBLIC_SALTS, true)) {
			$salt = imgboard_instance_salts($file)[$name];
		}
	}
}
