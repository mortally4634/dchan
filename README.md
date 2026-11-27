# dchan

A vichan fork used as a starting point for new imageboards. It is stock vichan plus a
staff-moderation toolkit, anti-abuse defences and a Docker stack. All site-specific
branding is stripped: the placeholders are `example.com`, the `imgboard` name/prefix
(functions, tables, JS, templates) and a `meta` board. Rename them to taste.

## Install

The whole stack runs in containers (nginx, php-fpm, MySQL, Redis, plus optional Cloudflare
Tunnel, Tor and I2P). The only things you install on the host are **Git** and **Docker with
the Compose plugin** - nginx, PHP, MySQL and Redis all live inside the containers, so you do
not install those yourself.

### Install the components (per OS)

**Windows 10 / 11**
- Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) (it bundles Compose). It needs WSL 2: Docker Desktop can turn it on for you, or run `wsl --install` in an elevated PowerShell and reboot.
- Install [Git](https://git-scm.com/download/win), or `winget install Git.Git`.
- Start Docker Desktop (wait until it says "running"), then run the commands below in PowerShell or Git Bash.

**macOS**
- Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) (choose the Apple-silicon or Intel build), or `brew install --cask docker`, then launch it once.
- Git comes with the Xcode command-line tools: `xcode-select --install` (or `brew install git`).

**Linux**
- Docker Engine + the Compose plugin:
  - Debian / Ubuntu: `sudo apt install docker.io docker-compose-plugin`
  - Fedora: `sudo dnf install docker docker-compose-plugin`
  - Arch: `sudo pacman -S docker docker-compose`
  - or the official, newest install: <https://docs.docker.com/engine/install/>
- Start it and allow non-root use: `sudo systemctl enable --now docker`, then `sudo usermod -aG docker $USER` and log out/in.
- Git: `sudo apt install git` / `sudo dnf install git` / `sudo pacman -S git`.

Confirm the host is ready before continuing (all three should print a version):

```bash
docker --version
docker compose version
git --version
```

>OYY DOCTOS "==cp== .env.example" OYYYY
```bash
git clone <your-fork-url> dchan && cd dchan
git submodule update --init --recursive   # js/wPaint, js/twemoji, inc/lib/parsedown
cp .env.example .env                       # then edit: set every password/token
docker compose up -d --build
```

`install.sql` seeds the schema on first boot. No account is seeded. Run this to create
the `admin` owner account, build the static pages and create the two staff-only boards
(`/j/` and `/test/`):

```bash
docker compose exec -T -u www-data -w /var/www php php tools/imgboard-rebuild.php
```

**The first run prints the `admin` password once.** Save it, log in at
`http://127.0.0.1:9080/mod.php`, then change it.

This is idempotent, so re-run it after any code change. It creates `/j/` (staff board,
Staff IDs on) and `/test/` (staff scratch board) if they don't exist, seeds the Root owner,
and rebuilds every board and thread. Both boards are gated to logged-in staff at the web
server (`staffcheck.php` + the `j|test` rule in `docker/nginx/vichan.conf`) and hidden from
the public board list and overboard; the staff-board list is `$config['imgboard_staff_boards']`
in `inc/instance-config.php`.

Notes:
- Edit `.env` before the first `up`: fill in `VICHAN_MYSQL_PASSWORD`, the three `MYSQL_*`
  passwords, `VICHAN_CACHE_PASSWORD`, and (if you use the tunnel) `TUNNEL_TOKEN`.
  The MySQL password must match on both the `VICHAN_MYSQL_PASSWORD` and `MYSQL_PASSWORD` lines.
- The site binds to `127.0.0.1:9080` only. It is meant to sit behind Cloudflare (Tunnel or
  proxy), which supplies the real IP and country headers. To reach it directly on a LAN,
  change the `ports` line in `compose.yml`.
- To run without the Cloudflare Tunnel, Tor or I2P, remove or comment out those services in
  `compose.yml`.
- Data lives in `./local-instances/<INSTANCE>/` (bind-mounted www and db). Set `INSTANCE` in
  `.env` to run more than one site from the same checkout.
- Reset the database by stopping the stack and deleting `./local-instances/<INSTANCE>/db`;
  `install.sql` re-seeds on the next boot.

