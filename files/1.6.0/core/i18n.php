<?php
/**
 * Bench CMS — languages (optional bilingual sites, e.g. English and Welsh).
 *
 * Off by default: a site with no second language works exactly as before.
 * Switched on in Admin → Settings → Languages.
 *
 * How it works
 *  - Content is written in the site's main language, as always. Translations are
 *    stored next to it, in a "_t" object on the same page, section, list item,
 *    collection item or settings file:  {"heading": "Our services",
 *    "_t": {"cy": {"heading": "Ein gwasanaethau"}}}.  So photos, prices, layout and
 *    order are shared, and moving or deleting a section takes its translation with it.
 *  - Only text fields (text, textarea, richtext) are translated, unless the field says
 *    'translate' => false (phone numbers, slugs of other things, keys…).
 *  - The second language lives under a prefix: /cy/, /cy/cysylltu, /cy/ceir/<id>.
 *    Pages can have their own slug in each language.
 *  - Anything not yet translated falls back to the main language.
 *  - Fixed wording in themes, sections, forms and plugins goes through t('English text').
 *    Translations live in core/lang/<code>.php, plugins/<id>/lang/<code>.php and,
 *    for site-specific changes that survive updates, custom/lang/<code>.php.
 */

/** Every language Bench has a language file for: code => ['name', 'english_name', 'html']. */
function bench_languages(): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (glob(BENCH_CORE . '/lang/*.php') ?: [] as $f) {
            $code = basename($f, '.php');
            if (!preg_match('/^[a-z]{2,3}$/', $code)) {
                continue;
            }
            $d = require $f;
            $all[$code] = [
                'code'         => $code,
                'name'         => (string) ($d['name'] ?? $code),
                'english_name' => (string) ($d['english_name'] ?? $d['name'] ?? $code),
                'html'         => (string) ($d['html'] ?? $code),
                'locale'       => (string) ($d['locale'] ?? $code),
            ];
        }
        ksort($all);
    }
    return $all;
}

function bench_lang_known(string $code): bool
{
    return isset(bench_languages()[$code]);
}

/** Language settings straight from the settings file (no localising, no recursion through site()). */
function bench_lang_settings(): array
{
    static $s = null;
    if ($s === null) {
        $raw = is_file(content_path('site')) ? store_read('site', []) : [];
        $main = (string) ($raw['language'] ?? 'en');
        $main = bench_lang_known($main) ? $main : 'en';
        $second = (string) ($raw['second_language'] ?? '');
        $second = $second !== $main && bench_lang_known($second) ? $second : '';
        $s = ['main' => $main, 'second' => $second];
    }
    return $s;
}

/** The site's main language code, e.g. "en". */
function bench_primary_lang(): string
{
    return bench_lang_settings()['main'];
}

/** The languages this site is shown in, main language first: ['en'] or ['en', 'cy']. */
function bench_site_languages(): array
{
    $s = bench_lang_settings();
    return $s['second'] !== '' ? [$s['main'], $s['second']] : [$s['main']];
}

function bench_is_bilingual(): bool
{
    return count(bench_site_languages()) > 1;
}

/** Languages translations can be entered for (everything except the main language). */
function bench_translation_langs(): array
{
    return array_slice(bench_site_languages(), 1);
}

/** The language of the page being shown. Only the public site (index.php) ever changes it. */
function bench_lang(): string
{
    return $GLOBALS['bench_lang'] ?? bench_primary_lang();
}

function bench_set_lang(string $code): void
{
    $GLOBALS['bench_lang'] = in_array($code, bench_site_languages(), true) ? $code : bench_primary_lang();
}

/** True while showing a page in a language other than the main one. */
function bench_localising(): bool
{
    return isset($GLOBALS['bench_lang']) && $GLOBALS['bench_lang'] !== bench_primary_lang();
}

/** The value for <html lang="…">, e.g. "en-GB" or "cy". */
function bench_html_lang(?string $code = null): string
{
    $code ??= bench_lang();
    return bench_languages()[$code]['html'] ?? $code;
}

/* ------------------------------------------------------------------ *
 * Content translations ("_t")
 * ------------------------------------------------------------------ */

