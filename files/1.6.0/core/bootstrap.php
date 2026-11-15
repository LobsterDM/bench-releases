<?php
/**
 * Bench CMS — core
 * Flat-file storage, URLs, rendering helpers. No database required.
 */

declare(strict_types=1);

define('BENCH_VERSION', '1.6.0');
define('BENCH_ROOT', dirname(__DIR__));
define('BENCH_CONTENT', BENCH_ROOT . '/content');
define('BENCH_UPLOADS', BENCH_ROOT . '/uploads');
define('BENCH_THEMES', BENCH_ROOT . '/themes');
// Site-specific files that Bench updates never touch (like a WordPress child theme)
define('BENCH_CUSTOM', BENCH_ROOT . '/custom');
define('BENCH_CORE', __DIR__);

// Every content file starts with this line so it can never be read over the web,
// even on servers that ignore .htaccess.
const BENCH_GUARD = "<?php http_response_code(403); exit; ?>\n";

$GLOBALS['bench_config'] = require BENCH_ROOT . '/config.php';
date_default_timezone_set($GLOBALS['bench_config']['timezone'] ?? 'UTC');
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By'); // don't advertise the PHP version to scanners
}

require BENCH_CORE . '/icons.php';
require BENCH_CORE . '/i18n.php';
require BENCH_CORE . '/schema.php';
require BENCH_CORE . '/plugins.php';
require BENCH_CORE . '/forms.php';
require BENCH_CORE . '/updater.php';

function cfg(string $key, $default = null)
{
    return $GLOBALS['bench_config'][$key] ?? $default;
}

/* ------------------------------------------------------------------ *
 * Storage
 * ------------------------------------------------------------------ */

function content_path(string $name): string
{
    // $name like "site", "pages/home" or "collections/cars/abc" — only safe characters allowed
    if (!preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+){0,2}$#', $name)) {
        throw new InvalidArgumentException('Bad content name');
    }
    return BENCH_CONTENT . '/' . $name . '.php';
}

