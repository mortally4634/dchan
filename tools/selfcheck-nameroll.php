<?php
// Self-check for the nameroll logic (inc/mod/pending.php). It extracts the two pure functions
// and checks them. Run on the server:  docker compose exec -T php php < tools/selfcheck-nameroll.php
// (the pending.php path below is the deployed one inside the php container)
$src = file_get_contents('/code/inc/mod/pending.php');
preg_match('/^const IMGBOARD_NAMEROLL_MAX_LEN.*?^const IMGBOARD_NAMEROLL_MAX_ENTRIES[^\n]*\n/ms', $src, $c);
preg_match('/^function imgboard_nameroll_parse.*?^}\r?\n/ms', $src, $f1);
preg_match('/^function imgboard_nameroll_pick.*?^}\r?\n/ms', $src, $f2);
if (!$c || !$f1 || !$f2) { echo "FAIL: could not find the nameroll code\n"; exit(1); }
eval(str_replace('<?php', '', $c[0] . $f1[0] . $f2[0]));
// stand-ins for the site helpers the function calls
function imgboard_is_staff_board($u) { return in_array($u, ['j', 'test'], true); }
$GLOBALS['__list'] = '';
function imgboard_setting_get($k, $d = '') { return $GLOBALS['__list']; }

$fail = 0;
$check = function ($label, $ok) use (&$fail) { if (!$ok) { echo "FAIL: $label\n"; $fail++; } };

// parse
$p = imgboard_nameroll_parse("  Anonymous \r\n\r\nAnon2\nAnonymous\nbad#trip\n\t\x01weird\x02name  \n" . str_repeat('x', 80));
$check('trims, drops blanks and repeats', array_slice($p, 0, 2) === ['Anonymous', 'Anon2']);
$check('removes # (tripcode marker)', in_array('badtrip', $p, true));
$check('removes control characters', in_array('weirdname', $p, true));
$check('cuts to 35 characters', mb_strlen(end($p)) === 35);
$check('caps the entry count', count(imgboard_nameroll_parse(implode("\n", range(1, 900)))) === 500);

// pick
$GLOBALS['__list'] = '';
$check('empty list = no roll', imgboard_nameroll_pick('hello', 'q') === null);
$GLOBALS['__list'] = "A\nB\nC";
$seen = [];
for ($i = 0; $i < 200; $i++) $seen[imgboard_nameroll_pick('plain post', 'q')] = true;
$check('rolls only from the list', !array_diff(array_keys($seen), ['A', 'B', 'C']));
$check('actually varies', count($seen) === 3);
$check('">" at the start opts out', imgboard_nameroll_pick('>greentext', 'q') === null);
$check('">" in the middle opts out', imgboard_nameroll_pick("hi\n>quote", 'q') === null);
$check('reply link ">>123" opts out', imgboard_nameroll_pick('>>123 nice', 'q') === null);
$check('staff board is never rolled', imgboard_nameroll_pick('plain', 'j') === null);

echo $fail ? "FAILED $fail\n" : "all nameroll checks passed\n";
exit($fail ? 1 : 0);