/** Whether a schema field holds words that get translated. */
function field_translatable(array $f): bool
{
    if (array_key_exists('translate', $f)) {
        return (bool) $f['translate'];
    }
    return in_array($f['type'] ?? 'text', ['text', 'textarea', 'richtext'], true);
}

/**
 * Content with translations applied for $lang (default: the current language).
 * Walks the whole structure, so sections, list items and plugin data all work
 * without their templates knowing about languages. Empty translations fall back
 * to the main-language text.
 */
function bench_localise($data, ?string $lang = null)
{
    if (!is_array($data)) {
        return $data;
    }
    $lang ??= bench_lang();
    $tr = isset($data['_t'][$lang]) && is_array($data['_t'][$lang]) ? $data['_t'][$lang] : null;
    unset($data['_t']);
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $data[$k] = bench_localise($v, $lang);
        }
    }
    if ($tr) {
        foreach ($tr as $k => $v) {
            // The page slug stays the internal one; translated slugs are handled by url()
            if ($k !== 'slug' && is_string($v) && trim($v) !== '' && array_key_exists($k, $data) && !is_array($data[$k])) {
                $data[$k] = $v;
            }
        }
    }
    return $data;
}

/** Applies bench_localise() only when a page is being shown in a second language. */
function bench_maybe_localise($data)
{
    return bench_localising() ? bench_localise($data) : $data;
}

/**
 * Cleans the "_t" part of submitted content against the same fields as the main text:
 * only translatable fields, of known languages, as plain strings; empty ones are dropped.
 * Translations of languages that aren't switched on right now are kept, so turning
 * the second language off and on again loses nothing.
 */
function clean_translations($input, array $fields): array
{
    $out = [];
    if (!is_array($input)) {
        return $out;
    }
    foreach (array_slice($input, 0, 5, true) as $lang => $vals) {
        if (!is_string($lang) || !bench_lang_known($lang) || !is_array($vals)) {
            continue;
        }
        $tr = [];
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'heading' || !field_translatable($f) || !isset($vals[$f['key']]) || !is_scalar($vals[$f['key']])) {
                continue;
            }
            $s = str_replace("\r\n", "\n", (string) $vals[$f['key']]);
            $s = in_array($f['type'], ['richtext', 'textarea', 'code'], true) ? mb_substr($s, 0, 50000) : mb_substr(trim($s), 0, 1000);
            if (trim($s) !== '') {
                $tr[$f['key']] = $s;
            }
        }
        if ($tr) {
            $out[$lang] = $tr;
        }
    }
    return $out;
}

/**
 * How much of something is translated: ['done' => n, 'total' => n] counting
 * translatable fields that have main-language text. Used by the admin.
 */
function translation_progress(array $data, array $fields, string $lang): array
{
    $done = $total = 0;
    foreach ($fields as $f) {
        $k = $f['key'] ?? '';
        if (($f['type'] ?? '') === 'list') {
            foreach (is_array($data[$k] ?? null) ? $data[$k] : [] as $item) {
                if (is_array($item)) {
                    $p = translation_progress($item, $f['fields'] ?? [], $lang);
                    $done += $p['done'];
                    $total += $p['total'];
                }
            }
            continue;
        }
        if (($f['type'] ?? '') === 'heading' || !field_translatable($f) || $k === 'slug' || !is_string($data[$k] ?? null) || trim($data[$k]) === '') {
            continue;
        }
        $total++;
        if (trim((string) ($data['_t'][$lang][$k] ?? '')) !== '') {
            $done++;
        }
    }
    return ['done' => $done, 'total' => $total];
}

/** Translation progress of a whole page (its settings and every section) in $lang. */
function page_translation_progress(array $page, string $lang): array
{
    $p = translation_progress($page, schema_page(), $lang);
    $blocks = schema_blocks();
    foreach ($page['blocks'] ?? [] as $b) {
        if (!is_array($b) || !empty($b['hidden']) || !isset($blocks[$b['type'] ?? ''])) {
            continue;
        }
        $q = translation_progress($b, array_merge($blocks[$b['type']]['fields'], schema_block_common()), $lang);
        $p['done'] += $q['done'];
        $p['total'] += $q['total'];
    }
    return $p;
}

