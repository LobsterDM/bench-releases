# Bench CMS

A small, database-free CMS for small business websites. PHP + vanilla JavaScript, content stored as flat JSON files, a block-based page editor, swappable themes, and contact forms handled by a third-party service (the server never sends mail).

Ships with two demo sites, three themes (*Trade*, *Fresh* and *Executive*) and a plugin system:

- **Taff Valley Plumbing & Heating** — a fictional Cardiff plumber (installed by default)
- **Ely Bridge Motors** — a fictional used car dealer using the **Car Stock** plugin

Plugins included: **Car Stock** (used car dealers) and **Van Stock** (commercial vehicle dealers).

Switch between them in **Admin → Backups → Start from a demo site**.

## Requirements

- PHP 8.0+ with `json`, `mbstring`, `fileinfo` (standard on most hosts). `gd` is optional, used to shrink large uploaded photos.
- Apache (uses the included `.htaccess`) or nginx (see below).
- `content/` and `uploads/` must be writable by PHP.

## Install

1. Upload the folder to your web root (or a sub-folder), **including the hidden `.htaccess` files** (in the root, `content/`, `core/` and `uploads/`). FTP clients and file managers often skip files that start with a dot, so turn on "show hidden files" or upload the zip and extract it on the server.
2. Make `content/` and `uploads/` writable (`chmod -R 775 content uploads` usually does it).
3. Visit `/admin/`. The first visit asks you to create the admin account.
4. Go to **Settings → Contact form** and add your form service details (see below).

To try it locally:

```bash
php -S localhost:8000 router.php
# open http://localhost:8000 and http://localhost:8000/admin/
```

**Starting a fresh site for a client:** load the closest demo (Admin → Backups), or delete the files in `content/pages/` and `uploads/demo/`, then edit the settings and build pages in the admin. The `demos/` folder can be deleted on a live site.

## Using the admin

- **Pages** — list, reorder (sets the menu order), create, delete. Click a page to edit.
- **Page editor** — each page is a stack of *sections* (hero, services grid, text & image, why choose us, stats, reviews, gallery, areas covered, FAQs, call to action, contact form, custom HTML). Add, reorder, hide, duplicate or delete them. `Ctrl/Cmd + S` saves. Unpublished pages are visible to a logged-in admin only, for previewing.
- **Settings** — business details, logo, theme and colours, contact form, SEO (including schema.org business type and a slot for analytics code).
- **Media** — drag-and-drop uploads. JPG/PNG/WebP/GIF only, files are type-checked, and big photos are resized to 2000px (which also strips location data from phone photos). Each image shows where it's used, there's a filter for images that aren't used anywhere, and **Move unused to trash** tidies them up in one go.
- **Media → Trash** — when a vehicle, page or other item is deleted, or a photo is removed from it and saved, each of its photos that **nothing else uses** moves to the trash. Trashed photos are no longer on the website. **Restore** puts one back where it was, **Delete for good** removes one, and **Empty trash** permanently deletes them all. Restoring a vehicle or page from **Backups** also brings its photos back from the trash, as long as the trash hasn't been emptied. Set `'media_trash' => false` in `config.php` to turn this off.
- **Backups** — every save keeps the previous version (20 per file by default). One click to restore.

While logged in, an **Edit page** button floats on the public site.

## Claude connector

Ask Claude in a chat to update the site: "change the opening hours to 8am–5pm", "add this van, here are the photos", "mark the blue Transit as sold", "add a FAQ about warranties". Claude makes the change through the same code as the admin, so it's checked, backed up, and can be undone.

**Setting it up (once per site):**

1. In the site's admin, go to **Claude** and switch on **Let Claude edit this website**. It's off on every site until someone does this.
2. Copy the connector address shown there (`https://<site>/admin/mcp.php`).
3. In Claude, go to **Settings → Connectors → Add custom connector**, paste the address, and click **Connect**.
4. The site's own sign-in page opens. Log in with a Bench admin account and click **Allow**.

The site needs `https://`, Bench at the root of the domain, and Bench's `.htaccess` (or the nginx rules below). The Connections screen checks this and says if anything's wrong. Putting the connector in a Claude Project for each client, with instructions about that client's site, works well.

**What Claude can do:** read and change pages and their sections, add, change and delete collection items (vehicles…), add images from a web address, and change site settings (business details, hours, announcement bar, colours, theme, contact form, SEO).

