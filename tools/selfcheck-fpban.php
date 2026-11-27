<?php
// Self-check for the pure parts of fingerprint banning (inc/mod/pending.php).
// Run: docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-fpban.php
define('TINYBOARD', 1);
$config = ['cache' => ['enabled' => false]];
require __DIR__ . '/../inc/mod/pending.php';

$fail = 0;
function chk($cond, $msg) { global $fail; if (!$cond) { echo "FAIL $msg\n"; $fail++; } }

// network key: IPv4 /24, IPv6 /64
chk(imgboard_net_key('93.184.216.10') === '93.184.216.0/24', 'IPv4 /24');
chk(imgboard_net_key('93.184.216.255') === imgboard_net_key('93.184.216.1'), 'same /24');
chk(imgboard_net_key('93.184.217.1') !== imgboard_net_key('93.184.216.1'), 'other /24');
chk(imgboard_net_key('2a00:23c7:891d:ea01:e5f7:da55:c87c:15d5') === '2a00:23c7:891d:ea01::/64', 'IPv6 /64');
chk(imgboard_net_key('::ffff:93.184.216.10') === '93.184.216.0/24', 'IPv4-mapped address counts as IPv4');
// shared or meaningless networks never take part in device matching
foreach (['', 'junk', '127.0.0.1', '10.0.0.5', '172.18.0.4', '192.168.0.9', '::1', 'fdd0:0:0:0:1:2:3:4', 'fdd1::9', 'fe80::1', '::ffff:10.0.0.1', '100.64.0.1'] as $ip) {
	chk(imgboard_net_key($ip) === '', "no network key for [$ip]: " . imgboard_net_key($ip));
}

// identifiers: only well-formed ones are used
$uid = str_repeat('ab', 16);
chk(imgboard_visitor_ids(['imgboard_uid' => $uid, 'imgboard_fp' => 'deadbeefdeadbeef']) === [$uid, 'deadbeefdeadbeef'], 'good identifiers');
foreach ([[], ['imgboard_uid' => 'short', 'imgboard_fp' => 'xyz'], ['imgboard_uid' => $uid . "\n", 'imgboard_fp' => "deadbeef\n"],
		['imgboard_uid' => [$uid], 'imgboard_fp' => ['deadbeef']], ['imgboard_uid' => strtoupper($uid), 'imgboard_fp' => str_repeat('a', 33)]] as $bad) {
	chk(imgboard_visitor_ids($bad) === ['', ''], 'rejected identifiers: ' . json_encode($bad));
}

echo $fail ? "FAILED $fail\n" : "all fp-ban checks passed\n";
exit($fail ? 1 : 0);