## Upgrading from before 1.2.0

Run `docker compose up -d --build` (the new image adds `exiftool`), `docker compose restart web`,
then `tools/imgboard-rebuild.php`. Expect:

- Staff are logged out once. Sessions now last 30 days, and logging out ends them all.
- If `admin` still used the stock password, the rebuild replaces it and prints the new one.
- Sites that never set their own salts get new ones: poster IDs, secure tripcodes and
  device IDs change, and older posts can no longer be deleted with their password.
- The config editor, themes and raw HTML are now Root only.

## Upgrading to 1.2.1

Run `docker compose up -d --build`, then `tools/imgboard-rebuild.php`. Posts already published
are not re-checked by the stronger link detection. If you have edited the privacy page, add the
new fingerprint details to it yourself (`?/edit-privacy`).

## New site checklist

1. Replace `example.com` and, if you like, the `imgboard` prefix.
2. Salts need no setup: random ones are generated on first run in `inc/instance-salts.php`
   (in Docker, under `local-instances/<INSTANCE>/www/inc/`). Back that file up with the
   database. To manage salts yourself, set them in `inc/secrets.php` (never commit it).
3. `docker compose up -d --build` (see `compose.yml`, `docker/`), then run
   `tools/imgboard-rebuild.php` as shown above and save the printed `admin` password.
4. Add a logo (`$config['logo']`), favicon (`/favicon.ico`) and banners in `static/banners/`
   (the banner endpoint returns 404 until one exists).
5. Create a `meta` board: the Schizo reset (below) keeps it and wipes everything else.
   Use a different URI by editing `inc/mod/pending.php` and `templates/mod/schizo.html`.
6. Edit rules, news, ToS and privacy from the site itself (Admin+). The defaults (rules, ToS, privacy, Report Abuse, footer)
   carry the placeholder contact email `contact@example.com` (with TODO comments marking each spot); put your own contact info in
   every one of them (`inc/mod/pending.php`, `templates/footer.html`, `SECURITY.md`).
   The home page is `templates/imgboard_home.html`.
7. Set the optional keys you want (Turnstile site/secret, `VICHAN_PROXYCHECK_KEY`); features
   with no key simply stay off.
8. Optional extra components (all off by default):
   - **StopForumSpam** IP checks on the mod IP page: set `VICHAN_SFS_CHECK=1` (free, no key).
   - **Offline staff push notifications**: in the `php` container run
     `composer require minishlink/web-push:^7.0` then `php tools/gen-vapid.php`, put the
     printed VAPID keys in `inc/secrets.php` (or the `VICHAN_VAPID_*` env vars), and serve
     over HTTPS. Online notifications (the bell on `?/pending`) work without this.

## Where things live

| What | Where |
|---|---|
| Instance settings, permission levels, feature switches | `inc/instance-config.php`, `inc/config.php` |
| Moderation handlers and defaults | `inc/mod/pending.php`, `inc/mod/steg.php` |
| Mod templates | `templates/mod/` |
| Front-end extras | `js/imgboard-*.js`, `js/toast.js`, `js/theme-effects.js` |
| Maintenance and self-checks | `tools/` |
| Containers (nginx, php-fpm, tor, i2pd) | `docker/`, `compose.yml` |

## Features

### Staff ranks
Linear levels: Mediator 5 < Janitor 10 < Mod 20 < Admin 30 < Manager 35 < Root 40.
A permission is a minimum level, so lowering one also grants it to every higher rank.
New sensitive features should pick an explicit level in `inc/instance-config.php`.

### Approval systems
- **Media Pending Approval**: uploads are replaced by a text placeholder until approved.
- **Link Pending Approval**: a post that links or names another site anywhere visitors can see it
  (comment, subject, name, email, file name, embed), including obfuscated forms such as
  `example(dot)com`, shows `[Link Pending Approval]` until approved (staff always see the real
  text). Links are refused in poll choices. Domain endings come from `inc/tlds.txt` (the IANA
  list); replace that file now and then to pick up new ones.
- Both halves live in one `/pending` queue with CSRF-protected approve actions.
- **Post Pending Approval (PPA)**: optionally hold non-staff posts for review, switchable per board from the Pending page.
  Posts via the Tor and I2P mirrors are held by default.