**What it can't do:** logins, plugins, updates, security settings, or "code" settings that can run scripts (`<head>` code, form embed code). Set `'connector_allow_code' => true` in `config.php` to allow those.

**Keeping control:**

- Changes go live straight away. Each one is listed in **Admin → Connections**, with who asked, which app, and an **Undo** button. Previous versions are also in **Backups**.
- **Connected** lists the Claude apps signed in, with **Disconnect**. Switching the connector off stops all access at once, as does removing the user from `content/_auth/users.php`.

**How it works:**

- `admin/mcp.php` is an MCP server (JSON-RPC over HTTP).
- `admin/oauth.php` does OAuth 2.1 sign-in with PKCE and dynamic client registration, as Claude's custom connectors expect.
- Only Claude's own addresses (`claude.ai`, `claude.com`, and `localhost` for Claude Code or Desktop) can receive sign-in codes. `connector_redirect_hosts` in `config.php` changes this.
- Access tokens last an hour. Refresh tokens last 30 days and can only be used once. All tokens are stored hashed in `content/_auth/connector.php`.
- Fetching images from the web only reaches public addresses, re-checked on every redirect, so it can't be used to probe the server's own network.
- The code is in `core/connector.php`. The save logic shared with the admin is in `core/content-ops.php`.

## Contact forms (no `mail()`)

The contact section posts straight from the visitor's browser to a form service, which emails you. Nothing is sent from your server, so there's no SMTP setup and nothing for spam filters to block.

| Service | How it plugs in | Notes |
|---|---|---|
| **Web3Forms** (recommended default) | Paste the access key | Free tier is 250 submissions/month, unlimited forms and domains. No account needed to get a key. |
| **Formspree** | Paste the `https://formspree.io/f/…` URL as the endpoint | Free tier is 50/month; polished dashboard and spam filtering. |
| **Basin** | Paste the `https://usebasin.com/f/…` URL as the endpoint | Free tier is 1 form, 50/month. |
| **Custom** | Any service that accepts a standard HTML form POST | e.g. Getform, FormSubmit, Fabform. |
| **Embed** | Paste the embed code from a form builder | Tally, Jotform, Fillout, Google Forms. The builder controls the look. |

With Web3Forms, Formspree, Basin and Custom, the CMS draws the form in your theme's style, includes a honeypot field against bots, and submits in the background with a thank-you message (falling back to a normal POST if JavaScript fails).

Free-tier limits change; check the provider's pricing page before choosing for a client.

The demo ships with a placeholder Web3Forms key (`YOUR-WEB3FORMS-KEY`). While logged in you'll see a reminder above the form until you replace it.

## Themes / reskinning

```
themes/
  trade/
    theme.json      name, description, swatch colours for the admin picker
    layout.php      header, footer, page wrapper
    assets/style.css
    blocks/         optional: override any section's HTML
  fresh/ …
```

- **Switch theme**: Settings → Appearance. Content is untouched.
- **New theme**: copy a theme folder, rename it, edit `theme.json` and `style.css`. Most themes only need CSS: every section uses the same semantic classes (`.block--hero`, `.service`, `.review`, `.faq__item`, etc.), and `--brand` / `--accent` CSS variables are set from the admin colour pickers.
- **Change a section's markup** for one theme: copy `core/blocks/<type>.php` to `themes/<theme>/blocks/<type>.php` and edit it. The theme version wins.
- **Change a theme for one site only**: don't edit `themes/trade/` itself, because updates replace it. Copy the file you want to change to the same place under `custom/themes/trade/` (e.g. `custom/themes/trade/layout.php`) and edit the copy; for small CSS tweaks, create `custom/custom.css`, which loads after the theme's stylesheet. Updates never touch `custom/`. See `custom/README.md`.
- In `layout.php`, call `bench_head($title, $description)` inside `<head>` and `bench_footer($page)` before `</body>`. These add meta tags, LocalBusiness schema, colour overrides, the shared JS and the admin edit bar.

**Keeping new themes AAA:**
- Copy the *Accessibility (WCAG 2.2 AAA)* block from the end of an existing theme's CSS.
- Keep text at 7:1 contrast.
- Use the colour variables Bench sets, so client-picked colours stay safe: `--brand` and `--brand-deep` for text and dark backgrounds, `--accent` with `--accent-ink` for buttons, `--accent-on-dark` for accent-coloured text on dark backgrounds, and `--on-dark` / `--on-dark-muted` for body and secondary text on brand backgrounds.
- Run the checks in *Accessibility → How it was tested*.

