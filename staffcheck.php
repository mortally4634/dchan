<?php
/*
 *  staffcheck.php  --  nginx auth_request target for the staff-only boards (/j/, /test/)
 *  ----------------------------------------------------------------------------------
 *  nginx asks this before serving any static file under those boards. Answers 204 only for
 *  a genuine staff session (the same check_login() the mod panel uses); 403 for everything
 *  else. Fail closed: the status starts at 403, so an early exit() inside check_login (e.g.
 *  on a malformed cookie) can never turn into an "allowed" 200.
 *  Not reachable from outside: nginx marks /_staffcheck "internal".
 */
http_response_code(403);
require 'inc/bootstrap.php';
global $config, $mod;
$mod = false;
check_login(Vichan\build_context($config), false);
if ($mod) {
	http_response_code(204);
}
