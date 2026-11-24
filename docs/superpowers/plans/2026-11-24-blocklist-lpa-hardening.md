# Blocklist + LPA Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Catch link-shaped content (incl. scheme-less/obfuscated) so it is blocked+banned if listed or else held in LPA, harden blocked-word matching against separator/zero-width bypasses, and tidy the ban-list wrapping.

**Architecture:** One shared pure detector `imgboard_detect_links()` in `inc/mod/pending.php` drives both the blocked-link match and the LPA-hold decision. A pure match core `imgboard_blocklist_match_core()` takes explicit word/link lists so it is unit-testable; the existing DB-backed `imgboard_blocklist_match()` becomes a thin wrapper. Word matching gains `imgboard_word_canon()`. A new `tools/selfcheck-blocklist.php` `require`s the real file and runs case tables (repo's self-check idiom).

**Tech Stack:** PHP 7.4, run in the `php` Docker container. No new deps.

**Spec:** docs/superpowers/specs/2026-11-24-blocklist-lpa-hardening-design.md

## Global Constraints

- PHP 7.4 compatible; no new Composer deps.
- Staff exempt from all filtering: guarded by `empty($post['mod'])` at call sites (unchanged).
- Detector/canon/match-core are pure string functions: no SQL, no network, O(body length).
- Any SQL stays via `prepare()`/`bindValue()` (no new SQL in this change).
- Over-blocking of ambiguous dotted text / split words is accepted per spec.
- Run self-checks in-container: `docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php` (prints `all blocklist checks passed` and exits non-zero on failure).
- After all tasks, bump dchan `1.1.6` → `1.1.7` in `inc/instance-config.php` and `README.md` (keep in sync).

## Review Focus

- **Email addresses** (`user@host.com`): the host is a dotted token → post held in LPA. Expected over-block per spec; pin a test asserting it IS detected (documented behavior, not a bug). → Task 1.
- **IPv4-literal URLs** (`http://185.0.0.1/x`): must be detected as a link. → Task 1.
- **Protocol-relative** (`//evil.top/x`) and **punycode/IDN** (`xn--….com`) hosts: must be detected. → Task 1.
- **Non-allowlisted / filename-lookalike suffix** (`photo.zip`, `clip.mov`, `readme.md`): must NOT be detected as a bare host (allowlist omits these), so ordinary text isn't held. → Task 1.
- **Zero-width / separator split words** (`b​a d`, `b.a.d`): must match a blocked word via the squeezed body. → Task 2.

---

### Task 1: Shared link detector + canon, with self-check harness

**Files:**
- Modify: `inc/mod/pending.php` (add `IMGBOARD_LINK_TLDS` list + `imgboard_detect_links()`; keep `imgboard_link_canon()`, extend only if a test needs it)
- Create: `tools/selfcheck-blocklist.php`

**Interfaces:**
- Produces: `imgboard_detect_links(string $text): array` — de-duplicated raw candidate link strings (scheme URLs, `rel="nofollow"` hrefs, de-obfuscated forms, and bare `host.tld` where `tld` ∈ allowlist).
- Produces: `imgboard_link_canon(string $s): string` (existing; canonical compare form).
- Consumes: nothing from other tasks.

- [ ] **Step 1: Write the failing self-check** `tools/selfcheck-blocklist.php`

```php
<?php
// Self-check for the blocklist detector/matcher (inc/mod/pending.php).
// Run: docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php
define('TINYBOARD', 1);
$config = ['cache' => ['enabled' => false]];
require __DIR__ . '/../inc/mod/pending.php';

$fail = 0;
function chk($cond, $msg) { global $fail; if (!$cond) { echo "FAIL $msg\n"; $fail++; } }

// --- imgboard_detect_links: must detect ---
$detect_hit = [
  'bare domain'        => 'check evil.com now',
  'dot-obfuscated'     => 'go to evil(dot)com',
  'hxxp scheme'        => 'hxxp://evil.top/path',
  'https url'          => 'see https://evil.io/a',
  'ipv4 url'           => 'http://185.0.0.1/x',
  'protocol-relative'  => 'visit //evil.top/x',
  'punycode host'      => 'xn--80ak6aa92e.com here',
  'email host'         => 'mail me at bad@evil.com',   // over-block: expected
];
foreach ($detect_hit as $name => $body) {
  chk(count(imgboard_detect_links($body)) > 0, "detect should hit: $name [$body]");
}

// --- imgboard_detect_links: must NOT detect (allowlist omits these) ---
$detect_miss = [
  'zip filename' => 'here is photo.zip for you',
  'mov filename' => 'clip.mov attached',
  'md filename'  => 'read readme.md',
  'plain text'   => 'i went there etc then left',
];
foreach ($detect_miss as $name => $body) {
  chk(count(imgboard_detect_links($body)) === 0, "detect should miss: $name [$body]");
}

echo $fail ? "FAILED $fail\n" : "all blocklist checks passed\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php`
Expected: FAIL — `Call to undefined function imgboard_detect_links()`.

- [ ] **Step 3: Implement `imgboard_detect_links(string $text): array` in `inc/mod/pending.php`**

Add a module-level `IMGBOARD_LINK_TLDS` array (~150 common + abuse-favored TLDs: com net org io co info biz xyz top online site click link shop app dev gg to cc tv me ru su cn tk ml ga cf pw icu vip club live fun life world space website host press and the common ccTLDs; **omit** filename-lookalikes `zip mov md js` and other extension collisions) with an editing comment. Detector order: (a) de-obfuscate via the same pre-steps as `imgboard_link_canon` (strip zero-width/direction/soft-hyphen, decode entities, up-to-3× percent-decode, map `(dot)`/`[dot]`/`{dot}`/` dot `→`.`, `hxxps?`→`https?`); (b) collect `https?://\S+`; (c) collect `rel="nofollow"` hrefs (reuse the existing regex from `imgboard_extract_links`); (d) collect `//host…` protocol-relative and bare `([a-z0-9-]+\.)+(tld)` hosts where the final label is in `IMGBOARD_LINK_TLDS` (case-insensitive; IPv4 literals in a URL are already caught by (b)). Return `array_values(array_unique(array_filter(...)))`.

- [ ] **Step 4: Run it, verify it passes**

Run: `docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php`
Expected: `all blocklist checks passed`.

- [ ] **Step 5: Commit**

```bash
git add inc/mod/pending.php tools/selfcheck-blocklist.php
git commit -m "Detect scheme-less and obfuscated links for the blocklist"
```

---

### Task 2: Word canon + testable match core

**Files:**
- Modify: `inc/mod/pending.php` (add `imgboard_word_canon()`, `imgboard_blocklist_match_core()`; refactor `imgboard_blocklist_match()` to call the core with cached lists)
- Modify: `tools/selfcheck-blocklist.php` (add word + match-core cases)

**Interfaces:**
- Consumes: `imgboard_detect_links()`, `imgboard_link_canon()` (Task 1).
- Produces: `imgboard_word_canon(string $s): array` → `[stripped_lower, squeezed]` (squeezed = `stripped_lower` with all non-alphanumerics removed).
- Produces: `imgboard_blocklist_match_core(string $body_nomarkup, array $words, array $links): ?string` — returns the block message or null; words match literally on stripped body OR on squeezed body; links match canonically against `imgboard_detect_links($body)` + whole-body canon.
- `imgboard_blocklist_match(string $body_nomarkup): ?string` now = `imgboard_blocklist_match_core($body, cached('words'), cached('links'))`.

- [ ] **Step 1: Add failing cases to `tools/selfcheck-blocklist.php`** (before the final tally)

```php
// --- word matching through bypasses ---
$words = ['bad'];
$links = ['evil.com'];
$word_hit = ['bad', 'b a d', 'b.a.d', "b\xE2\x80\x8Ba\xE2\x80\x8Bd", 'XbadX'];
foreach ($word_hit as $b) {
  chk(imgboard_blocklist_match_core($b, $words, $links) === 'Your post contains a blocked word.', "word should hit [$b]");
}
chk(imgboard_blocklist_match_core('totally fine text', $words, $links) === null, 'clean body should pass');

// --- link matching via the shared detector ---
$link_hit = ['go to evil.com', 'evil(dot)com', 'hxxp://evil.com/x', 'EVIL.COM/'];
foreach ($link_hit as $b) {
  chk(imgboard_blocklist_match_core($b, [], $links) === 'Your post contains a blocked link.', "link should hit [$b]");
}
chk(imgboard_blocklist_match_core('clean.example-not-listed', [], $links) === null, 'unlisted link should pass match');
```

- [ ] **Step 2: Run, verify it fails**

Run: `docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php`
Expected: FAIL — `Call to undefined function imgboard_blocklist_match_core()`.

- [ ] **Step 3: Implement the functions in `inc/mod/pending.php`**

`imgboard_word_canon`: strip zero-width/combining marks, `mb_strtolower`; squeezed = `preg_replace('/[^a-z0-9]+/u','',$stripped)`. `imgboard_blocklist_match_core`: for each word, canon it → hit if its stripped form is a substring (`mb_stripos`) of the body's stripped form OR its squeezed form is a substring of the body's squeezed form (skip empty/very-short squeezed words to avoid matching everything — minimum squeezed length 2). For links, reuse the existing canonical compare loop but build candidates from `imgboard_detect_links($body)` plus `imgboard_link_canon($body)`. Rewire `imgboard_blocklist_match()` to delegate.

- [ ] **Step 4: Run, verify it passes**

Run: `docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php`
Expected: `all blocklist checks passed`.

- [ ] **Step 5: Commit**

```bash
git add inc/mod/pending.php tools/selfcheck-blocklist.php
git commit -m "Match blocked words through separator and zero-width bypasses"
```

---

### Task 3: LPA holds any detected link

**Files:**
- Modify: `inc/functions.php` (~line 926, the `$has_link` assignment in `insertPost`)

**Interfaces:**
- Consumes: `imgboard_detect_links()` (Task 1).

- [ ] **Step 1: Make the change**

Replace `$has_link = isset($post['body']) && strpos($post['body'], 'rel="nofollow"') !== false;` with a test that is true when `imgboard_detect_links()` finds anything in the post's raw text. Use `$post['body_nomarkup']` when set (falls back to `$post['body']`), guard with `function_exists('imgboard_detect_links')`, and keep the `&& empty($post['mod'])` exemption on the `:approved_link` bind (line 927) intact.

- [ ] **Step 2: Verify the code path (static)**

Run: `docker compose exec -T -u www-data -w /var/www php php -l inc/functions.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Integration verify in-container**

On the running stack, post as a non-staff user on `/test/`:
- `evil.com` (bare) → post is held (shows pending, `approved_link = 0`).
- a post with no link → not held.
Confirm via the `?/pending` queue. (Manual; note result in the commit/PR.)

- [ ] **Step 4: Commit**

```bash
git add inc/functions.php
git commit -m "Hold posts with scheme-less or obfuscated links in LPA"
```

---

### Task 4: Ban-list cells expand to their text

**Files:**
- Modify: `stylesheets/mod/ban-list.css`

- [ ] **Step 1: Make the change**

Build on the existing `overflow-wrap:anywhere; word-break:break-word` already in `#banlist td`. Make cells show their full content without awkward clipping: give the IP column room for a full IPv6 (so the common case fits on one line) and ensure the hover reveal box wraps neatly within its column rather than overlapping Reason. Keep the long-table layout intact (don't break the absolute-positioned rows); prefer widening + wrapping over forcing variable row heights.

- [ ] **Step 2: Verify visually**

Open `templates/mod/ban_list.html` / the rendered ban list in the browser pane with a long-IPv6 row; confirm no clipping, no overlap, tidy hover. Capture a screenshot for the user.

- [ ] **Step 3: Commit**

```bash
git add stylesheets/mod/ban-list.css
git commit -m "Expand ban-list cells to fit long IP addresses"
```

---

### Task 5: Version bump + full self-check

**Files:**
- Modify: `inc/instance-config.php`, `README.md`

- [ ] **Step 1: Bump** dchan `1.1.6` → `1.1.7` in both files (per the project rule; 1.0.10 would roll to 1.1.0, N/A here).

- [ ] **Step 2: Run the full self-check**

Run: `docker compose exec -T -u www-data -w /var/www php php tools/selfcheck-blocklist.php`
Expected: `all blocklist checks passed`.

- [ ] **Step 3: Commit**

```bash
git add inc/instance-config.php README.md
git commit -m "Bump dchan version to 1.1.7"
```

## Notes left to the builder

- Final `IMGBOARD_LINK_TLDS` contents (seed ~150 sensible entries; it's an editable constant).
- Exact regexes / de-obfuscation order (mirror `imgboard_link_canon`).
- CSS sizing numbers, verified against a real long-IPv6 row.
- If `body_nomarkup` isn't populated at the `insertPost` call site, detect on `$post['body']`.