## Adding a new section type

1. Add the type and its fields to `schema_blocks()` in `core/schema.php`. The admin form is generated automatically. Field types: `text`, `textarea`, `richtext`, `image`, `link`, `select`, `checkbox`, `color`, `code`, `list`.
2. Create `core/blocks/<type>.php`. Inside it, `$b` is the section's data, `$site` the settings, `$anchor` the optional ID. Use `section_open()` / `section_head()` for consistent wrappers.

## Plugins

Plugins add features without touching core files. Each one is a folder in `plugins/`, switched on and off in **Admin → Plugins**. Content a plugin created is kept if you switch it off.

```
plugins/car-stock/
  plugin.json          name, description, version
  plugin.php           registers everything (runs on each request while enabled)
  blocks/*.php         templates for new section types
  templates/*.php      templates for detail pages
  assets/              CSS/JS the plugin adds to the site
```

What `plugin.php` can register:

| Function | What it adds |
|---|---|
| `register_collection('cars', [...])` | A list of items with its own menu entry, list screen and editor in the admin, stored in `content/collections/cars/`. With a `route`, each item gets a page, e.g. `/cars/2019-ford-focus-1945`. |
| `register_block('car_listings', [...])` | A new section type in the page editor. |
| `register_settings('Car stock', [...])` | A new tab in Settings. Values appear in `$site`. |
| `add_hook('head' / 'footer', fn)` | Extra HTML on every page (stylesheets, scripts). |

Helpers for templates: `items_all($id, $publicOnly)`, `item_get()`, `item_title()`, `item_url()`, `item_image()`, `plugin_asset(__DIR__, 'assets/x.css')`, and `bench_contact_form([...])` to drop in an enquiry form that goes through the site's form service (with extra hidden fields, subject and default message).

Collection fields use the same field types as sections, plus `number` (with `prefix`/`suffix`), `date`, `gallery` (multi-photo upload with reordering) and `heading` (a divider in the editor). Add `'width' => 'half'` or `'third'` to lay fields out side by side.

Plugin CSS should use the theme variables (`--brand`, `--accent`, `--radius`, `--line`, `--muted`…) so it fits any theme. A theme can replace a plugin's templates: put `blocks/<type>.php` or `templates/<collection>-detail.php` in the theme folder.

### Car Stock plugin

- **Admin → Cars**: add a car with make, model, variant, year, price (and optional "was" price), mileage, fuel, gearbox, body, MOT, history, photos, description and features. Status can be *Available*, *Reserved*, *Sold* (stays on the site with a SOLD banner) or *Draft* (hidden). The registration is private and only sent with enquiries.
- **Car listings section**: add to any page. Either a full stock list with make/body/fuel/gearbox/price filters and sorting (plain links, so a filtered search can be shared), or a "featured cars" strip for the home page.
- **Car pages** at `/cars/<id>`: photo gallery with thumbnails, swipe and full-screen view, key specs, full spec table, finance line, similar cars, and an enquiry form pre-filled with the car's details. Includes schema.org `Car` data for Google.
- Make a page with the slug `cars` for the stock list (the demo has one).

### Van Stock plugin

A commercial-vehicle version of Car Stock for van, pick-up and minibus dealers. It can run alongside Car Stock (its functions are prefixed `van_`, its ids are `vans` / `van_listings`, and it reuses the `.car-*` CSS class names).

- **Admin → Vehicles**: stock number, make, model, variant, year, price, **VAT** (*Plus VAT*, *Price includes VAT* or *No VAT to pay*), optional "was" price, status, finance line, type (small/medium/large van, crew van, dropside, tipper, Luton, pick-up, 4x4, minibus…), mileage, fuel, gearbox, engine, colour, wheelbase, roof height, seats, payload, MOT, history, photos, description and features. A blank price shows "Call for price".
- **Van listings section**: full stock list with make/type/fuel/gearbox/price filters, or a "latest vehicles" strip. A VAT note shows under every list.
- **Vehicle pages** at `/vans/<id>`, with gallery, prices shown with their VAT label, spec table, warranty and finance notes, and an enquiry form carrying the vehicle and price.
- **Settings → Van stock**: the warranty note, VAT note, finance note and part-exchange question.
- Make a page with the slug `vans` for the stock list.

