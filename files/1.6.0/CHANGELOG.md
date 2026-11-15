# Changelog

All notable changes to Bench core. Versions follow [semantic versioning](https://semver.org/). Sites install new versions from **Admin → Updates** (see README → Updates).

## Unreleased

## v1.6.0 — Blog plugin and bilingual sites (English and Welsh)

- **This is a feature release,** so sites offer it in **Admin → Updates** and wait for someone to click **Update**. Before updating a site with a customised `.htaccess`, see the server rule change below.
- **New Blog plugin** (Admin → Plugins → Blog): posts with a featured image, categories, tags, author and date, like WordPress.
  - **Schedule posts:** pick a future publish date and the post goes live by itself at that time. Scheduled and draft posts are hidden from visitors but can be previewed while logged in.
  - A **Blog posts** section for the main blog list (category links, page numbers, category, tag and author archives) or a "latest posts" strip on any page.
  - Post pages at `/blog/<post-title>` with breadcrumbs, tags, newer/older links, `BlogPosting` structured data and a share image; an RSS feed at `/blog/feed`.
  - The post editor has the Accessibility check, and its text box has an **Image** button for photos in the post.
  - Claude can write and schedule posts through the connector.
- **New field types for plugins:** `datetime`, `multiselect` (checkboxes) and `tags`, plus typing suggestions (`suggest`) and options worked out on demand.
- **New collection options:** readable item addresses (`clean_ids`), `before_save`, `describe`, `subroutes`, `a11y_check` and `help`. Collection items can now have their own SEO title.
- Rich text keeps images (`<img>` from `uploads/` or `https://`, with their alt text). The Accessibility check flags images in text without a description.
- New icons: `pen` and `user`.
- `tests/blog.sh` (in the smoke CI job); the AAA audit now covers the blog list, archives, a post and the post editor.
- **Bilingual sites (optional), e.g. English and Welsh.** Switch on in Admin → Settings → Languages. Off by default, so existing sites don't change.
  - The second language lives under `/cy/` (or `/en/` on a Welsh-first site). Pages can have their own address in each language (`/cy/cysylltu`).
  - The page editor, vehicle editor and Settings show a translation box under every text field. Blank boxes fall back to the main language. The Pages list shows how much of each page is translated.
  - A language link in the top bar of every theme. `<html lang>`, `hreflang` and `og:locale` are set for each language. Fixed wording in themes, sections, forms, bench.js and the Car Stock, Van Stock and Blog plugins goes through `t()`. Blog dates show Welsh month names, and blog categories get a Welsh translation (Settings → Blog), line by line.
  - Welsh wording ships in `core/lang/cy.php` and the plugins' `lang/cy.php`. It's a first draft: have it checked by a Welsh speaker. `custom/lang/cy.php` overrides any of it for one site.
  - The Claude connector can read and write translations (`_t`). It merges them per language and marks translatable fields in `get_schema`.
  - Enquiry forms on Welsh pages send `language: Welsh` with the message.
  - Page addresses made from Welsh titles keep their letters: "Cysylltu â ni" becomes `cysylltu-a-ni`. Language codes such as `cy` can't be used as page addresses.
- **Server rule change:** the short-link rewrite in `.htaccess` and `router.php` now allows three-part addresses (`/cy/ceir/<id>`). Sites with a customised `.htaccess` get the new file as `.htaccess.new`. Copy the `RewriteRule … {0,2}` line across, or Welsh vehicle pages return 404 on Apache.
- **Themes copied into `custom/themes/`** keep working. To get the language link and the right `<html lang>` on Welsh pages, add `bench_language_switcher()` and `bench_html_lang()` (see `themes/trade/layout.php`).
- New `tests/i18n.sh` (bilingual sites, in the CI smoke job). `tests/blog.sh` covers a Welsh blog. `tests/connector.sh` covers translations, and the AAA audit has two bilingual runs.

## v1.5.2 — Updates work on hosts without sodium or zip

- **Updates now work on hosts that don't have PHP's sodium or zip extensions**, such as Fasthosts. Admin → Updates no longer asks you to get them enabled.
  - Without sodium, Bench checks each release's signature with built-in pure-PHP code (from paragonie/sodium_compat, the same code WordPress uses for this).
  - Without zip, Bench downloads only the files that changed, one at a time, and checks every file against the signed release before installing anything.
  - Hosts that have both work exactly as before.
- **Sites on 1.5.1 without sodium can't check this release themselves,** so upload `bench-1.5.2.zip` by FTP once. After that, updates install from Admin → Updates.
- Releases now also publish their files unpacked (`files/X.Y.Z/` in the release channel), with the file list's hash in the signed manifest.
- The updater test runs a second time with sodium and zip removed (`BENCH_NO_EXT=1 tests/updater.sh`).

## v1.5.1 — Automatic updates switched on

- **Updates now work.** This release has Bench's release signing key built in, so from here on sites find new versions in **Admin → Updates** and install fix releases by themselves. Sites on 1.5.0 or earlier need 1.5.1 uploaded by FTP once (the update package, `bench-1.5.1.zip`).
- **Admin → Claude is now Admin → Connections.** Same screen and the same address (`admin/?view=claude`), just a clearer name in the menu and page title.

## v1.5.0 — Updates, Claude connector, media trash and Executive theme

- **Admin → Updates:** sites now update themselves from the Bench release channel, like WordPress.
  - Bench checks twice a day. **Fix releases** (1.5.0 → 1.5.1) install automatically unless turned off; **feature releases** wait for someone to click **Update**.
  - Every release is **signed**. A site checks the signature, the package hash and every file's hash before installing, so a hacked download server can't push code to sites.
  - Before installing, the files being replaced are backed up. Afterwards the site's home page and admin are loaded, and **a broken update is rolled back automatically**. **Go back to …** undoes the last update by hand.
  - Only files Bench ships are replaced. `content/`, `uploads/`, `custom/` and `config.php` are never touched. A customised `.htaccess` is kept, with the new one saved as `.htaccess.new`.
  - Visitors see a short "back in a moment" page while files are swapped.
  - `'updates' => false` in `config.php` turns it off (for sites deployed with Git); automatic updates are off on Git checkouts anyway.
- **`custom/` folder** for site-specific changes that survive updates, like a WordPress child theme: copy any theme file to `custom/themes/<theme>/` to override it, or add `custom/custom.css`.
- **Claude connector.** Claude can edit a Bench site from a chat: change page text and sections, add or update vehicles and other collection items, add photos from a web address, and change site settings.
  - Switch it on in the new **Admin → Claude** screen, then add `https://<site>/admin/mcp.php` to Claude as a custom connector and sign in with a Bench login. It's off by default.
  - Sign-in is OAuth 2.1 with PKCE and dynamic client registration, with a Bench login and an "Allow Claude?" page (`admin/oauth.php`). Only Claude's addresses (and localhost) can receive sign-in codes. Tokens are stored hashed. Access tokens last an hour; refresh tokens last 30 days and are single use.
  - 22 tools (`admin/mcp.php`): read the site, pages, sections, items, settings, media and backups, and change pages, sections, items and settings. Upload images by URL (public addresses only), restore backups.
  - Claude can't change logins, plugins, updates, security settings or script-running "code" settings (`'connector_allow_code' => true` allows the last).
  - Every change goes through the same code as the admin (new `core/content-ops.php`), is backed up, and is listed in Admin → Claude with **Undo**. **Connected** apps can be disconnected.
  - `.htaccess` / nginx: `/.well-known/oauth-*` metadata, POSTs allowed to the connector endpoints, and the `Authorization` header passed to PHP.
  - New test `tests/connector.sh` (in the smoke CI job). The Apache test checks the connector routes, and the accessibility audit covers Admin → Claude.
- Admin nav labels stay available to screen readers on phones.
- **Media trash.** Deleting a vehicle, page or other item, or removing a photo from one and saving, moves each of its photos that nothing else uses to **Admin → Media → Trash**. Trashed photos go out of `uploads/` into `content/_trash/`, so they're off the website straight away.
  - In the trash: **Restore** one, **Delete for good**, or **Empty trash** to permanently delete everything.
  - Restoring an item or page from **Backups** brings its photos back from the trash.
  - Photos still used anywhere else are never moved. That includes rich text, settings, and items of plugins that are switched off.
  - **Media** now shows where each image is used, can filter to unused images, and has **Move unused to trash**. Its ✕ button now moves an image to the trash instead of deleting it outright, and warns if the image is in use.
  - Turn it off with `'media_trash' => false` in `config.php`.
  - New `core/media.php`, and API actions `trash_unused`, `trash_restore`, `trash_delete` and `trash_empty`. `media` now returns usage and the trash.
  - New test `tests/media.sh` (runs in the smoke CI job). The accessibility audit now covers the Trash tab.
- **Executive theme:** midnight blue, champagne gold and Cormorant Garamond headings, for chauffeurs, executive travel and professional services. Dark hero with an optional photo behind an overlay, ruled FAQs and features, framed call to action. Tested to WCAG 2.2 AAA with default and custom colours.
- New icons: `plane`, `briefcase`, `rings`, `glass`, `route`.
- **Fix (all themes):** the focused "Skip to content" link could sit over the sticky header (WCAG 2.4.12) when the announcement bar was short or switched off. When focused, it's now a full-width bar that pushes the page down. The keyboard tests now also check this with no announcement bar.
- **Release workflow** (`.github/workflows/release.yml` and `tools/release.php`): runs the tests, builds the update and full-install packages, signs them and publishes them to the release channel.
- Reflow fix: no sideways scrolling at 320px when the web fonts don't load (WCAG 1.4.10).
- Git repository, GitHub Actions CI (lint and smoke on PHP 8.2–8.4, updater, Apache security rules, WCAG 2.2 AAA audit), test suite in `tests/`, contributing guide and pull request template.
- `BENCH_VERSION` is now 1.5.0.

**Upgrading from 1.4.0 or earlier:** sites before 1.5.0 can't update themselves yet, so install 1.5.0 by hand once: unzip `bench-1.5.0.zip` (the update package, not `-full`) and upload its contents over the site by FTP. It contains no content, uploads or `config.php`, so nothing the client edited is overwritten. From then on, updates arrive in Admin → Updates.

## v1.4.0 — 2026-10-22 — Accessibility (WCAG 2.2 AAA)

- **Contrast:**
  - 7:1 for text in both themes and the admin; 3:1 for field borders and graphics.
  - Fresh buttons now use black text (white on coral had been 2.9:1).
  - Colours chosen in Settings are auto-adjusted to stay AAA, and the admin explains any change.
- **Keyboard and focus:**
  - Two-tone focus ring, 44px targets, skip link, and focus never hidden under the sticky header or mobile call bar.
  - The closed mobile menu is out of the Tab order, and opening it puts its links straight after the button.
  - The photo lightbox is a modal dialog (focus trapped, Escape returns focus).
- **No surprises:** stock filters no longer submit on change, and public links no longer open new windows.
- **Forms:** labels linked to fields, hints, required fields explained, text errors with a focused summary, and the honeypot hidden from assistive tech.
- **Text:** about 78 characters per line, 1.5 line spacing, paragraph gaps, reflow at 320px. Supports reduced motion and forced colours.
- **Admin:**
  - Labelled fields; real dialogs and ARIA tabs; section and list tools outside `<summary>`.
  - Sessions never lose unsaved work: keep-alive, no reload on 401.
  - Error messages persist until dismissed.
- **Content tools:** alt text fields for *Text & image* and gallery photos, an **Abbr** button, and an **Accessibility check** panel in the page editor.
- `BENCH_VERSION` is now 1.4.0.

## v1.3.0 — 2026-10-22 — Security hardening (PHP2Shell)

- **`.htaccess`:**
  - Public pages refuse anything but GET/HEAD, and multipart is only accepted by `admin/api.php`, so PHP never parses a request body outside the admin.
  - Blocks internals, `config.php`, `router.php`, plugin PHP, hidden files (not `.well-known`) and `.md`/`.zip`/`.patch` files.
  - Safer mod_php settings, and `X-Powered-By` removed.
- **Admin → Security:**
  - Checks PHP against known advisories (`core/security.php`), risky ini settings, HTTPS and whether the hardening rules are active.
  - Detects unexpected PHP files (web shells) and writable code.
  - Red banner while a critical PHP fix is missing; `security_acknowledged` in `config.php` covers backported fixes.
- **Admin hardening:** Content-Security-Policy with nonces, strict session mode, safer image path validation, and an optional IP allowlist (`admin/.htaccess`).
- nginx equivalents in the README.

## v1.2.0 — 2026-10-22 — Van Stock and automatic short links

- **Van Stock plugin:** vehicles collection with VAT-aware pricing, stock numbers and a van listings section, alongside Car Stock.
- **`pretty_urls` = `'auto'`:**
  - Short links switch on once the server is seen routing them, with an admin warning until then.
  - `ErrorDocument` fallback for hosts without mod_rewrite.
- **Trade theme:** dark and alt call-to-action fixes; AutoDealer and AutoRepair business types.
- PATH_INFO no longer counts as a working short link, and the listing count reads "1 car matches".

## v1.1.0 — 2026-10-19 — Plugins and Car Stock

- **Flat-file core:**
  - JSON content files with a PHP guard line, atomic writes and automatic backups.
  - A block-based page editor, settings, media library and backups.
- **Themes:** Trade and Fresh, with per-theme template overrides.
- **Contact forms:** via Web3Forms, Formspree, Basin or embeds — the server never sends mail.
- **Plugin system:** collections, section types, settings tabs and hooks.
- **Car Stock plugin** with the Ely Bridge Motors demo; demo packs switchable in the admin.
