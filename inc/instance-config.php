<?php

/*
 *  Instance Configuration  --  example.com
 *  ----------------------------------------
 *  Edit this file (not config.php) for imageboard configuration.
 *  This file is copied into the running instance by docker/php/bootstrap.sh.
 */

	/* ---------------------------------------------------------------
	 * Database (read from Docker env, with local .env defaults)
	 * ------------------------------------------------------------- */
	$config['db']['type']     = 'mysql';
	$config['db']['server']   = getenv('VICHAN_MYSQL_HOST')     ?: 'db';
	$config['db']['user']     = getenv('VICHAN_MYSQL_USER')     ?: 'vichan';
	$config['db']['password'] = getenv('VICHAN_MYSQL_PASSWORD') ?: '';
	$config['db']['database'] = getenv('VICHAN_MYSQL_NAME')     ?: 'vichan';

	/* ---------------------------------------------------------------
	 * Site identity
	 * ------------------------------------------------------------- */
	$config['domain']        = 'example.com';
	$config['sitetitle']     = 'example.com';
	$config['root']          = '/';

	// Strip camera / GPS metadata from uploads without recompressing them (needs exiftool,
	// which docker/php/Dockerfile installs).
	$config['strip_exif']   = true;
	$config['use_exiftool'] = true;

	// ImgOps reverse-image-search link next to every image (the other engines stay off).
	$config['image_identification']        = true;
	$config['image_identification_imgops'] = true;
	$config['image_identification_exif']   = false;
	$config['image_identification_google'] = false;
	$config['image_identification_yandex'] = false;
	$config['image_identification_iqdb']   = false;

	// Board banner: b.php serves a random image from static/banners/, so every
	// banner dropped in there is active automatically. Fixed 300x100 stretches
	// each one to the standard banner shape, whatever its source dimensions.
	$config['url_banner']    = '/b.php';
	$config['banner_width']  = 300;
	$config['banner_height'] = 100;
	$config['url_favicon']   = '/favicon.ico';
	$config['logo']          = '';

	// Tiered identifier visibility in the mod post view (templates/post/ip.html)
	// and the ?/fp/ detail page. Linear permission model (type >= level):
	//   fp_raw      (Admin, 30)    - full un-hashed fingerprint signal breakdown
	//   show_ip     (Mod, 20)      - real IP + HWID + cookie + fp hash + ALT flag
	//                                (set in config.php) plus a bare-bones ?/fp/ page
	//   fp_hashed   (Janitor, 10)  - only an opaque hashed poster number
	//   fp_ip_token (Mediator, 5)  - only a super-hashed IP token, nothing else
	$config['mod']['fp_raw']      = 30; // Admin
	$config['mod']['fp_hashed']   = 10; // Janitor
	$config['mod']['fp_ip_token'] = 5;  // Mediator
	// Fingerprint bans: besides the per-browser cookie, also match the device fingerprint on
	// the same network (catches a banned user who cleared cookies; an identical phone on that
	// same network would be caught too). false = match the cookie only.
	$config['imgboard_fp_ban_device'] = true;
	// Bulk visitor fingerprint data (the ?/fingerprints list + .zip export of
	// every visitor's fingerprint .txt). Manager+ only - one tier above Admin.
	$config['mod']['fp_data']     = 35; // Manager

	// Janitors moderate directly with the full delete + ban control suite
	// ([D] [D+] [D++] [B] [B&D] [B&D+] [B&D++]). Mediators (5) still only propose.
	// Set here (re-runs on every loadConfig) so it survives the openBoard reload.
	$config['mod']['delete']            = 10; // JANITOR - [D]
	$config['mod']['deletebyip']        = 10; // JANITOR - [D+]  / [B&D+]
	$config['mod']['deletebyip_global'] = 10; // JANITOR - [D++] / [B&D++]
	$config['mod']['ban']               = 10; // JANITOR - [B]
	$config['mod']['bandelete']         = 10; // JANITOR - [B&D]

	// Root only: each of these lets its holder run code on the server or script in other staff
	// members' browsers, so lowering one hands that rank control of the whole site.
	$config['mod']['edit_config'] = 40; // config editor
	$config['mod']['themes']      = 40; // themes write files with chosen names
	$config['mod']['rawhtml']     = 40; // unfiltered HTML in posts and pages

	// DTEG (Device Tracking & Entropy Gathering): client browser fingerprinting.
	// Runs on every board/thread/index page, stamps a stable hash on posts
	// (imgboard_fp cookie) and ships the full un-hashed signal breakdown to fp.php
	// for the mod panel. Load order matters: dteg-collect.js defines window.DTEG
	// and runs the collection; dteg-detect.js and dteg-behavior.js register extra
	// collectors onto it. Appended here so it survives the per-board config reload.
	$config['additional_javascript'][] = 'js/dteg/dteg-collect.js';
	$config['additional_javascript'][] = 'js/dteg/dteg-detect.js';
	$config['additional_javascript'][] = 'js/dteg/dteg-behavior.js';

	// Persistent "you are previewing as <role>" banner + exit link, shown on any
	// page whenever the imgboard_viewas cookie is set (staff role-preview mode).
	$config['additional_javascript'][] = 'js/imgboard-viewas.js';

	// Staff approval-queue notifications (js/imgboard-staff-notify.js): a bell widget for
	// logged-in staff with Off / Online / Online+offline(push). Does nothing for ordinary
	// visitors (gated on the readable imgboard_staff cookie set at login).
	$config['additional_javascript'][] = 'js/imgboard-staff-notify.js';
	$config['mod']['custom_pages']['/notify-poll']        = 'notify_poll';
	$config['mod']['custom_pages']['/notify-subscribe']   = 'notify_subscribe';
	$config['mod']['custom_pages']['/notify-unsubscribe'] = 'notify_unsubscribe';

	// Web Push (offline notifications) VAPID keys. Empty = offline push stays off (online
	// still works). Generate with `php tools/gen-vapid.php` (needs minishlink/web-push,
	// `composer require minishlink/web-push`) and set them here or via the env vars.
	$config['imgboard_vapid'] = [
		'public'  => getenv('VICHAN_VAPID_PUBLIC')  ?: '',
		'private' => getenv('VICHAN_VAPID_PRIVATE') ?: '',
		'subject' => 'mailto:contact@example.com',
	];

	// Hovering a >>X reference inside a post highlights the post it refers to, in
	// place (the header post number does not).
	$config['additional_javascript'][] = 'js/hover-highlight.js';

	// Ctrl+Enter in a post form submits the post.
	$config['additional_javascript'][] = 'js/ctrl-enter-post.js';

	// Enable URL embedding (YouTube / Vimeo / Dailymotion / Rumble, etc. - the
	// providers are defined in config.php) so the post form's Select row offers
	// an "Embed" option alongside File / Oekaki / Poll.
	$config['enable_embedding'] = true;

	/* ---------------------------------------------------------------
	 * Behind a Cloudflare Tunnel: TLS is terminated at the edge, the
	 * backend speaks plain HTTP, so don't force secure-only cookies.
	 * ------------------------------------------------------------- */
	$config['cookies']['secure_login_only'] = (int)(getenv('VICHAN_SECURE_LOGIN_ONLY') ?: 0);

	// Salts are generated per instance (inc/salts.php, applied at the end of this file).
	// Set your own in inc/secrets.php if you want to manage them yourself.

	/* ---------------------------------------------------------------
	 * File Pending approval system
	 * ------------------------------------------------------------- */
	// Placeholder shown in place of a file until a mod approves it.
	$config['pending']['placeholder']       = 'static/imgboard/file-pending.png';
	$config['pending']['placeholder_thumb'] = 'static/imgboard/file-pending.png';
	$config['pending']['thumb_width']       = 140;  // displayed size (image is 420x150, same 2.8:1 ratio)
	$config['pending']['thumb_height']      = 50;   // (keep its 2.8:1 ratio, no stretch)

	// Permission levels (Janitor = 10, Mod = 20, Admin = 30).
	$config['mod']['approve_files']     = 20;  // approve pending files   (Mods + Admins)
	$config['mod']['approve_links']     = 20;  // approve pending links   (Mods + Admins)
	$config['mod']['edit_blocklists']   = 20;  // add/approve/delete      (Mods + Admins)
	$config['mod']['suggest_blocklists'] = 10; // suggest entries         (Janitors)

	// Custom moderator pages (dispatched by mod.php without editing core).
	$config['mod']['custom_pages']['/pending']                        = 'pending_files';
	$config['mod']['custom_pages']['/(\%b)/approvefile/(\d+)']        = 'secure approve_file';
	$config['mod']['custom_pages']['/pending-links']                  = 'pending_links';
	$config['mod']['custom_pages']['/(\%b)/approvelink/(\d+)']        = 'secure approve_link';
	$config['mod']['custom_pages']['/blocked-(words|links)']          = 'secure_POST blocklist';
	$config['mod']['custom_pages']['/blocked-(words|links)/approve/(\d+)'] = 'secure blocklist_approve';
	$config['mod']['custom_pages']['/blocked-(words|links)/delete/(\d+)']  = 'secure blocklist_delete';

	// Browser-fingerprint detail: full un-hashed signal breakdown, trust score,
	// detected spoof tells, and every sighting (IP / cookie / hwid) for a hash.
	$config['mod']['custom_pages']['/fp/([0-9a-f]{8,32})']                 = 'imgboard_fp_view';

	// User fingerprint data: every visitor (posters + lurkers) with their
	// fingerprint/hwid/cookie/IP, plus a .zip of per-visitor .txt files. The
	// zip route is registered before the list so it is matched first. Manager+.
	$config['mod']['custom_pages']['/fingerprints/zip']                    = 'imgboard_fp_zip';
	$config['mod']['custom_pages']['/fingerprints/zip-staff']              = 'imgboard_fp_zip_staff';
	$config['mod']['custom_pages']['/fingerprints']                        = 'secure_POST imgboard_fp_list';

	// "View as a lower role": preview the site as a rank below your own, or exit.
	$config['mod']['custom_pages']['/viewas/(off|user|\d+)']                    = 'imgboard_viewas';

	// Post Pending approval (PPA): hold non-staff posts for approval when on.
	//   ppa_toggle  (Admin/Root) - turn the system on/off
	//   ppa_approve (Mediator+)  - view the queue, approve/reject held posts (all boards the
	//                              account is assigned; Mediator accounts should have boards '*')
	$config['mod']['ppa_toggle']  = 30;
	$config['mod']['ppa_approve'] = 5;

	// Schizo (nuclear site reset): Manager (35) and Root (40). JSID and Root/
	// Manager role assignment stay owner-only (Root, 40), enforced in code.
	$config['mod']['schizo'] = 35;
	$config['mod']['custom_pages']['/ppa']                 = 'ppa';
	$config['mod']['custom_pages']['/ppa/toggle/(\%b)']   = 'secure ppa_toggle';
	$config['mod']['custom_pages']['/ppa/approve/(\d+)']   = 'secure ppa_approve';
	$config['mod']['custom_pages']['/ppa/reject/(\d+)']    = 'secure ppa_reject';

	/* ---------------------------------------------------------------
	 * Staff stay logged in via a mod cookie; logging out ends every session
	 * of that account. Optionally the
	 * session can be tied to the client IP (lock_ip) so a stolen cookie
	 * can't be replayed from another address.
	 *
	 * Trade-off: with lock_ip on, any staff member whose IP changes
	 * (mobile data, dynamic ISP, Cloudflare routing to a new client IP)
	 * has their session hash invalidated on the next request and is
	 * silently logged out. IPv6 is already softened to the /64 prefix in
	 * imgboard_ip_lock_key(), but dynamic IPv4 still rotates constantly,
	 * which is the usual cause of "I keep getting logged out".
	 *
	 * Default is OFF so staff stay logged in across IP changes. Set the
	 * VICHAN_LOCK_IP=1 env var to opt back into IP-pinned sessions if you
	 * accept the re-login churn in exchange for replay protection.
	 * Set here (re-runs on every loadConfig) so it survives the
	 * openBoard config reload.
	 * ------------------------------------------------------------- */
	$config['cookies']['expire'] = 60 * 60 * 24 * 30; // 30 days, enforced server-side too
	$config['mod']['lock_ip']    = getenv('VICHAN_LOCK_IP') === '1';

	// Recompile a Twig template whenever its source changes. config.php sets this false and
	// re-runs on every openBoard(), while pending.php (require_once) sets it true only once -
	// so after any openBoard() it was reverting to false, and edited templates (header.html,
	// imgboard_home.html, ...) kept serving the STALE compiled copy. Rendering the homepage
	// opens every board for stats, so the home template in particular was always stale. Set
	// here so it survives the openBoard reload. The per-render mtime check is cheap.
	$config['twig_auto_reload'] = true;

	// Let banned users appeal (the appeal form shows on the ban screen; pending appeals
	// surface under the Report queue and at ?/ban-appeals). Drives the auto-ban safety net.
	$config['ban_appeals'] = true;

	// Staff-only boards: viewable/postable only by logged-in staff, hidden from
	// the public board list and homepage. /j/ is the staff board (SIDs on);
	// /test/ is a staff scratch board for posting test content.
	$config['imgboard_staff_boards'] = [ 'j', 'test' ];

	// Software versions shown in the footer. vichan's own version comes from the
	// .installed file (config.version); set the Tinyboard base and dchan versions here.
	// Bump the dchan patch number (1.0.X) by one for every change to the software.
	$config['imgboard_tinyboard_version'] = '0.9.6';
	// vichan's version normally comes from the .installed file (config.version),
	// but that file doesn't exist until install.php runs, so the footer would show
	// a blank vichan version before/without install. Pin the upstream vichan
	// version here as a fallback; the footer prefers config.version when present.
	$config['imgboard_vichan_version']    = '5.2.2';
	$config['imgboard_dchan_version']     = '1.2.5';

	// Country flags: use the individual PNG files in static/flags/ (all present)
	// rather than the condensed CSS sprite, so flags render without extra setup.
	// The country code itself comes from Cloudflare's CF-IPCountry header (post.php).
	$config['country_flags_condensed'] = false;

	/* ---------------------------------------------------------------
	 * Cloudflare Turnstile (new-thread captcha). Enabled only when both
	 * keys are present in the environment; set them in .env on the host:
	 *   VICHAN_TURNSTILE_SITEKEY, VICHAN_TURNSTILE_SECRET
	 * ------------------------------------------------------------- */
	$config['imgboard_turnstile'] = [
		'sitekey' => getenv('VICHAN_TURNSTILE_SITEKEY') ?: '',
		'secret'  => getenv('VICHAN_TURNSTILE_SECRET') ?: '',
	];

	// Imgboard: Cloudflare Turnstile replaces the old no-JS image captcha for
	// new threads. Turn the image captcha off whenever Turnstile is configured, so
	// only one challenge shows (and the captcha.php flood target is retired).
	if (!empty($config['imgboard_turnstile']['sitekey']) && !empty($config['imgboard_turnstile']['secret'])) {
		$config['imgboard_captcha'] = false;
	}

	/* ---------------------------------------------------------------
	 * VPN/proxy detection for the mod IP indicator (proxycheck.io).
	 * Optional key (VICHAN_PROXYCHECK_KEY) raises the free quota.
	 * Set 'enabled' to false to turn the lookups off entirely.
	 * ------------------------------------------------------------- */
	$config['imgboard_vpn'] = [
		'enabled' => (getenv('VICHAN_VPN_CHECK') === '0') ? false : true,
		'api_key' => getenv('VICHAN_PROXYCHECK_KEY') ?: '',
		'ttl'     => 60 * 60 * 24 * 7, // cache each IP's result for 7 days
	];

	// StopForumSpam (stopforumspam.com): check poster IPs against the known-abuser list.
	// Free, no key. Shown on the mod IP page beside the address. Off by default (set the
	// env var to opt in); results cached per IP.
	$config['imgboard_sfs'] = [
		'enabled' => getenv('VICHAN_SFS_CHECK') === '1',
		'ttl'     => 60 * 60 * 24, // cache each IP's result for 1 day
		// Auto-reject new posts from IPs on the StopForumSpam abuser list (needs
		// 'enabled' too). This blocks known spammers only - VPN/proxy users are not
		// affected (that is the separate proxycheck signal, left as display-only).
		'reject'  => getenv('VICHAN_SFS_REJECT') === '1',
	];

	// Load the handlers for the pages above.
	require_once __DIR__ . '/mod/pending.php';

	// Apply per-board name overrides (default name / name field). This runs on
	// every loadConfig - including the openBoard reload, when $board is set -
	// whereas pending.php itself is require_once'd and skipped on that reload.
	imgboard_apply_board_overrides();

	@include('inc/secrets.php');

	// After secrets.php, so your own salts win and only public ones are replaced.
	require_once __DIR__ . '/salts.php';
	imgboard_replace_public_salts($config, __DIR__ . '/instance-salts.php');
