<?php
/**
 * Bench CMS — plugin system
 *
 * A plugin is a folder in /plugins with:
 *   plugin.json   { "name", "description", "version", "author" }
 *   plugin.php    runs on every request while the plugin is enabled, and registers things:
 *
 *   register_block('type', [...])        a new section type for the page editor
 *   register_collection('id', [...])     a list of items (cars, properties, menu items…) with
 *                                        its own admin screen and optional detail pages
 *   register_settings('Group', [fields]) a new tab in Settings
 *   add_hook('head' | 'footer', fn)      output extra HTML in every page
 *
 * Plugins are switched on and off in Admin → Plugins.
 */

define('BENCH_PLUGINS', BENCH_ROOT . '/plugins');

$GLOBALS['bench_registry'] = [
    'blocks'      => [],
    'collections' => [],
    'settings'    => [],
    'hooks'       => [],
    'loaded'      => [],
];

/** Plugin currently being loaded (so registrations know where their files live). */
$GLOBALS['bench_loading_plugin'] = null;

function plugins_available(): array
{
    $out = [];
    foreach (glob(BENCH_PLUGINS . '/*/plugin.json') ?: [] as $f) {
        $id = basename(dirname($f));
        if (!preg_match('/^[a-z0-9_\-]+$/', $id)) {
            continue;
        }
        $meta = json_decode((string) file_get_contents($f), true) ?: [];
        $out[$id] = $meta + ['id' => $id, 'name' => $id, 'description' => '', 'version' => '', 'author' => ''];
        $out[$id]['dir'] = dirname($f);
    }
    ksort($out);
    return $out;
}

function plugins_enabled(): array
{
    $enabled = store_read('plugins', ['enabled' => []])['enabled'] ?? [];
    return array_values(array_intersect($enabled, array_keys(plugins_available())));
}

function plugins_boot(): void
{
    $available = plugins_available();
    foreach (plugins_enabled() as $id) {
        $file = $available[$id]['dir'] . '/plugin.php';
        if (!is_file($file)) {
            continue;
        }
        $GLOBALS['bench_loading_plugin'] = $available[$id];
        (static function (string $__file, array $plugin) {
            require $__file;
        })($file, $available[$id]);
        $GLOBALS['bench_registry']['loaded'][] = $id;
        $GLOBALS['bench_registry']['plugin_dirs'][] = $available[$id]['dir']; // for lang/<code>.php
    }
    $GLOBALS['bench_loading_plugin'] = null;
}

function current_plugin(): array
{
    $p = $GLOBALS['bench_loading_plugin'];
    if (!$p) {
        throw new LogicException('register_* functions must be called from a plugin.php file');
    }
    return $p;
}

/* ---------- Registration API ---------- */

/**
 * $def: label, description, fields — same format as core blocks.
 * Template: plugins/<id>/blocks/<type>.php (a theme can override it in themes/<theme>/blocks/<type>.php)
 */
function register_block(string $type, array $def): void
{
    $p = current_plugin();
    $def['_template'] = $def['template'] ?? $p['dir'] . '/blocks/' . $type . '.php';
    $def['_plugin'] = $p['id'];
    $GLOBALS['bench_registry']['blocks'][$type] = $def;
}

/**
 * $def keys:
 *   label, singular      "Cars", "car"
 *   icon                 admin menu icon (from core/icons.php)
 *   fields               field list (same format as blocks)
 *   title                format string for an item's title, e.g. "{year} {make} {model}" (or a callable)
 *   subtitle             format string or callable(item) shown under the title in the admin list
 *   image_field          field holding the thumbnail (image or gallery)
 *   route                URL prefix for detail pages, e.g. "cars" → /cars/<item-id>  (optional)
 *   detail_template      PHP template for the detail page (theme may override with
 *                        themes/<theme>/templates/<collection>-detail.php)
 *   sort                 [field, 'asc'|'desc'] default order
 *   is_public            callable(item): bool — hide items from the public site (drafts etc.)
 *   badge                callable(item): ?string — small status label in the admin list
 *   clean_ids            true: new items get readable ids from their title ("spring-offers",
 *                        then "spring-offers-2"); false (default) adds a short random code
 *   before_save          callable(array $data, ?array $existing): array — adjusts cleaned
 *                        input before it's saved (fill a date, a default); may throw BenchOpError
 *   help                 text shown above the list in the admin
 *   describe             callable(item): string — search engine description when the item's own
 *                        meta_description is blank (an item's seo_title field is used if it has one)
 *   a11y_check           true: show the page editor's Accessibility check beside the item form
 *   subroutes            ['feed' => callable(array $collection)] — extra addresses under the
 *                        route (/blog/feed) that the plugin answers itself (send headers, echo,
 *                        return). Item ids never take these names.
 */