To use it: switch it on in **Admin → Plugins**, create a page with the slug `vans` containing a *Van listings* section, then add vehicles. Setting **Settings → SEO → Business type** to `AutoDealer` suits vehicle sales sites.

To build another vertical (property listings, a restaurant menu, holiday cottages, staff profiles…), copy `plugins/car-stock`, rename it and change the fields and templates.

## How content is stored

```
content/
  site.php            settings
  pages/home.php      one file per page (slug = file name)
  plugins.php         which plugins are switched on
  _rewrite_ok         created when the server is seen handling short links (delete when moving servers)
  collections/cars/   one file per plugin item
  _auth/users.php     admin account (bcrypt hash)
  _backups/           previous versions
  _trash/             photos in Media → Trash (files/ plus index.php); blocked from the web like the rest of content/
```

A photo counts as **used** if its path (`uploads/…`) appears anywhere in a content file: any field of any page, item or setting, including rich text and custom code. Items from plugins that are switched off still count. Backups and the trash don't.

Each file is JSON with a first line of `<?php http_response_code(403); exit; ?>`, so it can't be downloaded even if a server ignores `.htaccess`. To move a site, copy the folder. To back it up, zip `content/` and `uploads/`. Content files are plain text, so they're fine to keep in git.

## Security

### PHP itself: keep it patched

Some vulnerabilities are in PHP, not in any website's code. PHP2Shell (CVE-2026-109618 / CVE-2026-109619, fixed 21 October 2026) let an attacker run code on a server by sending a crafted request. It fires while PHP is still reading the request, before Bench or WordPress or any other app runs, so **only updating PHP fixes it.** Fixed releases: 8.2.35, 8.3.36, 8.4.27, 8.5.12. PHP 8.1 and older get no fix.

Bench can't patch PHP, but it reduces exposure and tells you when something is wrong:

- **Admin → Security** checks the PHP version against known critical advisories (listed in `core/security.php`, easy to extend), risky PHP settings, HTTPS, whether the `.htaccess` hardening is active, and unexpected PHP files (likely web shells) in the site folder, `uploads/` and `content/`. A red banner shows across the admin while a critical PHP fix is missing.
- Linux distributions and control panels often **backport** fixes without changing the version number. If the host confirms the fix is in, add the advisory id to `security_acknowledged` in `config.php` (e.g. `['php2shell']`) to hide the warning.
- The Security screen includes a short note to forward to the host, with the PHP settings to use.

### What the server rules do (`.htaccess`, or the nginx rules below)

- **Public pages refuse anything but GET/HEAD.** Bench's public site never receives form posts, because contact forms send straight to the form service. So outside `/admin/`, PHP never has to parse a request body, which removes most of the exposure to request-parsing bugs like PHP2Shell. **Uploads (multipart)** are refused everywhere except the admin's upload endpoint.
- Internal folders (`content/`, `core/`, `demos/`, `docs/`, `tests/`), `config.php`, `router.php`, plugin PHP files, hidden files (`.git`, `.env`) and notes/archives (`.md`, `.zip`, `.patch`…) can't be fetched. `.well-known/` stays reachable for SSL certificates.
- Scripts can't run from `uploads/`.
- With **mod_php**, safer PHP settings are applied automatically: `session.upload_progress.enabled` off, `display_errors` off, `session.use_strict_mode` on, and post/upload size limits. With **PHP-FPM / LiteSpeed**, the host has to set these in php.ini or the pool config. `.user.ini` and `ini_set()` take effect too late to help against PHP2Shell.
- The `X-Powered-By` header (which tells scanners the PHP version) is removed. Security headers are set: `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`.
- **Optional:** `admin/.htaccess` has a commented-out IP allowlist. If the client always edits from a fixed IP, enabling it means attackers can't reach the admin at all.

### In Bench's own code

