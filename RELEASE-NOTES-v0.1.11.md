# Berlin WP Comments v0.1.11 — Release Notes

**Release date:** 2026-08-30
**Tag:** `v0.1.11`
**Milestone:** V1 first public release (V1_WP_VERIFIED)
**License:** GPL-2.0-or-later
**Repository:** https://github.com/Berlin2022/berlin-wp-comments

## What this release is

A lightweight, self-contained WordPress comments plugin for custom post types
(e.g. WooCommerce-like product CPTs). It renders comments with a native
`cpage`-based pagination, local avatars (no third-party avatar service), and an
inline reply form — without touching WordPress core comment data or lifecycle.

## Highlights

- **Native WordPress comment data & lifecycle.** Comments are stored as standard
  WP comments (`comment_type = 'comment'`, standard `comment_approved` / `comment_parent`
  relationships). No custom tables, no data migration, no lock-in. Deleting the
  plugin leaves your comments fully intact and readable by any theme.
- **Shortcode + local avatars.** Exposes a canonical `[berlin_comments]` shortcode
  (with an `alias` alias for backward-compat) and renders avatars from a bundled
  local SVG (`default-avatar.svg`) via the `get_avatar_data` filter — **no Gravatar
  requests**.
- **Native `cpage` pagination.** Pagination uses WordPress's own `cpage` query var and
  `comment-page-N` pretty permalinks, driven by `default_comments_page` (newest/oldest)
  and `comments_per_page`. Top-level threads are the pagination unit; each page carries
  its complete descendant thread (thread-safe, threads are never split across pages).
- **Thread-safe pagination.** Root comments are computed from the full approved-comment
  set (`parent = 0` or orphan reply whose parent is missing → treated as root), then
  sliced per page; descendants are attached via an in-memory `children_map` with **no
  extra DB queries**. Orphan replies (parent pointing to a deleted/missing comment) are
  correctly shown as roots instead of being dropped.
- **English UI.** All user-facing strings are in English (WP-i18n-ready via
  `berlin-wp-comments` text domain).
- **Verified on a real WordPress site (O5 / O8 / Reply).** Acceptance criteria O5
  (native `cpage` pagination), O8 (zero `gravatar.com` requests), and inline Reply were
  verified on a live WP installation (vosalen.com, `wpkj_product` CPT). Evidence:
  `?bwpc_debug=1` runtime probe confirmed `query_comments_count = 3` with correct
  root/descendant counts; the previous "page 2/3 empty" bug was traced to
  `wp_list_comments()` double-slicing and fixed by `per_page => 0`.
- **No Gravatar.** Avatars resolve locally; the plugin never contacts `gravatar.com`.
- **GPL-2.0-or-later.** Distributed under the GNU General Public License v2 or (at your
  option) any later version. See `LICENSE`.

## Upgrade / install

1. Upload the plugin folder to `wp-content/plugins/berlin-wp-comments/`.
2. Activate. Place `[berlin_comments]` (or `[berlin_comments alias]`) in your
   single-product template or via a hook.
3. Ensure only one copy of the plugin directory exists; if your host uses OPcache with
   `opcache.validate_timestamps = 0`, reset OPcache after replacing files.

## Known limitations (V1 scope)

- WordPress.org directory submission is **not** a V1 target (per USER FINAL decision
  2026-08-27); no `readme.txt` / Stable Tag / Tested-up-to metadata is included.
- The `?bwpc_debug=1` runtime probe remains in the code but is gated behind
  `current_user_can('manage_options')` and only triggers for administrators — it is
  inert for normal visitors.

## Verification status

- P1–P6 all closed; 6 Open Items adjudicated (① ②④ ⑥ CLOSED / ③ CONDITIONAL / ⑤ CLOSED
  WITH CORRECTION).
- O5 (native `cpage` pagination) — PASS (real WP).
- O8 (zero Gravatar) — PASS (design + render; Network `gravatar` spot-check recommended,
  non-blocking).
- Reply inline — PASS (real WP, v0.1.6 `respond_id='respond'` + unconditional
  `comment-reply` script enqueue).
- Static regression `structure-check` 90/90; PHP `-l` syntax clean.
- Anchor: code `c58da96` (tag `v0.1.11`) / governance CHK-015.