/* ------------------------------------------------------------------ *
 * Fixed wording: t()
 * ------------------------------------------------------------------ */

/** All interface translations for $lang: core, then enabled plugins, then custom/lang. */
function bench_strings(?string $lang = null): array
{
    static $cache = [];
    $lang ??= bench_lang();
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }
    $out = [];
    if (preg_match('/^[a-z]{2,3}$/', $lang)) {
        $files = [BENCH_CORE . "/lang/$lang.php"];
        foreach ($GLOBALS['bench_registry']['plugin_dirs'] ?? [] as $dir) {
            $files[] = "$dir/lang/$lang.php";
        }
        $files[] = BENCH_CUSTOM . "/lang/$lang.php";
        foreach ($files as $f) {
            if (is_file($f)) {
                $d = require $f;
                $strings = isset($d['strings']) && is_array($d['strings']) ? $d['strings'] : (is_array($d) ? $d : []);
                $out = array_merge($out, array_filter($strings, 'is_string'));
            }
        }
    }
    return $cache[$lang] = $out;
}

/**
 * Translates fixed wording, written in English: t('Skip to content').
 * Extra arguments fill sprintf placeholders: t('Photo %1$d of %2$d', $n, $total).
 * Untranslated text is shown in English.
 */
function t(string $text, ...$args): string
{
    $s = bench_strings()[$text] ?? $text;
    return $args ? vsprintf($s, $args) : $s;
}

/** "March 2027" in the current language. */
function bench_month_year(int $ts): string
{
    return t(date('F', $ts)) . ' ' . date('Y', $ts);
}

/** Interface text bench.js needs, as JSON for a <script type="application/json"> tag. Empty in English. */
function bench_js_strings(): string
{
    $keys = [
        'Enter your %s', 'Enter an email address in the format name@example.com', 'Enter a phone number using only numbers, spaces and + ( ) -',
        'Error: %s', 'There is a problem with the form:', 'There are %d problems with the form:', 'Sending…', 'Sending your message…',
        'Thanks — message sent.', 'Sorry, your message could not be sent', 'Please try again, or call us.',
        'Photo viewer', 'Close photo viewer', 'Previous photo', 'Next photo', 'photo %1$d of %2$d',
        'Show photo %1$d of %2$d', 'Filter & sort',
    ];
    $all = bench_strings();
    $out = array_intersect_key($all, array_flip($keys));
    return $out ? '<script type="application/json" id="bench-i18n">' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . "\n" : '';
}

/* ------------------------------------------------------------------ *
 * Addresses
 * ------------------------------------------------------------------ */

/**
 * Page slugs translated into $lang: internal slug => translated slug.
 * Read straight from the page files, so it doesn't depend on localising.
 */
function bench_slug_map(string $lang): array
{
    static $maps = [];
    $key = $lang . '@' . ($GLOBALS['bench_pages_changed'] ?? 0); // re-read after a page is saved or deleted
    if (isset($maps[$key])) {
        return $maps[$key];
    }
    $map = [];
    foreach (glob(BENCH_CONTENT . '/pages/*.php') ?: [] as $f) {
        $slug = basename($f, '.php');
        if ($slug === 'home' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            continue;
        }
        $p = store_read('pages/' . $slug, []);
        $t = $p['_t'][$lang]['slug'] ?? '';
        if (is_string($t) && preg_match('/^[a-z0-9\-]+$/', $t)) {
            $map[$slug] = $t;
        }
    }
    return $maps[$key] = $map;
}

/** Internal slug → slug in $lang (unchanged if the page has no translated slug). */
function bench_slug_in(string $slug, string $lang): string
{
    return $lang === bench_primary_lang() ? $slug : (bench_slug_map($lang)[$slug] ?? $slug);
}

/** Slug in $lang → internal slug. */
function bench_slug_from(string $slug, string $lang): string
{
    if ($lang === bench_primary_lang()) {
        return $slug;
    }
    $found = array_search($slug, bench_slug_map($lang), true);
    return $found !== false ? (string) $found : $slug;
}

