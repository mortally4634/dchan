<?php
/*
 *  example.com - killswitch public page.
 *  Served by nginx (via rewrite) for every public page while the site is down.
 *  Renders a plain, raw HTML message. Staff keep operating through mod.php.
 */
require_once 'inc/bootstrap.php';

$mode  = function_exists('imgboard_killswitch_mode')  ? imgboard_killswitch_mode()  : 'none';
$since = function_exists('imgboard_killswitch_since') ? imgboard_killswitch_since() : 0;

// Flag file present but mode cleared (shouldn't happen) -> send them home.
if ($mode === 'none') {
	header('Location: /', true, 302);
	exit;
}

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Retry-After: 3600');

if ($mode === 'jsid') {
	echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>JSID</title></head><body>\nJSID\n</body></html>\n";
	exit;
}

// Temp-JSID: message + down-since timestamps + a live elapsed timer.
$since_hms   = gmdate('H:i:s', $since);
$since_human = gmdate('l, j F Y \a\t H:i:s', $since) . ' UTC';
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Temp-JSID</title></head>
<body>
Temp-JSID, the site will be right back up. :)
<br><br>
Site has been down since: <?php echo $since_hms; ?>

<br>
Site has been down since: <?php echo htmlspecialchars($since_human, ENT_QUOTES); ?>

<br><br>
<span id="elapsed">Site has been down for: 00:00:00</span>
<script>
(function(){
  var since = <?php echo (int) $since; ?> * 1000;
  function pad(n){ return (n < 10 ? '0' : '') + n; }
  function tick(){
    var s = Math.max(0, Math.floor((Date.now() - since) / 1000));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    document.getElementById('elapsed').textContent =
      'Site has been down for: ' + pad(h) + ':' + pad(m) + ':' + pad(x);
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
</body>
</html>