function register_collection(string $id, array $def): void
{
    $p = current_plugin();
    $def += [
        'label' => ucfirst($id), 'singular' => $id, 'icon' => 'badge', 'fields' => [],
        'title' => '{title}', 'subtitle' => '', 'image_field' => '', 'route' => '',
        'detail_template' => '', 'sort' => ['updated', 'desc'], 'is_public' => null, 'badge' => null,
        'clean_ids' => false, 'before_save' => null, 'help' => '', 'subroutes' => [], 'describe' => null,
    ];
    $def['id'] = $id;
    $def['_plugin'] = $p['id'];
    $GLOBALS['bench_registry']['collections'][$id] = $def;
}

function register_settings(string $group, array $fields): void
{
    current_plugin();
    $GLOBALS['bench_registry']['settings'][$group] = array_merge($GLOBALS['bench_registry']['settings'][$group] ?? [], $fields);
}

function add_hook(string $name, callable $fn, int $priority = 10): void
{
    $GLOBALS['bench_registry']['hooks'][$name][$priority][] = $fn;
}

/** Runs output hooks and returns the joined HTML. */
function do_hook(string $name, ...$args): string
{
    $hooks = $GLOBALS['bench_registry']['hooks'][$name] ?? [];
    ksort($hooks);
    $out = '';
    foreach ($hooks as $fns) {
        foreach ($fns as $fn) {
            $out .= (string) $fn(...$args);
        }
    }
    return $out;
}

/** URL to a file inside a plugin folder, with cache-busting. Use: plugin_asset(__DIR__, 'assets/x.css') */
function plugin_asset(string $pluginDir, string $file): string
{
    $id = basename($pluginDir);
    $path = $pluginDir . '/' . $file;
    return base_path() . '/plugins/' . $id . '/' . $file . (is_file($path) ? '?v=' . filemtime($path) : '');
}

/* ---------- Collections ---------- */

function collections(): array
{
    return $GLOBALS['bench_registry']['collections'];
}

function collection(string $id): ?array
{
    return $GLOBALS['bench_registry']['collections'][$id] ?? null;
}

function collection_by_route(string $route): ?array
{
    foreach (collections() as $c) {
        if ($c['route'] !== '' && $c['route'] === $route) {
            return $c;
        }
    }
    return null;
}

function items_all(string $cid, bool $publicOnly = false): array
{
    $c = collection($cid);
    if (!$c) {
        return [];
    }
    $items = [];
    foreach (glob(BENCH_CONTENT . '/collections/' . $cid . '/*.php') ?: [] as $f) {
        $item = store_read('collections/' . $cid . '/' . basename($f, '.php'));
        if (!$item) {
            continue;
        }
        $item['id'] = basename($f, '.php');
        $item = bench_maybe_localise($item + schema_defaults($c['fields']));
        if ($publicOnly && is_callable($c['is_public']) && !($c['is_public'])($item)) {
            continue;
        }
        $items[] = $item;
    }
    [$field, $dir] = $c['sort'] + [1 => 'desc'];
    usort($items, function ($a, $b) use ($field, $dir) {
        $x = $a[$field] ?? '';
        $y = $b[$field] ?? '';
        $cmp = (is_numeric($x) && is_numeric($y)) ? ((float) $x <=> (float) $y) : strnatcasecmp((string) $x, (string) $y);
        return $dir === 'asc' ? $cmp : -$cmp;
    });
    return $items;
}

function item_get(string $cid, string $id): ?array
{
    $c = collection($cid);
    if (!$c || !preg_match('/^[a-z0-9\-]+$/', $id)) {
        return null;
    }
    $item = store_read('collections/' . $cid . '/' . $id);
    if (!$item) {
        return null;
    }
    $item['id'] = $id;
    return bench_maybe_localise($item + schema_defaults($c['fields']));
}

/** Fills a format string like "{year} {make} {model}" from an item. */
function item_format(string $format, array $item): string
{
    $s = preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($m) use ($item) {
        $v = $item[$m[1]] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }, $format);
    return trim(preg_replace('/\s+/', ' ', $s), " ·-|");
}

function item_title(array $c, array $item): string
{
    $t = is_callable($c['title']) ? (string) ($c['title'])($item) : item_format($c['title'], $item);
    return $t ?: t('Untitled') . ' ' . $c['singular'];
}

function item_subtitle(array $c, array $item): string
{
    if (is_callable($c['subtitle'])) {
        return (string) ($c['subtitle'])($item);
    }
    return $c['subtitle'] ? item_format($c['subtitle'], $item) : '';
}

function item_url(array $c, array $item): string
{
    return $c['route'] !== '' ? url($c['route'] . '/' . $item['id']) : '#';
}

/** First image of an item (works with image or gallery fields). */
function item_image(array $c, array $item): string
{
    $v = $item[$c['image_field']] ?? '';
    if (is_array($v)) {
        $v = $v[0] ?? '';
    }
    return is_string($v) ? $v : '';
}

/** Renders a collection item's detail page body. */
function render_item_detail(array $c, array $item): string
{
    $override = theme_file('templates/' . $c['id'] . '-detail.php');
    $tpl = is_file($override) ? $override : $c['detail_template'];
    if (!$tpl || !is_file($tpl)) {
        return '';
    }
    $site = site();
    $collection = $c;
    ob_start();
    include $tpl;
    return ob_get_clean();
}