/**
 * Works out the language from the requested path and returns the internal path.
 * "cy/cysylltu" → sets Welsh, returns "contact". "cy" → "home". Other paths are
 * in the main language. Called once, by index.php.
 */
function bench_route_language(string $path): string
{
    bench_set_lang(bench_primary_lang());
    $parts = explode('/', $path, 2);
    if (in_array($parts[0], bench_translation_langs(), true)) {
        bench_set_lang($parts[0]);
        $rest = $parts[1] ?? '';
        if ($rest === '' || $rest === 'home') {
            return 'home';
        }
        $seg = explode('/', $rest, 2);
        $seg[0] = bench_slug_from($seg[0], $parts[0]);
        return implode('/', $seg);
    }
    return $path;
}

/** The path segment(s) after the base path for an internal slug in $lang, e.g. "cy/cysylltu" ('' for the main home page). */
function bench_lang_path(string $slug, string $lang): string
{
    $slug = trim($slug, '/');
    if ($slug === 'home') {
        $slug = '';
    }
    if ($lang === bench_primary_lang() || !in_array($lang, bench_site_languages(), true)) {
        return $slug;
    }
    if ($slug !== '') {
        $seg = explode('/', $slug, 2);
        $seg[0] = bench_slug_in($seg[0], $lang);
        $slug = implode('/', $seg);
    }
    return trim($lang . '/' . $slug, '/');
}

/** Full address (scheme and host) for a site path, for hreflang links. */
function absolute_url(string $path): string
{
    if (!isset($_SERVER['HTTP_HOST']) || !preg_match('/^[a-z0-9.\-:\[\]]+$/i', $_SERVER['HTTP_HOST'])) {
        return $path;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $path;
}

/** The internal path being shown (e.g. "contact" or "cars/2019-ford-focus-x7k2"), set by index.php. */
function bench_current_route(): string
{
    return $GLOBALS['bench_route'] ?? 'home';
}

/** This page in each of the site's languages: code => address. */
function bench_alternates(): array
{
    $out = [];
    // A missing page has no other-language version: offer that language's home page
    $route = ($GLOBALS['bench_status'] ?? 200) === 200 ? bench_current_route() : 'home';
    foreach (bench_site_languages() as $code) {
        $out[$code] = url($route, $code);
    }
    return $out;
}

/** <link rel="alternate" hreflang> tags, for bench_head(). */
function bench_hreflang_links(): string
{
    if (!bench_is_bilingual() || ($GLOBALS['bench_status'] ?? 200) !== 200) {
        return '';
    }
    $out = '';
    foreach (bench_alternates() as $code => $href) {
        $out .= '<link rel="alternate" hreflang="' . e(bench_html_lang($code)) . '" href="' . e(absolute_url($href)) . '">' . "\n";
    }
    $out .= '<link rel="alternate" hreflang="x-default" href="' . e(absolute_url(url(bench_current_route(), bench_primary_lang()))) . '">' . "\n";
    return $out;
}

/**
 * Link(s) to this page in the site's other language(s), for a theme's header.
 * Each link is written in its own language and marked with lang/hreflang, so
 * screen readers pronounce it properly (WCAG 3.1.2). Empty on one-language sites.
 */
function bench_language_switcher(string $class = 'lang-switch'): string
{
    if (!bench_is_bilingual()) {
        return '';
    }
    $links = '';
    foreach (bench_alternates() as $code => $href) {
        if ($code === bench_lang()) {
            continue;
        }
        $l = bench_languages()[$code];
        $links .= '<a class="' . e($class) . '" href="' . e($href) . '" lang="' . e($l['html']) . '" hreflang="' . e($l['html']) . '">' . e($l['name']) . '</a>';
    }
    return $links;
}

/** Admin-side summary of the language set-up, for admin.js. */
function bench_admin_languages(): array
{
    $all = bench_languages();
    return [
        'main'   => bench_primary_lang(),
        'others' => array_values(array_map(fn($c) => ['code' => $c, 'name' => $all[$c]['name'], 'english_name' => $all[$c]['english_name'], 'html' => $all[$c]['html']], bench_translation_langs())),
    ];
}
