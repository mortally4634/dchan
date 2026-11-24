# Blocklist + LPA hardening — design

Date: 2026-11-24
Status: awaiting approval

## Intent / why

Malicious links are reaching the board without being held for review. The current
Link Pending Approval (LPA) gate only holds a post when its rendered body contains
an external anchor (`rel="nofollow"`), and vichan only auto-links strings that carry
an `http(s)://` scheme. So a bare `evil.com`, `evil(dot)com`, or `hxxp://evil.co`
is never linkified, never held, and never checked against the blocked list unless
that exact host is already blocked. Staff want: anything link-shaped either gets
blocked+banned (if it's on the list) or held in LPA (if it isn't), with no easy
bypass — accepting that some innocent dotted text will be held as the price.

The same "no bypass" stance is wanted for the blocked **words** list.

A cosmetic ban-list wrapping issue is folded in.

## Goals

1. Detect link-shaped content even without a scheme (bare/obfuscated domains), via
   one shared detector used by both the LPA-hold decision and the blocked-link match.
2. Blocked link on the list → refuse post + auto-ban (existing behavior, now driven
   by the wider detector so obfuscated forms also hit).
3. Link not on the list but detected → hold in LPA (`approved_link = 0`), including
   scheme-less/obfuscated links that slip through today.
4. Blocked **words**: match through separator/zero-width/obfuscation bypasses too.
5. Ban-list cells expand to fit their text; nothing clipped awkwardly.

## Non-goals / anti-goals

- No retroactive action on already-posted content (user chose forward-only; new
  malicious links get caught going forward and via LPA). 
- No resolving of URL shorteners/redirectors (still out of scope; documented).
- No full public-suffix list; a curated, editable TLD allowlist instead.
- Anti-goal: a scheme-less bare domain posting with no block and no LPA review.

## Constraints

- Mods/staff stay exempt (`empty($post['mod'])`), as today.
- Matching runs on every non-staff post — keep it O(body length), no network calls.
- Follow repo security rules: no new SQL except via prepare/bind; the detector is
  pure string work.

## Approaches considered

- **A — dotted-token heuristic (chosen).** Treat as a link: any `http(s)://` URL,
  any de-obfuscated form, and any bare `host.tld`. Closes the bare-domain hole.
- **B — known-TLD list.** More precise but needs a ~1.5k-entry public-suffix list
  and is only marginally better for this goal.
- **C — de-obfuscate only.** Lowest false positives but leaves plain `evil.com` open
  — doesn't fix the reported problem.

**Decision: A with a sprinkle of B.** Dotted-host detection is gated by a curated,
editable allowlist of ~150 common + abuse-favored TLDs (com, net, org, io, co, xyz,
top, ru, online, site, link, click, shop, app, dev, gg, to, cc, tv, country codes,
…). The list deliberately **omits** file-extension lookalikes (`zip`, `mov`) so
`file.zip` isn't held, trimming the dumbest false positives while still catching the
malicious ones. Over-blocking of genuinely ambiguous dotted text is still accepted.

## What gets built (decided parts)

1. **`imgboard_detect_links(string $text): array`** (new, in `inc/mod/pending.php`).
   - De-obfuscate: strip zero-width/direction/soft-hyphen chars, decode HTML entities
     and up-to-3× percent-decode, map `(dot)`/`[dot]`/`{dot}`/` dot `→`.`, `hxxp`/`hxxps`→`http(s)`.
   - Extract: `https?://…` URLs; existing `rel="nofollow"` anchors; and bare
     `(label.)+tld` hosts where `tld` ∈ the curated allowlist.
   - Returns de-duplicated raw candidate strings. Reuses `imgboard_link_canon()` for
     comparison downstream.
   - The TLD allowlist lives as a module constant/array with a comment saying how to edit.

2. **LPA hold decision** — in `inc/functions.php` (~line 926), replace the
   `strpos($post['body'], 'rel="nofollow"')` test with
   "`imgboard_detect_links($post['body_nomarkup'])` is non-empty" (mod-exempt as now),
   so scheme-less/obfuscated links also set `approved_link = 0`.

3. **Blocked-link match** — `imgboard_blocklist_match()` builds its candidates from
   `imgboard_detect_links()` (plus whole-body canon) instead of the narrower
   `imgboard_extract_links()`, so the list matches every detected form. Blocked →
   refuse + auto-ban stays as-is.

4. **Blocked-word match** — add `imgboard_word_canon()`: strip zero-width/combining
   marks + lowercase, and a "squeezed" variant removing all non-alphanumerics. A
   blocked word matches if it (canon) is a substring of the raw body **or** of the
   squeezed body, so `b a d`, `b.a.d`, `b‑a‑d`, `b<zwsp>a<zwsp>d` all hit `bad`.
   Over-blocking accepted.

5. **Ban-list CSS** — `stylesheets/mod/ban-list.css`: let cells size to their content
   (widen the IP column enough for a full IPv6 and allow neat wrapping; keep the
   hover reveal tidy) so nothing is clipped or overlaps. Builder's visual judgment;
   I'll show the result.

6. **Version bump** per the project rule (1.1.6 → 1.1.7), instance-config + README.

## Left to the builder

- Exact TLD allowlist contents (I'll seed a sensible ~150 and note it's editable).
- Precise regexes and the de-obfuscation order (match/extend `imgboard_link_canon`).
- The CSS sizing numbers, verified against a long IPv6 row.
- Confirm `body_nomarkup` is populated at the `insertPost` call site; if not, detect
  on the available raw body.