function store_read(string $name, $default = null)
{
    $file = content_path($name);
    if (!is_file($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    if (strncmp($raw, BENCH_GUARD, strlen(BENCH_GUARD)) === 0) {
        $raw = substr($raw, strlen(BENCH_GUARD));
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function store_write(string $name, array $data, bool $backup = true): bool
{
    $file = content_path($name);
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if ($backup && is_file($file)) {
        backup_file($name, $file);
    }
    if (strncmp($name, 'pages/', 6) === 0) {
        $GLOBALS['bench_pages_changed'] = ($GLOBALS['bench_pages_changed'] ?? 0) + 1;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Atomic write: temp file then rename, so a half-written file is never served
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, BENCH_GUARD . $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $file);
}

function store_delete(string $name): bool
{
    $file = content_path($name);
    if (!is_file($file)) {
        return false;
    }
    backup_file($name, $file);
    if (strncmp($name, 'pages/', 6) === 0) {
        $GLOBALS['bench_pages_changed'] = ($GLOBALS['bench_pages_changed'] ?? 0) + 1;
    }
    return unlink($file);
}

function backup_file(string $name, string $file): void
{
    $dir = BENCH_CONTENT . '/_backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $key = str_replace('/', '__', $name);
    copy($file, $dir . '/' . $key . '@' . date('Ymd-His') . '-' . random_int(100, 999) . '.php');

    $all = glob($dir . '/' . $key . '@*.php') ?: [];
    rsort($all);
    foreach (array_slice($all, (int) cfg('backups_to_keep', 20)) as $old) {
        @unlink($old);
    }
}

function backups_list(): array
{
    $out = [];
    foreach (glob(BENCH_CONTENT . '/_backups/*.php') ?: [] as $f) {
        $base = basename($f, '.php');
        [$key, $stamp] = array_pad(explode('@', $base, 2), 2, '');
        $out[] = [
            'file'  => basename($f),
            'name'  => str_replace('__', '/', $key),
            'time'  => filemtime($f),
            'size'  => filesize($f),
        ];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/* ------------------------------------------------------------------ *
 * Site + pages
 * ------------------------------------------------------------------ */

function site(): array
{
    static $site = null;
    static $localised = [];
    if ($site === null) {
        $site = store_read('site', []) + schema_defaults(schema_settings());
    }
    // On a page in the second language, settings such as the tagline come back translated
    return bench_localising() ? ($localised[bench_lang()] ??= bench_localise($site)) : $site;
}

function slugify(string $s): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim($s), 'UTF-8') : strtolower(trim($s));
    // Accented letters (Welsh â ê î ô û ŵ ŷ, and others) become plain ones: "Cysylltu â ni" → "cysylltu-a-ni"
    $s = strtr($s, [
        'â' => 'a', 'ä' => 'a', 'á' => 'a', 'à' => 'a', 'ê' => 'e', 'ë' => 'e', 'é' => 'e', 'è' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o',
        'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ù' => 'u', 'ŵ' => 'w', 'ẅ' => 'w', 'ẃ' => 'w', 'ẁ' => 'w',
        'ŷ' => 'y', 'ÿ' => 'y', 'ý' => 'y', 'ỳ' => 'y', 'ç' => 'c', 'ñ' => 'n', '’' => '', "'" => '',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'page';
}

function pages_all(bool $includeDrafts = true): array
{
    $pages = [];
    foreach (glob(BENCH_CONTENT . '/pages/*.php') ?: [] as $f) {
        $slug = basename($f, '.php');
        $p = store_read('pages/' . $slug);
        if (!$p) {
            continue;
        }
        $p['slug'] = $slug;
        if (!$includeDrafts && empty($p['published'])) {
            continue;
        }
        $pages[] = bench_maybe_localise($p);
    }
    usort($pages, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
    return $pages;
}

function page_get(string $slug): ?array
{
    if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
        return null;
    }
    $p = store_read('pages/' . $slug);
    if ($p) {
        $p['slug'] = $slug;
        $p = bench_maybe_localise($p);
    }
    return $p;
}

function nav_items(): array
{
    $items = [];
    foreach (pages_all(false) as $p) {
        if (!empty($p['in_nav'])) {
            $items[] = [
                'label' => $p['nav_label'] ?: $p['title'],
                'url'   => url($p['slug']),
                'slug'  => $p['slug'],
            ];
        }
    }
    return $items;
}

/* ------------------------------------------------------------------ *
 * URLs
 * ------------------------------------------------------------------ */

function base_path(): string
{
    $cfg = rtrim((string) cfg('base_path', ''), '/');
    if ($cfg !== '') {
        return $cfg;
    }
    // Auto-detect from the script location (works for index.php and admin/*.php)
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (substr($dir, -6) === '/admin') {
        $dir = substr($dir, 0, -6);
    }
    return $dir === '.' ? '' : $dir;
}

/**
 * Whether to print short links like /vans.
 * config 'pretty_urls': true / false, or 'auto' (default) = only once the server
 * has been seen routing a short link to index.php, so a host without working
 * rewrites still gets a fully working site via index.php?p=vans links.
 */
function pretty_urls(): bool
{
    $c = cfg('pretty_urls', 'auto');
    if ($c === true || $c === false) {
        return $c;
    }
    static $ok = null;
    return $ok ??= is_file(BENCH_CONTENT . '/_rewrite_ok');
}

/** Record that short links reach index.php on this server (called by index.php). */
function pretty_urls_mark_ok(): void
{
    $f = BENCH_CONTENT . '/_rewrite_ok';
    if (!is_file($f)) {
        @file_put_contents($f, date('c'));
    }
}

/**
 * The slug a request is for: from ?p= (rewrite rule or plain links), or from
 * the request path itself (servers that hand unknown paths to index.php, e.g.
 * the .htaccess ErrorDocument fallback, or nginx try_files without ?p=).
 * Returns [slug, cameViaShortLink].
 */
function request_slug(): array
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = base_path();
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = trim(rawurldecode($path), '/');
    // /index.php/foo (PATH_INFO) doesn't prove rewrites work, so it doesn't count as a short link
    $short = $path !== '' && $path !== 'index.php' && strpos($path, 'index.php/') !== 0;
    if (isset($_GET['p']) && is_string($_GET['p'])) {
        return [strtolower(trim($_GET['p'], '/')), $short];
    }
    return [$short ? strtolower($path) : 'home', $short];
}

/**
 * Address of a page or item ("contact", "cars/<id>"). On a bilingual site it's in
 * the language being shown (or $lang): /cy/cysylltu, with the page's Welsh slug.
 */
function url(string $slug = '', ?string $lang = null): string
{
    $path = bench_lang_path($slug, $lang ?? bench_lang());
    if ($path === '') {
        return base_path() . '/';
    }
    return pretty_urls()
        ? base_path() . '/' . $path
        : base_path() . '/index.php?p=' . str_replace('%2F', '/', rawurlencode($path));
}

/** The value of ?p= for a page, for forms that submit to it when short links are off ("cy/ceir"). */
function url_param(string $slug): string
{
    return bench_lang_path($slug, bench_lang()) ?: 'home';
}

/** Turn whatever the editor typed into a usable href: "contact" → /contact, "#quote", tel:, https://… */
function link_to(string $link): string
{
    $link = trim($link);
    if ($link === '') {
        return '#';
    }
    if (preg_match('#^(https?:|mailto:|tel:|\#|/)#i', $link)) {
        return $link;
    }
    if (preg_match('/^[a-z0-9\-]+(#[\w\-]+)?$/i', $link)) {
        [$slug, $hash] = array_pad(explode('#', $link, 2), 2, '');
        return url($slug) . ($hash !== '' ? '#' . $hash : '');
    }
    return '#';
}

function media_url(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#', $path) || $path[0] === '/') {
        return $path;
    }
    return base_path() . '/' . ltrim($path, '/');
}

function theme_name(): string
{
    $t = site()['theme'] ?? 'trade';
    $ok = preg_match('/^[a-z0-9_\-]+$/', $t) && (is_dir(BENCH_THEMES . '/' . $t) || is_dir(BENCH_CUSTOM . '/themes/' . $t));
    return $ok ? $t : 'trade';
}

/**
 * Path to a file of the current theme. A copy in custom/themes/<theme>/ wins over
 * the built-in one, so a site can change any theme file and still take updates.
 */
function theme_file(string $rel): string
{
    $rel = ltrim($rel, '/');
    $custom = BENCH_CUSTOM . '/themes/' . theme_name() . '/' . $rel;
    return is_file($custom) ? $custom : BENCH_THEMES . '/' . theme_name() . '/' . $rel;
}

function theme_url(string $file): string
{
    $path = theme_file($file);
    $v = is_file($path) ? '?v=' . filemtime($path) : '';
    $dir = strpos($path, BENCH_CUSTOM . '/') === 0 ? '/custom/themes/' : '/themes/';
    return base_path() . $dir . theme_name() . '/' . ltrim($file, '/') . $v;
}

/** <link> for custom/custom.css, if the site has one. Themes print this after their own stylesheet. */
function custom_css(): string
{
    $f = BENCH_CUSTOM . '/custom.css';
    return is_file($f) ? '<link rel="stylesheet" href="' . e(base_path() . '/custom/custom.css?v=' . filemtime($f)) . '">' . "\n" : '';
}

function themes_all(): array
{
    $out = [];
    foreach (array_merge(glob(BENCH_THEMES . '/*/theme.json') ?: [], glob(BENCH_CUSTOM . '/themes/*/theme.json') ?: []) as $f) {
        $id = basename(dirname($f));
        $meta = json_decode(file_get_contents($f), true) ?: [];
        $out[$id] = $meta + ($out[$id] ?? []) + ['name' => $id, 'description' => ''];
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Output helpers
 * ------------------------------------------------------------------ */

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Light rich text: keeps a safe set of tags, strips attributes (except safe hrefs),
 * and turns blank-line-separated text into paragraphs.
 */
function rich(?string $html): string
{
    $html = trim((string) $html);
    if ($html === '') {
        return '';
    }
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><h4><blockquote><abbr><img>');
    $html = preg_replace_callback('#<(/?)([a-z0-9]+)([^>]*)>#i', function ($m) {
        [$all, $close, $tag, $attrs] = $m;
        $tag = strtolower($tag);
        if ($close) {
            return '</' . $tag . '>';
        }
        // <abbr title="…"> explains abbreviations (WCAG 3.1.4)
        if ($tag === 'abbr') {
            $title = preg_match('#title\s*=\s*(["\'])(.*?)\1#i', $attrs, $t) ? html_entity_decode($t[2]) : '';
            return $title !== '' ? '<abbr title="' . e($title) . '">' : '<abbr>';
        }
        // <img>: only an uploads/ path or a plain https URL, with its alt text (empty alt = decorative)
        if ($tag === 'img') {
            $src = preg_match('#src\s*=\s*(["\'])(.*?)\1#i', $attrs, $t) ? trim(html_entity_decode($t[2])) : '';
            $src = ltrim(preg_replace('#^' . preg_quote(base_path(), '#') . '/#', '', $src), '/');
            if (!preg_match('#^(uploads/[\w\-./]+|https://[^\s\'"()<>\\\\]+)$#', $src) || strpos($src, '..') !== false) {
                return '';
            }
            $alt = preg_match('#alt\s*=\s*(["\'])(.*?)\1#i', $attrs, $t) ? html_entity_decode($t[2]) : '';
            return '<img src="' . e(media_url($src)) . '" alt="' . e($alt) . '" loading="lazy">';
        }
        if ($tag !== 'a') {
            return '<' . $tag . '>';
        }
        $href = '#';
        if (preg_match('#href\s*=\s*(["\'])(.*?)\1#i', $attrs, $h)) {
            $href = link_to(html_entity_decode($h[2]));
        }
        // Links open in the same tab: new windows are a change of context (WCAG 3.2.5)
        $ext = preg_match('#^https?://#i', $href) ? ' rel="noopener"' : '';
        return '<a href="' . e($href) . '"' . $ext . '>';
    }, $html);

    if (!preg_match('#<(p|ul|ol|h2|h3|h4|blockquote)>#', $html)) {
        $paras = preg_split("/\R{2,}/", $html);
        $html = implode("\n", array_map(fn($p) => '<p>' . nl2br(trim($p)) . '</p>', $paras));
    }
    return $html;
}

function tel_link(string $phone): string
{
    return 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
}

/** Validates a CSS colour from settings before it goes into a <style> tag. */
function css_color(?string $c): ?string
{
    $c = trim((string) $c);
    return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c) ? $c : null;
}

/* ------------------------------------------------------------------ *
 * Rendering
 * ------------------------------------------------------------------ */

/** Finds a template: the theme's own version first (custom/themes/ copy before the built-in one), then a plugin's, then the core default. */
function template_path(string $kind, string $name): ?string
{
    if (!preg_match('/^[a-z0-9_\-]+$/', $name)) {
        return null;
    }
    $candidates = [theme_file("$kind/$name.php")];
    if ($kind === 'blocks' && isset($GLOBALS['bench_registry']['blocks'][$name])) {
        $candidates[] = $GLOBALS['bench_registry']['blocks'][$name]['_template'];
    }
    $candidates[] = BENCH_CORE . "/$kind/$name.php";
    foreach ($candidates as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

function render_block(array $block, int $index, array $page): string
{
    $type = $block['type'] ?? '';
    $tpl = template_path('blocks', $type);
    if (!$tpl || !empty($block['hidden'])) {
        return '';
    }
    $b = $block + schema_defaults(schema_blocks()[$type]['fields'] ?? []);
    $site = site();
    $anchor = !empty($b['anchor']) ? slugify($b['anchor']) : '';
    ob_start();
    include $tpl;
    $html = trim(ob_get_clean());
    return $html === '' ? '' : $html . "\n";
}

/** $content: pre-rendered body HTML (used for plugin detail pages); otherwise the page's blocks are rendered. */
function render_page(array $page, int $status = 200, ?string $content = null): void
{
    http_response_code($status);
    $GLOBALS['bench_status'] = $status;
    $site = site();
    if ($content === null) {
        $content = '';
        foreach (($page['blocks'] ?? []) as $i => $block) {
            $content .= render_block($block, $i, $page);
        }
    }
    $nav = nav_items();
    $title = ($page['slug'] ?? '') === 'home'
        ? ($page['seo_title'] ?: $site['business_name'] . ($site['tagline'] ? ' | ' . $site['tagline'] : ''))
        : ($page['seo_title'] ?: $page['title'] . ' | ' . $site['business_name']);
    $description = $page['meta_description'] ?: $site['default_description'];
    $is_admin = is_admin();

    include theme_file('layout.php');
}

/**
 * CSS custom properties from the colour settings, for themes to use.
 * Colours are nudged lighter/darker where needed so text on and in them keeps
 * WCAG AAA contrast (7:1), whatever the site owner picks. See bench_accessible_colours().
 */
function theme_style_vars(): string
{
    $site = site();
    $c = bench_accessible_colours($site['brand_color'] ?? '', $site['accent_color'] ?? '');
    $vars = [];
    foreach (['brand' => '--brand', 'brand_deep' => '--brand-deep', 'on_dark' => '--on-dark', 'on_dark_muted' => '--on-dark-muted', 'accent' => '--accent', 'accent_ink' => '--accent-ink', 'accent_on_dark' => '--accent-on-dark', 'accent_stripe' => '--accent-stripe'] as $k => $var) {
        if (!empty($c[$k])) {
            $vars[] = "$var:{$c[$k]}";
        }
    }
    return $vars ? '<style>html:root{' . implode(';', $vars) . '}</style>' : '';
}

/** WCAG contrast ratio between two hex colours (1–21). */
function color_contrast(string $a, string $b): float
{
    $la = color_luminance($a);
    $lb = color_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

function color_to_hsl(string $hex): array
{
    [$r, $g, $b] = array_map(fn($v) => $v / 255, color_rgb($hex));
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, $l];
    }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = $max === $r ? (($g - $b) / $d + ($g < $b ? 6 : 0)) : ($max === $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4);
    return [$h / 6, $s, $l];
}

function color_from_hsl(float $h, float $s, float $l): string
{
    if ($s == 0) {
        $r = $g = $b = $l;
    } else {
        $hue = function ($p, $q, $t) {
            if ($t < 0) $t += 1;
            if ($t > 1) $t -= 1;
            if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
            if ($t < 1 / 2) return $q;
            if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
            return $p;
        };
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        [$r, $g, $b] = [$hue($p, $q, $h + 1 / 3), $hue($p, $q, $h), $hue($p, $q, $h - 1 / 3)];
    }
    return vsprintf('#%02x%02x%02x', array_map(fn($v) => (int) round(max(0, min(1, $v)) * 255), [$r, $g, $b]));
}

/** Moves a colour's lightness (keeping its hue) until it reaches $ratio against every colour in $against. */
function color_adjust_for_contrast(string $hex, array $against, float $ratio, int $direction): ?string
{
    [$h, $s, $l] = color_to_hsl($hex);
    for ($i = 0; $i <= 200; $i++) {
        $c = color_from_hsl($h, $s, max(0, min(1, $l)));
        if (min(array_map(fn($x) => color_contrast($c, $x), $against)) >= $ratio) {
            return $c;
        }
        if (($direction < 0 && $l <= 0) || ($direction > 0 && $l >= 1)) {
            return null;
        }
        $l += 0.005 * $direction;
    }
    return null;
}

/**
 * Accessible versions of the brand and accent colours:
 *  - brand: readable as text on light backgrounds and behind white text (7:1)
 *  - accent: button colour, paired with black or white text at 7:1
 *  - accent_on_dark: accent used as text on brand-coloured backgrounds (7:1)
 * Returns the colours plus 'notes' describing any adjustment, for the admin.
 */
function bench_accessible_colours(string $brand, string $accent): array
{
    $out = ['notes' => []];
    $lightBgs = ['#ffffff', '#efe9dd']; // white and the darkest light background the themes use
    if ($b = css_color($brand)) {
        $b = strlen($b) === 4 ? '#' . $b[1] . $b[1] . $b[2] . $b[2] . $b[3] . $b[3] : strtolower($b);
        $safe = color_adjust_for_contrast($b, $lightBgs, 7.0, -1) ?? '#1a1a1a';
        $out['brand'] = $safe;
        $out['brand_deep'] = color_mix($safe, 0.62);
        // Secondary and body text on brand-coloured backgrounds: a pale tint of the brand
        // that still reaches 7:1 (with a little margin for decorative stripes and glows)
        [$h, $sat] = color_to_hsl($safe);
        $tint = color_from_hsl($h, min($sat, 0.35), 0.8);
        $out['on_dark_muted'] = color_adjust_for_contrast($tint, [$safe, $out['brand_deep']], 7.4, 1) ?? '#ffffff';
        $out['on_dark'] = color_adjust_for_contrast(color_from_hsl($h, min($sat, 0.25), 0.92), [$safe, $out['brand_deep']], 9.0, 1) ?? '#ffffff';
        if ($safe !== $b) {
            $out['notes']['brand_color'] = "Shown as $safe — your colour was too light for text to reach the 7:1 contrast WCAG AAA needs.";
        }
    }
    if ($a = css_color($accent)) {
        $a = strlen($a) === 4 ? '#' . $a[1] . $a[1] . $a[2] . $a[2] . $a[3] . $a[3] : strtolower($a);
        $withBlack = color_contrast($a, '#000000') >= 7 ? $a : color_adjust_for_contrast($a, ['#000000'], 7.0, 1);
        $withWhite = color_contrast($a, '#ffffff') >= 7 ? $a : color_adjust_for_contrast($a, ['#ffffff'], 7.0, -1);
        // pick whichever needs the smaller change in lightness
        $dist = fn($c) => $c ? abs(color_to_hsl($c)[2] - color_to_hsl($a)[2]) : 9;
        [$btn, $ink] = $dist($withBlack) <= $dist($withWhite) ? [$withBlack, '#000000'] : [$withWhite, '#ffffff'];
        $out['accent'] = $btn;
        $out['accent_ink'] = $ink;
        // decorative stripes over the accent move away from the text colour, never towards it
        $out['accent_stripe'] = $ink === '#000000' ? 'rgba(255,255,255,.14)' : 'rgba(0,0,0,.08)';
        if ($btn !== $a) {
            $out['notes']['accent_color'] = "Buttons use $btn with " . ($ink === '#000000' ? 'black' : 'white') . ' text, the nearest shade that reaches 7:1 contrast (WCAG AAA).';
        }
        $darkBg = $out['brand'] ?? null;
        if ($darkBg) {
            $out['accent_on_dark'] = color_adjust_for_contrast($a, [$darkBg, $out['brand_deep']], 7.0, color_luminance($darkBg) < 0.18 ? 1 : -1) ?? '#ffffff';
        } else {
            $out['accent_on_dark'] = '';
        }
    }
    return $out;
}

function color_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return array_map('hexdec', str_split($hex, 2));
}

/** Multiplies a colour towards black (factor < 1). */
function color_mix(string $hex, float $factor): string
{
    return vsprintf('#%02x%02x%02x', array_map(fn($v) => (int) round($v * $factor), color_rgb($hex)));
}

function color_luminance(string $hex): float
{
    [$r, $g, $b] = array_map(function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, color_rgb($hex));
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/** JSON-LD for local SEO (LocalBusiness). */
function local_business_schema(): string
{
    $s = site();
    $data = array_filter([
        '@context'  => 'https://schema.org',
        '@type'     => $s['schema_type'] ?: 'LocalBusiness',
        'name'      => $s['business_name'],
        'telephone' => $s['phone'],
        'email'     => $s['email'],
        'address'   => $s['address'] ? ['@type' => 'PostalAddress', 'streetAddress' => $s['address']] : null,
        'url'       => (isset($_SERVER['HTTP_HOST']) ? '//' . $_SERVER['HTTP_HOST'] : '') . url(),
        'openingHours' => $s['hours'] ?: null,
    ]);
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
}

/* ------------------------------------------------------------------ *
 * Sessions + auth (shared with admin)
 * ------------------------------------------------------------------ */

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Only accept session IDs this server created, and only from cookies (stops session fixation)
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');
    session_name((string) cfg('session_name', 'bench_admin'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function is_admin(): bool
{
    // Only check if a session cookie exists, so public pages don't start sessions needlessly
    if (session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE[cfg('session_name', 'bench_admin')])) {
        return false;
    }
    session_boot();
    return !empty($_SESSION['bench_user']);
}

/* Block section helpers */
if (!function_exists('section_open')) {
    function section_open(string $type, array $b, string $anchor, string $extra = ''): string
    {
        $style = in_array($b['style'] ?? 'default', ['alt', 'dark'], true) ? ' is-' . $b['style'] : '';
        $id = $anchor !== '' ? ' id="' . e($anchor) . '"' : '';
        return '<section class="block block--' . e($type) . $style . ($extra ? ' ' . e($extra) : '') . '"' . $id . '>';
    }
    /** Item headings are h3 under a section heading, or h2 when the section has none (keeps heading order, 1.3.1). */
    function item_heading_tag(array $b): string
    {
        return !empty($b['heading']) ? 'h3' : 'h2';
    }
    function section_head(array $b, string $introKey = 'intro'): string
    {
        $h = '';
        if (!empty($b['heading'])) {
            $h .= '<h2 class="section-title">' . e($b['heading']) . '</h2>';
        }
        if (!empty($b[$introKey])) {
            $h .= '<p class="section-intro">' . nl2br(e($b[$introKey])) . '</p>';
        }
        return $h ? '<header class="section-head">' . $h . '</header>' : '';
    }
}

/* ------------------------------------------------------------------ *
 * Theme hooks — call these from a theme's layout.php
 * ------------------------------------------------------------------ */

/** Everything a theme needs in <head> besides its own stylesheet. */
function bench_head(string $title, string $description): string
{
    $site = site();
    $out = '<meta charset="utf-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
        . '<title>' . e($title) . '</title>' . "\n"
        . bench_hreflang_links();
    if ($description !== '') {
        $out .= '<meta name="description" content="' . e($description) . '">' . "\n"
            . '<meta property="og:description" content="' . e($description) . '">' . "\n";
    }
    $out .= '<meta property="og:title" content="' . e($title) . '">' . "\n"
        . '<meta property="og:type" content="website">' . "\n"
        . (bench_is_bilingual() ? '<meta property="og:locale" content="' . e(bench_languages()[bench_lang()]['locale'] ?? '') . '">' . "\n" : '')
        . theme_style_vars() . "\n"
        . local_business_schema() . "\n";
    $out .= do_hook('head');
    if (!empty($site['head_code'])) {
        $out .= $site['head_code'] . "\n";
    }
    return $out;
}

/** Scripts + the admin toolbar (only for logged-in editors). Call before </body>. */
function bench_footer(array $page): string
{
    $js = BENCH_ROOT . '/assets/bench.js';
    $out = bench_js_strings();
    $out .= '<script src="' . e(base_path()) . '/assets/bench.js?v=' . (is_file($js) ? filemtime($js) : 1) . '" defer></script>';
    $out .= do_hook('footer', $page);
    if (is_admin()) {
        $edit = $page['edit_url'] ?? base_path() . '/admin/?view=edit&page=' . rawurlencode($page['slug'] ?? 'home');
        $draft = empty($page['published']) && ($page['slug'] ?? '') !== '404' ? '<span class="bench-bar__draft">Draft — only you can see this</span>' : '';
        $out .= '<div class="bench-bar" style="position:fixed;left:12px;bottom:12px;z-index:9999;display:flex;gap:8px;align-items:center;font:600 13px/1 system-ui,sans-serif;background:#111;color:#fff;padding:8px 10px;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.25)">'
            . $draft
            . '<a style="color:#fff;background:#2f6bff;padding:7px 10px;border-radius:7px;text-decoration:none" href="' . e($edit) . '">✎ Edit ' . (isset($page['edit_url']) ? 'this item' : 'page') . '</a>'
            . '<a style="color:#ddd;text-decoration:none;padding:7px 4px" href="' . e(base_path()) . '/admin/">Dashboard</a></div>';
    }
    return $out;
}

// Load enabled plugins last, so they can use every core function.
plugins_boot();