- No database, so no SQL injection. No Composer packages, so no third-party code to go out of date. No `eval`, `unserialize`, shell commands, or includes built from user input.
- Passwords use `password_hash` (bcrypt). The session ID is regenerated on login, cookies are HttpOnly/SameSite/Secure on HTTPS, and sessions use strict mode (no session fixation).
- CSRF tokens are required on every admin action. Login locks for 15 minutes after 5 failed attempts per IP.
- The admin sends a **Content-Security-Policy** that only allows its own scripts (with a per-request nonce), so injected script can't run there.
- All output is escaped. Rich text keeps a small tag allowlist, strips attributes and blocks `javascript:` links. Image fields only accept upload paths or plain `https://` URLs.
- Uploads are checked by content (not extension) and renamed. JPEG photos are re-encoded, which strips EXIF/GPS data.
- The **Claude connector** is off until switched on in Admin → Connections. It needs a Bench login plus an "Allow" click, can't touch logins, plugins, updates, security or script-running settings, and logs every change with an undo. See **Claude connector** above.
- The *Custom HTML* section, the form *Embed* field and *Extra head code* output raw HTML by design (for embeds and analytics). Only give admin access to people you'd trust with that.
- Content files start with a PHP exit line, so they can't be read over the web even if `.htaccess` is ignored.

### Good practice for live sites

- Use HTTPS (Let's Encrypt is usually free with the host).
- If the host allows it, make everything except `content/` and `uploads/` read-only for the web server user.
- Keep an off-site copy of `content/` and `uploads/`. That's the whole site's data, so a compromised server can be rebuilt from a clean Bench zip plus those two folders.
- Delete `demos/`, `docs/`, `tests/` and `router.php` on live sites if you like. They're blocked anyway.

## Accessibility (WCAG 2.2 AAA)

Bench's templates, all three themes, the Car Stock and Van Stock plugins and the admin are built and tested to **WCAG 2.2 Level AAA**. Some AAA criteria depend on what editors write and upload, so Bench gives them the tools and a checker for those (see below).

### What Bench does for you

- **Contrast 7:1** for all text in every theme and the admin, and 3:1 for field borders, focus rings and graphics such as star ratings.
- **Colours picked in Settings are made safe automatically.** If a brand or button colour would fail 7:1, Bench uses the nearest shade that passes, keeping the hue. It also works out readable greys for text on brand-coloured backgrounds. The admin shows a note under each colour picker explaining any change (`bench_accessible_colours()` in `core/bootstrap.php`).
- **Keyboard:**
  - a skip link
  - a two-tone focus ring visible on any background
  - the closed mobile menu isn't reachable with Tab, and opening it puts its links next in order; Escape closes it
  - the photo lightbox is a real dialog: focus moves in, Tab stays inside, Escape returns you to the photo
  - nothing focused ever hides under the sticky header or the mobile call bar
- **Targets** are at least 44 × 44px (links in sentences are exempt).
- **Text blocks:**
  - at most about 78 characters per line
  - line spacing 1.5 or more, with clear paragraph gaps
  - no justified text
  - reflows at 320px with no sideways scrolling, and survives the 1.4.12 text-spacing test
- **No surprise changes of context:**
  - stock filters only apply when Search is pressed
  - links don't open new windows (the admin's "View" links say "opens in a new tab")
- **Forms:**
  - visible labels tied to their fields, and hints where useful
  - required fields explained, with the phone number offered as an alternative
  - errors listed in a summary that gets focus, each linked to its field and described next to it in text
  - the honeypot spam trap is hidden from keyboard and screen readers
- **Reduced motion:** with "reduce motion" turned on, all transitions, hover movement and smooth scrolling stop.
- **Structure:**
  - landmarks (header, nav, main, footer), with the current page marked in menus
  - headings in order (item headings become h2 when a section has no heading)
  - breadcrumbs on vehicle pages
  - stars, thumbnails and buttons all have text names, and "View car" links include the car's name
- **Admin:**
  - all fields labelled
  - dialogs, tabs and section tools work fully by keyboard
  - **an expired login never loses work**: the page isn't reloaded, the editor is told how to log back in and save, and a keep-alive stops sessions timing out mid-edit
  - error messages stay until dismissed

### What editors need to do (Bench helps)

The **Accessibility check** panel in the page editor flags these as you work:

- **Alt text** for every meaningful image. *Text & image* sections and gallery photos have an "Image description" field. Hero background images are treated as decoration.
- **Link and button text** that makes sense on its own. The checker flags "click here", "read more" and similar.
- **Reading level** (3.1.5). The checker estimates it, and AAA asks for text readable by around age 12–14, or a simpler summary alongside.
- **Abbreviations** (3.1.4). The checker lists them. Write them out once, or select one and press **Abbr** in the text toolbar.
- **Embedded media** in *Custom HTML* sections: video needs captions, audio description and, for AAA, sign language and extended audio description (1.2.6–1.2.9). Bench can't check embeds, so the checker reminds you.
- Also editor-dependent, and not checkable automatically: unusual words or jargon need explaining (3.1.3), and pronunciation must be given where meaning depends on it (3.1.6). Images of text should only be logos (1.4.9).