- **Blocklists**: blocked words and links; Janitors suggest, Mods approve or delete.
- **Mediator proposals**: Mediators propose a deletion or ban, Janitors+ approve or deny.

### Moderation and visibility
- Tiered identifier visibility: Mediators see only a super-hashed IP token, Janitors an
  opaque poster number, Mods the IP and device identifiers, Admins the full fingerprint breakdown.
- DTEG browser fingerprinting (`js/dteg/`: device and headless checks, WebRTC IP discovery, extension detection, input-cadence timing without keystroke content) with a trust score, an append-only history of every report and its request headers (also recorded for each post, with or without JavaScript), a per-visitor list and bulk export
  (Manager+ only), plus VPN/proxy detection (proxycheck.io) beside the IP.
- File-hash bans, steganography check on images, per-board IP post deletion.
- Admin-editable mod log, drag-to-reorder top bar editor, mod-mode overboard (`?/o`).
- "View as <role>" preview with a persistent banner.
- Staff-only boards: `/j/` (staff) and `/test/` (staff scratch) ship by default, hidden from
  the public board list and overboard and gated to logged-in staff at the web server. To add
  another, append it to `$config['imgboard_staff_boards']` in `inc/instance-config.php` **and**
  to both `j|test` regexes in `docker/nginx/vichan.conf` (the static-file gate), then create
  the board in the mod panel.
- Per-board anonymous-name settings and an admin-managed random name list.
- Login throttling per username and per IPv6 /64.

### Emergency controls
- **JSID** (permanent, Root only) and **Temp-JSID** (temporary, any admin) killswitch with
  a down-since timer.
- **Schizo**: nuclear reset that deletes every board except `meta`, wipes it down to its
  stickied and locked threads, and fires all mods. Manager and Root only.

### Posting
- Cloudflare Turnstile on new threads, image-captcha fallback, country flags from
  Cloudflare's header.
- Extra upload types: WebM (with audio) and JPEG XL, AVIF, HEIC/HEIF, TIFF, ICO, MP4, all validated by ffmpeg or ImageMagick and thumbnailed. Ctrl+Enter submits the post form and quick reply.
- URL embeds (YouTube, Vimeo, Dailymotion, Rumble and more), polls, Tegaki and wPaint
  drawing, MathJax, themed effects, ImgOps link.
- Many optional client scripts: quick reply, inline expanding, hover previews, thread
  watcher, infinite scroll, catalog search, post filtering, favourites and the rest of `js/`.

### Hosting
- Docker stack with nginx, php-fpm, MySQL, Redis, plus optional Tor and I2P mirrors.
- Built to sit behind Cloudflare (Tunnel or proxy); real IP and country come from its headers.
- nginx denies PHP execution under `src/` and `thumb/`, so uploads never execute.

## Security rules for changes

1. Never commit secrets (`.env`, `inc/secrets.php`, `inc/instance-salts.php`).
2. SQL only through `prepare()` and `bindValue()`; board and table names only from validated sources.
3. Mod actions need both gates: `secure` / `secure_POST` (CSRF token) on the route and a
   `hasPermission()` check in the handler. Hiding a button is not a gate.
4. Log every staff action with `modLog()`.
5. Template autoescaping is **off**: add `|e` to every value a visitor can influence,
   including file contents and URLs. Use `|raw` only deliberately.
6. Every automatic defence (filter, ban, rate limit, hash ban) needs a staff screen and an undo.
7. Bulk-data access (fingerprints, IPs) is Manager+.

## Not included

Deploy and backup scripts, secrets, branding images, banners.
Licenses: `LICENSE.md`, `LICENSE.Tinyboard.md`.

## Credits

Built on the work of:

- **Tinyboard** 0.9.6 — Copyright © 2010-2014 Tinyboard Development Group.
  <https://github.com/savetheinternet/Tinyboard>
- **vichan** — Copyright © 2012-2025 vichan-devel. <https://github.com/vichan-devel/vichan>
- **dchan** 1.2.1 — this fork. <https://github.com/mortally4634/dchan>

Licenses: [LICENSE.md](LICENSE.md) covers dchan; [LICENSE.Tinyboard.md](LICENSE.Tinyboard.md) covers the
Tinyboard and vichan code this fork is built on.