W3C notes that AAA can't be met for every kind of content (live video, for example). So a site's AAA claim depends on its content as well as on Bench. For public-sector sites, add an accessibility statement page.

### How it was tested

- **axe-core 4.10** with the WCAG 2.0/2.1/2.2 A, AA and AAA rule sets plus best practices, on every page type in every theme, at 1280px and 320px wide.
- **Custom checks** for things axe doesn't measure:
  - 44px targets
  - real characters per line
  - line spacing
  - focus visibility
  - new windows
  - reflow
- **Pixel-sampled contrast** wherever text sits on gradients, stripes or photos.
- **32 scripted keyboard and behaviour tests** covering the skip link, menu, lightbox, gallery, form errors, filters, text spacing, reduced motion, admin dialogs, tabs, labels and session expiry.
- **Repeated with awkward custom colours** to prove the automatic adjustment.

Automated tools can't replace people. Before formally claiming AAA for a client site, do a pass with a screen reader (NVDA or VoiceOver) and ideally with disabled users.

## nginx

```nginx
# Bench CMS: equivalent of the .htaccess rules (inside the server { } block)
location ~ ^/(content|core|demos|docs|tests)(/|$) { deny all; }
location ~ ^/(config|router)\.php$ { deny all; }
location ~ ^/plugins/.*\.php$ { deny all; }
location ~ /\.(?!well-known/) { deny all; }
location ~ \.(md|patch|diff|zip|log|bak|sql|sh|ini)$ { deny all; }
location ~ ^/uploads/.*\.(php\d?|phtml|phar|html?|svg)$ { deny all; }

# Only the admin (and the Claude connector in it) receives posts; only the admin upload endpoint receives multipart
set $bench_post "";
if ($request_method !~ ^(GET|HEAD)$) { set $bench_post "1"; }
if ($request_uri ~ "^[^?]*/admin/(index\.php|api\.php|mcp\.php|oauth\.php/(register|token|authorize))?(\?|$)") { set $bench_post ""; }
if ($bench_post) { return 403; }
set $bench_mp "";
if ($http_content_type ~* "multipart/") { set $bench_mp "1"; }
if ($uri ~ "/admin/api\.php$") { set $bench_mp ""; }
if ($bench_mp) { return 403; }

# Claude connector sign-in (Admin → Connections)
rewrite ^/\.well-known/oauth-authorization-server(/.*)?$ /admin/oauth.php?endpoint=authorization-server last;
rewrite ^/\.well-known/openid-configuration(/.*)?$ /admin/oauth.php?endpoint=openid-configuration last;
rewrite ^/\.well-known/oauth-protected-resource(/.*)?$ /admin/oauth.php?endpoint=protected-resource last;
rewrite ^/admin/oauth\.php/([a-z\-]+)$ /admin/oauth.php?endpoint=$1 last;

location / { try_files $uri $uri/ /index.php?p=$uri&$args; }
# In your existing PHP location, add this so Admin → Security knows the rules are active:
#   fastcgi_param BENCH_HARDENING 1;
```

### Short links (/vans) and hosts without rewrites

`pretty_urls` in `config.php` defaults to `'auto'`. Bench prints plain `index.php?p=vans` links until it sees the server handle a short link, then switches to `/vans` by itself (it notes this in `content/_rewrite_ok`; delete that file if you move the site to a different server). So the site works on any host, and **Admin** shows a yellow warning while short links aren't working. The usual fixes are:

- **The `.htaccess` file wasn't uploaded.** Upload it (see Install).
- **The host has no `mod_rewrite`.** The `.htaccess` file falls back to `ErrorDocument 404 /index.php`, which Bench understands. If you're in a sub-folder, edit that path.
- **The host ignores `.htaccess`** (`AllowOverride None`) or **runs nginx.** Ask the host to allow it, or use the nginx rule below.

Set `pretty_urls` to `true` to always print short links, or `false` to never use them.

If you install in a sub-folder and short links misbehave, set `base_path` in `config.php`, or set `pretty_urls` to `false` to use `index.php?p=slug` links.

## Updates

Sites update themselves from **Admin → Updates**, much like WordPress.

- Bench checks the release channel twice a day: during admin visits, and after public page views on servers running PHP-FPM or LiteSpeed (once the page has been sent, so visitors never wait).
- **Fix releases** (1.5.0 → 1.5.1) install automatically unless that's switched off on the Updates screen. **Feature releases** (1.5 → 1.6) wait for someone to click **Update**, after reading what's new.
- **What an update changes:** only the files Bench ships (`core/`, `admin/`, `assets/`, the built-in themes and plugins, `index.php`, `.htaccess`…). `content/`, `uploads/`, `custom/` and `config.php` are never touched. Other plugins and themes are left alone.
  - Bench files edited on the server are replaced, and listed after the update; the old copies are in the update backup. Put site-specific changes in `custom/` instead.
  - A customised `.htaccess` is kept, and the new one is saved as `.htaccess.new` to compare.
- **Safety:**
  - Each release's manifest is signed with Ed25519, and lists the package's SHA-256; the package lists every file's SHA-256. Sites refuse anything that doesn't match, so even a hacked release server can't push code to sites.
  - The files being replaced are backed up first (the last three updates are kept, in `content/_updates/backups/`).
  - After installing, Bench loads the home page and the admin. If either fails, the previous version is put back automatically and that release isn't auto-installed again.
  - **Go back to …** on the Updates screen undoes the last update by hand.
- **Needs:** PHP able to make web requests (`curl`, or `allow_url_fopen`) and to write to the Bench files. The Updates screen says if anything's missing.
  - PHP's `sodium` and `zip` extensions are used when the host has them. Without them (Fasthosts, for one), Bench checks signatures with its bundled pure-PHP code (`core/vendor/sodium_compat`, from [paragonie/sodium_compat](https://github.com/paragonie/sodium_compat), ISC licence) and downloads just the changed files, one at a time, from the release channel's unpacked copy. Every file is still checked against the signed release. If a host doesn't allow writing, upload the update package by FTP instead.
- **Turning it off:** add `'updates' => false` to `config.php`, e.g. for a site deployed from its own Git repository. Automatic installs are already off when the site folder is a Git checkout.

Releases are published by the release workflow to a public channel repo (`LobsterDM/bench-releases`). See **CONTRIBUTING.md → Releasing**.

## Version control

Bench lives in Git. `main` is always releasable, and features arrive by pull request on `feature/…` branches. Releases are tagged (`v1.4.0`), with notes in `CHANGELOG.md`. GitHub Actions runs three sets of checks on every push and pull request: lint and smoke tests on PHP 8.2–8.4, the Apache security rules, and the WCAG 2.2 AAA audit.

Live sites get new versions through **Admin → Updates** (above). A client site can also have its own repo, for its `custom/` folder, content snapshots, or Git-based deploys. See **CONTRIBUTING.md** for the branch workflow, starting a client site, merging updates, and deploying without overwriting content edited on the live site.

## File map

```
index.php            public front controller
router.php           only for `php -S` local dev
config.php           server-level options
core/bootstrap.php   storage, URLs, rendering, helpers
core/schema.php      section types, page fields, settings
core/icons.php       built-in SVG icon set
core/blocks/*.php    default section templates
admin/               admin area (index.php, api.php, assets/)
core/plugins.php     plugin registry and collections
core/forms.php       contact form (reusable by plugins)
core/security.php    PHP advisory list and the Admin → Security checks
core/updater.php     Admin → Updates: signed release check, install, rollback
custom/              this site's own changes; never touched by updates
core/media.php       where images are used, and the media trash
core/content-ops.php saving pages, items, settings and images (admin and connector)
core/connector.php   Claude connector: sign-in, tokens, change log
admin/mcp.php        Claude connector endpoint (MCP)
admin/oauth.php      Claude connector sign-in (OAuth)
assets/bench.js      front-end JS: mobile menu, AJAX forms, lightbox
plugins/car-stock/   used car listings plugin
plugins/van-stock/   commercial vehicle listings plugin
demos/               demo site packs (plumber, car-dealer)
tests/               smoke.sh, media.sh (media trash), updater.sh, connector.sh (Claude connector), apache.sh (security rules), a11y/ (WCAG 2.2 AAA audit)
tools/release.php    builds and signs release packages (used by the release workflow)
.github/             CI and release workflows, pull request template
themes/              trade, fresh, executive
uploads/             images (demo images in uploads/demo)
```
