<?php
/**
 * Bench CMS — Claude connector endpoint (MCP over HTTP, JSON-RPC 2.0).
 * Add https://<site>/admin/mcp.php to Claude as a custom connector. See core/connector.php.
 */
require dirname(__DIR__) . '/core/bootstrap.php';
require BENCH_CORE . '/media.php';
require BENCH_CORE . '/content-ops.php';
require BENCH_CORE . '/connector.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function mcp_reply($body, int $status = 200): void
{
    http_response_code($status);
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function mcp_unauthorized(string $why): void
{
    header('WWW-Authenticate: Bearer resource_metadata="' . connector_oauth_url('protected-resource') . '", error="invalid_token", error_description="' . $why . '"');
    mcp_reply(['error' => 'unauthorized', 'error_description' => $why], 401);
}

// Requests from web pages are only accepted from this site itself or Claude (DNS-rebinding protection)
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $oh = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $self = strtolower((string) parse_url(connector_base(), PHP_URL_HOST));
    if ($oh !== $self && !preg_match('/(^|\.)claude\.(ai|com)$/', $oh)) {
        mcp_reply(['error' => 'Origin not allowed'], 403);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    mcp_reply(['error' => 'This is the Bench connector for Claude. Add this address to Claude as a custom connector.'], 405);
}

if (!connector_enabled()) {
    mcp_unauthorized('Claude access is turned off for this site (Admin → Connections)');
}

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $auth = (string) $v;
        }
    }
}
$who = preg_match('/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/i', trim($auth), $m) ? connector_check_token($m[1]) : null;
if (!$who) {
    mcp_unauthorized($auth === '' ? 'Sign-in required' : 'The access token is invalid or has expired');
}

$raw = file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024);
$msg = json_decode((string) $raw, true);
if (!is_array($msg)) {
    mcp_reply(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
}

$batch = $msg !== [] && array_keys($msg) === range(0, count($msg) - 1);
$replies = [];
foreach ($batch ? $msg : [$msg] as $one) {
    $r = mcp_handle(is_array($one) ? $one : [], $who);
    if ($r !== null) {
        $replies[] = $r;
    }
}
if (!$replies) {
    mcp_reply(null, 202); // only notifications
}
mcp_reply($batch ? $replies : $replies[0]);

/* ------------------------------------------------------------------ *
 * JSON-RPC
 * ------------------------------------------------------------------ */

function mcp_handle(array $m, array $who): ?array
{
    $id = $m['id'] ?? null;
    $method = (string) ($m['method'] ?? '');
    $params = is_array($m['params'] ?? null) ? $m['params'] : [];
    $isNotification = !array_key_exists('id', $m);
    if (($m['jsonrpc'] ?? '') !== '2.0' || $method === '') {
        return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32600, 'message' => 'Invalid request']];
    }
    if ($isNotification) {
        return null; // notifications/initialized, notifications/cancelled…
    }
    switch ($method) {
        case 'initialize':
            $asked = (string) ($params['protocolVersion'] ?? '');
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => in_array($asked, CONNECTOR_PROTOCOLS, true) ? $asked : CONNECTOR_PROTOCOLS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'bench', 'title' => (site()['business_name'] ?? 'Bench') . ' website', 'version' => BENCH_VERSION],
                'instructions' => mcp_instructions(),
            ]];
        case 'ping':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];
        case 'tools/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => array_values(array_map(function ($t) {
                unset($t['run']);
                return $t;
            }, mcp_tools()))]];
        case 'tools/call':
            $tools = mcp_tools();
            $name = (string) ($params['name'] ?? '');
            if (!isset($tools[$name])) {
                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => "Unknown tool: $name"]];
            }
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $out = ($tools[$name]['run'])($args, $who);
                $result = ['content' => [['type' => 'text', 'text' => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'isError' => false];
            } catch (BenchOpError $e) {
                $result = ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
            } catch (Throwable $e) {
                $result = ['content' => [['type' => 'text', 'text' => 'Something went wrong on the site: ' . $e->getMessage()]], 'isError' => true];
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        case 'resources/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]];
        case 'prompts/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]];
        default:
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: $method"]];
    }
}

function mcp_instructions(): string
{
    $s = site();
    return 'This connector edits the live website "' . ($s['business_name'] ?? '') . '" (' . connector_base() . "), built with Bench CMS.\n"
        . "- Changes go live straight away. Every change is backed up and listed in the site's Admin → Connections, where it can be undone; you can also undo with list_backups + restore_backup.\n"
        . "- Start with get_site. Before changing a page, call get_page and change only what was asked; keep the owner's wording elsewhere.\n"
        . "- A page is a list of sections, numbered from 0. Use update_section to change one section's fields; get_schema('sections') lists section types and their fields.\n"
        . "- Vehicles and other listings are collection items: list_items, get_item, create_item, update_item. get_schema(<collection>) shows their fields and allowed values.\n"
        . "- Images: upload_image_from_url gives a path (uploads/…) to put in image or gallery fields. Write useful alt text where there's an alt field.\n"
        . "- Text fields of type richtext take simple HTML (p, strong, em, a, ul, ol, li, h2, h3).\n"
        . "- datetime fields take the site's local time as YYYY-MM-DDTHH:MM. multiselect fields take a list of their options; tags fields take a list of short labels (reuse existing_values where they fit).\n"
        . "- Blog posts (when the Blog plugin is on) are items in the \"posts\" collection. A post with a future publish date stays hidden until then (scheduled).\n"
        . "- Before deleting anything, or making large changes, confirm with the person. Tell them what you changed and the page address."
        . mcp_language_instructions();
}

/** How translations work, when the site has a second language. */
function mcp_language_instructions(): string
{
    if (!bench_is_bilingual()) {
        return '';
    }
    $all = bench_languages();
    $main = $all[bench_primary_lang()]['english_name'];
    $others = array_map(fn($c) => $all[$c]['english_name'] . ' ("' . $c . '", at ' . connector_base() . '/' . $c . '/)', bench_translation_langs());
    $code = bench_translation_langs()[0];
    return "\n- This site is bilingual. Content is written in $main; translations into " . implode(', ', $others) . ' sit in a "_t" object on the same page, section, list item, collection item or settings: '
        . '{"heading": "Our services", "_t": {"' . $code . '": {"heading": "Ein gwasanaethau"}}}. Only fields marked translatable in get_schema can be translated; anything left untranslated shows in ' . $main . ".\n"
        . '- To change a translation, send just the "_t" part (e.g. update_section fields {"_t": {"' . $code . '": {"heading": "…"}}}); other translations are kept. An empty string removes one. '
        . 'A page\'s address in the other language is "_t".' . $code . '.slug. Lists (items) are replaced whole, so include each item\'s "_t" when you send a list.' . "\n"
        . '- Only translate when asked. Tell the person that machine translations should be checked by a fluent speaker before they rely on them.';
}

/** Applies $changes over $base, merging "_t" translations per language instead of replacing them. */
function mcp_merge(array $base, array $changes): array
{
    $out = array_merge($base, $changes);
    if (isset($changes['_t']) && is_array($changes['_t'])) {
        $out['_t'] = is_array($base['_t'] ?? null) ? $base['_t'] : [];
        foreach ($changes['_t'] as $lang => $vals) {
            if (is_string($lang) && is_array($vals)) {
                $out['_t'][$lang] = array_merge(is_array($out['_t'][$lang] ?? null) ? $out['_t'][$lang] : [], $vals);
            }
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */

/** Field definitions in a form Claude can follow. */
function mcp_fields(array $fields, bool $settings = false): array
{
    $out = [];
    foreach (schema_for_client($fields) as $f) {
        if ($f['type'] === 'heading') {
            continue;
        }
        $d = ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type']];
        if (isset($f['options'])) {
            $d['options'] = array_values(array_map('strval', $f['options']));
        }
        if (isset($f['labels'])) {
            $d['option_labels'] = $f['labels'];
        }
        if (!empty($f['suggest'])) {
            $d['existing_values'] = array_slice($f['suggest'], 0, 200);
        }
        if (!empty($f['help'])) {
            $d['help'] = $f['help'];
        }
        if (isset($f['fields'])) {
            $d['item_fields'] = mcp_fields($f['fields']);
        }
        if (bench_is_bilingual() && field_translatable($f)) {
            $d['translatable'] = true;
        }
        if ($settings && $f['type'] === 'code' && !cfg('connector_allow_code', false)) {
            $d['read_only'] = 'Can only be changed in the admin, because it can run scripts on the site.';
        }
        $out[] = $d;
    }
    return $out;
}

/** Keys in $input that aren't fields, so Claude hears about typos instead of them vanishing. */
function mcp_unknown(array $input, array $fields): array
{
    $known = array_column(array_filter($fields, fn($f) => $f['type'] !== 'heading'), 'key');
    $known[] = '_t'; // translations (bilingual sites)
    return array_values(array_diff(array_keys($input), $known));
}

function mcp_page(string $slug): array
{
    $p = page_get($slug);
    if (!$p) {
        throw new BenchOpError("There's no page with the address \"$slug\". get_site lists the pages.", 404);
    }
    return $p;
}

function mcp_page_view(array $p): array
{
    $schema = schema_blocks();
    $fields = [];
    foreach (schema_page() as $f) {
        $fields[$f['key']] = $f['key'] === 'slug' ? $p['slug'] : ($p[$f['key']] ?? null);
    }
    if (!empty($p['_t'])) {
        $fields['_t'] = $p['_t'];
    }
    $sections = [];
    foreach ($p['blocks'] ?? [] as $i => $b) {
        $sections[] = ['index' => $i, 'type' => $b['type'], 'label' => $schema[$b['type']]['label'] ?? $b['type']] + $b;
    }
    return ['slug' => $p['slug'], 'url' => url($p['slug']), 'fields' => $fields, 'sections' => $sections,
        'updated' => $p['updated'] ?? '', 'updated_by' => $p['updated_by'] ?? ''];
}

/** Saves a page built from its current state plus a change, logs it, and returns the new view. */
function mcp_save_page(array $who, string $tool, array $page, string $original, string $summary): array
{
    $page += ['slug' => $original];
    $existed = $original !== '' && page_get($original);
    $r = op_save_page($page, $original, $who['user'] . ' (via Claude)');
    $undo = $existed ? ['restore' => op_latest_backup('pages/' . $original)] : ['delete' => 'pages/' . $r['slug']];
    connector_log_add($who, $tool, $summary . ' — ' . ($page['title'] ?? $r['slug']), array_filter($undo), $r['url']);
    return ['saved' => true, 'url' => $r['url'], 'photos_moved_to_trash' => $r['trashed'], 'page' => mcp_page_view(mcp_page($r['slug']))];
}

function mcp_section_index(array $p, $index): int
{
    $n = count($p['blocks'] ?? []);
    if (!is_int($index) && !(is_string($index) && ctype_digit($index))) {
        throw new BenchOpError('index must be a section number (from get_page).');
    }
    $i = (int) $index;
    if ($i < 0 || $i >= $n) {
        throw new BenchOpError("This page has sections 0 to " . ($n - 1) . "; there's no section $i.", 404);
    }
    return $i;
}

function mcp_collection(string $id): array
{
    $c = collection($id);
    if (!$c) {
        throw new BenchOpError("There's no collection \"$id\" (or its plugin is turned off). get_site lists them.", 404);
    }
    return $c;
}

function mcp_item_view(array $c, array $item): array
{
    $s = item_summary($c, $item);
    return ['id' => $item['id'], 'title' => $s['title'], 'subtitle' => $s['subtitle'], 'status' => $s['badge']['label'] ?? null,
        'url' => $s['url'] ? connector_base() . $s['url'] : '', 'fields' => array_diff_key($item, ['id' => 1])];
}

/* ------------------------------------------------------------------ *
 * Tools
 * ------------------------------------------------------------------ */

function mcp_obj(array $props = [], array $required = []): array
{
    $s = ['type' => 'object', 'properties' => $props ?: new stdClass()];
    if ($required) {
        $s['required'] = $required;
    }
    return $s;
}

function mcp_tools(): array
{
    $str = fn(string $d) => ['type' => 'string', 'description' => $d];
    $int = fn(string $d) => ['type' => 'integer', 'description' => $d];
    $objAny = fn(string $d) => ['type' => 'object', 'description' => $d, 'additionalProperties' => true];
    $read = ['readOnlyHint' => true, 'openWorldHint' => false];
    $write = ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];
    $delete = ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false];

    $t = [];
    $add = function (string $name, string $title, string $desc, array $schema, array $ann, callable $run) use (&$t) {
        $t[$name] = ['name' => $name, 'title' => $title, 'description' => $desc, 'inputSchema' => $schema, 'annotations' => $ann + ['title' => $title], 'run' => $run];
    };

    /* ---------- Reading ---------- */

    $add('get_site', 'Get site overview',
        'Overview of the website: name, address, theme, every page (slug, title, published, in menu) and the collections (vehicles etc.) with item counts. Start here.',
        mcp_obj(), $read, function () {
            $s = site();
            return [
                'name' => $s['business_name'], 'url' => connector_base() . '/', 'theme' => $s['theme'], 'bench_version' => BENCH_VERSION,
                'pages' => array_map(fn($p) => ['slug' => $p['slug'], 'title' => $p['title'] ?? '', 'published' => !empty($p['published']),
                    'in_menu' => !empty($p['in_nav']), 'url' => url($p['slug'])], pages_all()),
                'collections' => array_values(array_map(fn($c) => ['id' => $c['id'], 'label' => $c['label'], 'singular' => $c['singular'],
                    'items' => count(items_all($c['id'])), 'list_page' => $c['route'] !== '' ? url($c['route']) : ''], collections())),
                'section_types' => array_keys(schema_blocks()),
                'languages' => bench_site_languages(),
            ];
        });

    $add('get_schema', 'Get field definitions',
        'Field definitions, so you know what can be set and the allowed values. what = "sections" (all page section types), "page" (page settings), "settings" (site settings) or a collection id such as "vans".',
        mcp_obj(['what' => $str('"sections", "page", "settings" or a collection id')], ['what']), $read, function ($a) {
            $what = (string) ($a['what'] ?? '');
            if ($what === 'sections') {
                $out = [];
                foreach (schema_blocks() as $type => $def) {
                    $out[] = ['type' => $type, 'label' => $def['label'], 'description' => $def['description'] ?? '',
                        'fields' => mcp_fields(array_merge($def['fields'], schema_block_common()))];
                }
                return ['section_types' => $out, 'note' => 'Every section can also have "hidden": true to keep it off the page.'];
            }
            if ($what === 'page') {
                return ['fields' => mcp_fields(schema_page())];
            }
            if ($what === 'settings') {
                $out = [];
                foreach (schema_settings() as $group => $fields) {
                    $out[$group] = mcp_fields($fields, true);
                }
                return ['groups' => $out];
            }
            $c = mcp_collection($what);
            return ['collection' => $c['id'], 'singular' => $c['singular'], 'fields' => mcp_fields($c['fields'])];
        });

    $add('get_page', 'Get a page',
        'A page\'s settings and its sections, in order, with every field. Sections are numbered from 0 (index).',
        mcp_obj(['slug' => $str('Page address, e.g. "home" or "services"')], ['slug']), $read,
        fn($a) => mcp_page_view(mcp_page((string) ($a['slug'] ?? ''))));

    $add('list_items', 'List collection items',
        'Items in a collection (e.g. vehicles), newest first, with title, price/mileage summary and status. Optional search matches the title and summary.',
        mcp_obj(['collection' => $str('Collection id from get_site, e.g. "vans"'), 'search' => $str('Optional words to match')], ['collection']), $read,
        function ($a) {
            $c = mcp_collection((string) ($a['collection'] ?? ''));
            $q = mb_strtolower(trim((string) ($a['search'] ?? '')));
            $out = [];
            foreach (items_all($c['id']) as $item) {
                $s = item_summary($c, $item);
                if ($q !== '' && mb_strpos(mb_strtolower($s['title'] . ' ' . $s['subtitle'] . ' ' . $item['id']), $q) === false) {
                    continue;
                }
                $out[] = ['id' => $item['id'], 'title' => $s['title'], 'subtitle' => $s['subtitle'], 'status' => $s['badge']['label'] ?? null,
                    'url' => $s['url'] ? connector_base() . $s['url'] : '', 'updated' => $item['updated'] ?? ''];
            }
            return ['collection' => $c['id'], 'count' => count($out), 'items' => $out];
        });

    $add('get_item', 'Get a collection item', 'Every field of one item (e.g. a vehicle).',
        mcp_obj(['collection' => $str('Collection id'), 'id' => $str('Item id from list_items')], ['collection', 'id']), $read,
        function ($a) {
            $c = mcp_collection((string) ($a['collection'] ?? ''));
            $item = item_get($c['id'], (string) ($a['id'] ?? ''));
            if (!$item) {
                throw new BenchOpError('No ' . $c['singular'] . ' with that id. list_items shows the ids.', 404);
            }
            return mcp_item_view($c, $item + ['id' => (string) $a['id']] + schema_defaults($c['fields']));
        });

    $add('get_settings', 'Get site settings', 'The site settings: business details, phone, hours, announcement bar, colours, theme, contact form and SEO.',
        mcp_obj(), $read, fn() => ['settings' => array_diff_key(site(), array_flip(['slug']))]);

    $add('list_media', 'List uploaded images', 'Images already on the site, newest first, with where each is used. Use a path in an image or gallery field.',
        mcp_obj(['search' => $str('Optional part of the file name'), 'limit' => $int('How many (default 30, max 200)')]), $read,
        function ($a) {
            $used = media_usage();
            $q = strtolower(trim((string) ($a['search'] ?? '')));
            $out = [];
            foreach (media_files() as $f) {
                if ($q !== '' && strpos(strtolower($f['name']), $q) === false) {
                    continue;
                }
                $out[] = ['path' => $f['path'], 'url' => connector_base() . $f['url'], 'uploaded' => date('c', $f['time']), 'used_in' => $used[$f['path']] ?? []];
                if (count($out) >= min(200, max(1, (int) ($a['limit'] ?? 30)))) {
                    break;
                }
            }
            return ['images' => $out];
        });

    $add('list_backups', 'List backups', 'Earlier versions of pages, items and settings, newest first. Pass one to restore_backup to undo a change.',
        mcp_obj(['name' => $str('Optional: only backups of this, e.g. "pages/home", "site" or "collections/vans/<id>"')]), $read,
        function ($a) {
            $want = str_replace('/', '__', trim((string) ($a['name'] ?? '')));
            $out = [];
            foreach (glob(BENCH_CONTENT . '/_backups/*.php') ?: [] as $f) {
                $file = basename($f);
                [$key] = explode('@', basename($f, '.php'), 2);
                if (strpos($key, '_') === 0 || ($want !== '' && $key !== $want)) {
                    continue;
                }
                $name = str_replace('__', '/', $key);
                $out[] = ['file' => $file, 'of' => $name, 'label' => media_label($name, store_read_backup($f)), 'time' => date('c', filemtime($f))];
            }
            usort($out, fn($x, $y) => strcmp($y['time'] . $y['file'], $x['time'] . $x['file']));
            return ['backups' => array_slice($out, 0, 50)];
        });

    $add('get_recent_changes', 'Get recent changes by Claude', 'The changes made through this connector, newest first (also shown in Admin → Connections).',
        mcp_obj(['limit' => $int('How many (default 20)')]), $read,
        fn($a) => ['changes' => array_map(fn($e) => array_diff_key($e, ['undo' => 1]), array_slice(connector_log(), 0, min(200, max(1, (int) ($a['limit'] ?? 20)))))]);

    /* ---------- Pages ---------- */

    $add('update_page', 'Update page settings',
        'Change a page\'s settings: title, slug (address), published, in_nav (show in menu), nav_label, order, seo_title, meta_description. Optionally replace ALL its sections by passing "sections" (a full list, as from get_page) — usually use update_section instead.',
        mcp_obj(['slug' => $str('Current page address'), 'fields' => $objAny('Page settings to change, e.g. {"title": "Our vans"}'),
            'sections' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Optional: the complete new list of sections']], ['slug']),
        $write, function ($a, $who) {
            $slug = (string) ($a['slug'] ?? '');
            $p = mcp_page($slug);
            $fields = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            $unknown = mcp_unknown($fields, schema_page());
            if ($unknown) {
                throw new BenchOpError('Unknown page settings: ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', array_column(schema_page(), 'key')));
            }
            $page = mcp_merge($p, $fields);
            if (isset($a['sections'])) {
                if (!is_array($a['sections'])) {
                    throw new BenchOpError('sections must be a list.');
                }
                $page['blocks'] = array_map(fn($b) => is_array($b) ? array_diff_key($b, ['index' => 1, 'label' => 1]) : [], $a['sections']);
            }
            return mcp_save_page($who, 'update_page', $page, $slug, 'Updated page' . ($fields ? ' (' . implode(', ', array_keys($fields)) . ')' : '') . (isset($a['sections']) ? ' and its sections' : ''));
        });

    $add('update_section', 'Update a page section',
        'Change fields of one section of a page (others are left as they are). Lists (e.g. "items", "badges") are replaced as a whole, so send the full list. Set hidden to true/false to hide or show the section.',
        mcp_obj(['slug' => $str('Page address'), 'index' => $int('Section number from get_page'),
            'fields' => $objAny('Fields to change, e.g. {"heading": "New heading", "body": "<p>…</p>"}'), 'hidden' => ['type' => 'boolean']], ['slug', 'index']),
        $write, function ($a, $who) {
            $slug = (string) ($a['slug'] ?? '');
            $p = mcp_page($slug);
            $i = mcp_section_index($p, $a['index'] ?? null);
            $b = $p['blocks'][$i];
            $def = schema_blocks()[$b['type']] ?? ['fields' => [], 'label' => $b['type']];
            $fields = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            unset($fields['type'], $fields['index'], $fields['label']);
            $unknown = array_diff(mcp_unknown($fields, array_merge($def['fields'], schema_block_common())), ['hidden']);
            if ($unknown) {
                throw new BenchOpError('A ' . $def['label'] . ' section has no field(s): ' . implode(', ', $unknown) . '. get_schema("sections") lists the fields.');
            }
            $p['blocks'][$i] = mcp_merge($b, $fields);
            if (array_key_exists('hidden', $a)) {
                $p['blocks'][$i]['hidden'] = (bool) $a['hidden'];
            }
            return mcp_save_page($who, 'update_section', $p, $slug, 'Changed section ' . ($i + 1) . ' (' . $def['label'] . ')');
        });

    $add('add_section', 'Add a section to a page',
        'Add a new section of a given type, at a position (default: the end). get_schema("sections") lists the types and fields.',
        mcp_obj(['slug' => $str('Page address'), 'type' => $str('Section type, e.g. "text", "faq", "cta"'),
            'fields' => $objAny('The section\'s fields'), 'position' => $int('Where to insert (0 = top). Default: end of the page')], ['slug', 'type']),
        $write, function ($a, $who) {
            $slug = (string) ($a['slug'] ?? '');
            $p = mcp_page($slug);
            $type = (string) ($a['type'] ?? '');
            $schema = schema_blocks();
            if (!isset($schema[$type])) {
                throw new BenchOpError("Unknown section type \"$type\". Types: " . implode(', ', array_keys($schema)));
            }
            $fields = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            $unknown = array_diff(mcp_unknown($fields, array_merge($schema[$type]['fields'], schema_block_common())), ['hidden']);
            if ($unknown) {
                throw new BenchOpError('A ' . $schema[$type]['label'] . ' section has no field(s): ' . implode(', ', $unknown));
            }
            $block = ['type' => $type] + $fields + schema_defaults($schema[$type]['fields']) + schema_defaults(schema_block_common());
            $blocks = $p['blocks'] ?? [];
            $pos = isset($a['position']) ? max(0, min(count($blocks), (int) $a['position'])) : count($blocks);
            array_splice($blocks, $pos, 0, [$block]);
            $p['blocks'] = $blocks;
            return mcp_save_page($who, 'add_section', $p, $slug, 'Added a ' . $schema[$type]['label'] . ' section as section ' . ($pos + 1));
        });

    $add('remove_section', 'Remove a section from a page', 'Delete one section of a page. Confirm with the person first. (To keep it but hide it, use update_section with hidden: true.)',
        mcp_obj(['slug' => $str('Page address'), 'index' => $int('Section number from get_page')], ['slug', 'index']),
        $delete, function ($a, $who) {
            $slug = (string) ($a['slug'] ?? '');
            $p = mcp_page($slug);
            $i = mcp_section_index($p, $a['index'] ?? null);
            $label = schema_blocks()[$p['blocks'][$i]['type']]['label'] ?? $p['blocks'][$i]['type'];
            array_splice($p['blocks'], $i, 1);
            return mcp_save_page($who, 'remove_section', $p, $slug, 'Removed section ' . ($i + 1) . ' (' . $label . ')');
        });

    $add('move_section', 'Move a section', 'Move a section to a new position on its page.',
        mcp_obj(['slug' => $str('Page address'), 'index' => $int('Section number now'), 'to' => $int('New position (0 = top)')], ['slug', 'index', 'to']),
        $write, function ($a, $who) {
            $slug = (string) ($a['slug'] ?? '');
            $p = mcp_page($slug);
            $i = mcp_section_index($p, $a['index'] ?? null);
            $to = max(0, min(count($p['blocks']) - 1, (int) ($a['to'] ?? 0)));
            $b = array_splice($p['blocks'], $i, 1);
            array_splice($p['blocks'], $to, 0, $b);
            return mcp_save_page($who, 'move_section', $p, $slug, 'Moved section ' . ($i + 1) . ' to position ' . ($to + 1));
        });

    $add('create_page', 'Create a page',
        'Make a new page. It\'s published and in the menu unless fields say otherwise ({"published": false} keeps it as a draft). Sections are optional; without them the page gets a heading and a text section.',
        mcp_obj(['title' => $str('Page title'), 'fields' => $objAny('Optional page settings (slug, published, in_nav, nav_label, order, seo_title, meta_description)'),
            'sections' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Optional list of sections, each {"type": …, fields…}']], ['title']),
        $write, function ($a, $who) {
            $title = trim((string) ($a['title'] ?? ''));
            if ($title === '') {
                throw new BenchOpError('Please give the page a title.');
            }
            $fields = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            $unknown = mcp_unknown($fields, schema_page());
            if ($unknown) {
                throw new BenchOpError('Unknown page settings: ' . implode(', ', $unknown));
            }
            $page = array_merge(schema_defaults(schema_page()), ['order' => (string) (count(pages_all()) * 10 + 10)], $fields, ['title' => $title]);
            $page['slug'] = ($fields['slug'] ?? '') !== '' ? slugify((string) $fields['slug']) : op_free_slug($title);
            $page['blocks'] = is_array($a['sections'] ?? null) ? $a['sections'] : [
                ['type' => 'hero', 'size' => 'small', 'heading' => $title],
                ['type' => 'text', 'heading' => '', 'body' => ''],
            ];
            return mcp_save_page($who, 'create_page', $page, '', 'Created page');
        });

    $add('delete_page', 'Delete a page', 'Delete a page (not the home page). Confirm with the person first. It can be restored from backups.',
        mcp_obj(['slug' => $str('Page address')], ['slug']), $delete, function ($a, $who) {
            $slug = (string) ($a['slug'] ?? '');
            $p = mcp_page($slug);
            $r = op_delete_page($slug);
            connector_log_add($who, 'delete_page', 'Deleted page — ' . ($p['title'] ?? $slug), array_filter(['restore' => op_latest_backup('pages/' . $slug)]));
            return ['deleted' => true, 'photos_moved_to_trash' => $r['trashed']];
        });

    /* ---------- Collection items ---------- */

    $add('create_item', 'Add a collection item',
        'Add an item, e.g. a vehicle to "vans". get_schema(<collection>) shows the fields and allowed values (e.g. status). Photos: upload with upload_image_from_url, then put the paths in the gallery field.',
        mcp_obj(['collection' => $str('Collection id, e.g. "vans"'), 'fields' => $objAny('The item\'s fields')], ['collection', 'fields']),
        $write, function ($a, $who) {
            $c = mcp_collection((string) ($a['collection'] ?? ''));
            $fields = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            if ($u = mcp_unknown($fields, $c['fields'])) {
                throw new BenchOpError('A ' . $c['singular'] . ' has no field(s): ' . implode(', ', $u) . '. get_schema("' . $c['id'] . '") lists them.');
            }
            $r = op_save_item($c['id'], '', $fields + schema_defaults($c['fields']), $who['user'] . ' (via Claude)');
            $view = mcp_item_view($c, $r['item']);
            connector_log_add($who, 'create_item', 'Added ' . $c['singular'] . ' — ' . $view['title'], ['delete' => 'collections/' . $c['id'] . '/' . $r['id']], $view['url']);
            return ['saved' => true, 'item' => $view];
        });

    $add('update_item', 'Update a collection item',
        'Change fields of an item (others are kept). E.g. {"status": "sold"} or {"price": 18995}. Lists such as a gallery are replaced as a whole.',
        mcp_obj(['collection' => $str('Collection id'), 'id' => $str('Item id from list_items'), 'fields' => $objAny('Fields to change')], ['collection', 'id', 'fields']),
        $write, function ($a, $who) {
            $c = mcp_collection((string) ($a['collection'] ?? ''));
            $id = (string) ($a['id'] ?? '');
            $item = item_get($c['id'], $id);
            if (!$item) {
                throw new BenchOpError('No ' . $c['singular'] . ' with that id. list_items shows the ids.', 404);
            }
            $fields = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            if ($u = mcp_unknown($fields, $c['fields'])) {
                throw new BenchOpError('A ' . $c['singular'] . ' has no field(s): ' . implode(', ', $u));
            }
            $r = op_save_item($c['id'], $id, mcp_merge($item + schema_defaults($c['fields']), $fields), $who['user'] . ' (via Claude)');
            $view = mcp_item_view($c, $r['item']);
            connector_log_add($who, 'update_item', 'Changed ' . $c['singular'] . ' (' . implode(', ', array_keys($fields)) . ') — ' . $view['title'],
                array_filter(['restore' => op_latest_backup('collections/' . $c['id'] . '/' . $id)]), $view['url']);
            return ['saved' => true, 'photos_moved_to_trash' => $r['trashed'], 'item' => $view];
        });

    $add('delete_item', 'Delete a collection item',
        'Delete an item completely. Confirm with the person first. For a sold vehicle, usually set status to "sold" instead so it shows as sold.',
        mcp_obj(['collection' => $str('Collection id'), 'id' => $str('Item id')], ['collection', 'id']), $delete,
        function ($a, $who) {
            $c = mcp_collection((string) ($a['collection'] ?? ''));
            $id = (string) ($a['id'] ?? '');
            $r = op_delete_item($c['id'], $id);
            connector_log_add($who, 'delete_item', 'Deleted ' . $c['singular'] . ' — ' . $r['title'], array_filter(['restore' => op_latest_backup('collections/' . $c['id'] . '/' . $id)]));
            return ['deleted' => true, 'photos_moved_to_trash' => $r['trashed']];
        });

    /* ---------- Settings ---------- */

    $add('update_settings', 'Update site settings',
        'Change site settings (others are kept), e.g. {"phone": "029 2000 0000", "announcement": "Closed bank holiday Monday"}. get_schema("settings") lists them. Colours are hex like "#1d4ed8"; they\'re adjusted automatically if needed for accessible contrast.',
        mcp_obj(['changes' => $objAny('Settings to change')], ['changes']), $write, function ($a, $who) {
            $changes = is_array($a['changes'] ?? null) ? $a['changes'] : [];
            $fields = settings_fields();
            if ($u = mcp_unknown($changes, $fields)) {
                throw new BenchOpError('Unknown settings: ' . implode(', ', $u) . '. get_schema("settings") lists them.');
            }
            if (!cfg('connector_allow_code', false)) {
                foreach ($fields as $f) {
                    if ($f['type'] === 'code' && array_key_exists($f['key'], $changes)) {
                        throw new BenchOpError('"' . $f['label'] . '" can only be changed in the admin, because it can run scripts on the site.', 403);
                    }
                }
            }
            if (isset($changes['theme']) && !isset(themes_all()[(string) $changes['theme']])) {
                throw new BenchOpError('Unknown theme. Themes: ' . implode(', ', array_keys(themes_all())));
            }
            $current = store_read('site', []) + schema_defaults(schema_settings());
            $r = op_save_settings(mcp_merge($current, $changes));
            connector_log_add($who, 'update_settings', 'Changed site settings (' . implode(', ', array_keys($changes)) . ')', array_filter(['restore' => op_latest_backup('site')]), url(''));
            return ['saved' => true, 'photos_moved_to_trash' => $r['trashed'], 'settings' => array_intersect_key(store_read('site', []), $changes)];
        });

    /* ---------- Images and backups ---------- */

    $add('upload_image_from_url', 'Add an image from a web address',
        'Download a JPG, PNG, WebP or GIF from a public web address into the site\'s uploads. Returns the path to put in an image or gallery field. Large photos are resized and their location data removed.',
        mcp_obj(['url' => $str('Public http(s) address of the image'), 'name' => $str('Optional file name, e.g. "ford-transit-front"')], ['url']),
        ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => true], function ($a, $who) {
            $url = trim((string) ($a['url'] ?? ''));
            $tmp = connector_fetch_image($url);
            try {
                $name = (string) ($a['name'] ?? '') ?: basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image';
                $r = op_store_image($tmp, $name, false);
            } finally {
                @unlink($tmp);
            }
            connector_log_add($who, 'upload_image_from_url', 'Uploaded image ' . $r['path'], [], $r['url']);
            return ['path' => $r['path'], 'url' => connector_base() . $r['url'], 'next' => 'Put the path in an image or gallery field (gallery fields take a list of paths).'];
        });

    $add('restore_backup', 'Restore a backup',
        'Put back an earlier version from list_backups (the current version is backed up first, so this can be undone too). Confirm with the person first.',
        mcp_obj(['file' => $str('Backup file name from list_backups')], ['file']), $delete, function ($a, $who) {
            $r = op_restore_backup((string) ($a['file'] ?? ''));
            $label = media_label($r['name'], store_read($r['name'], []));
            connector_log_add($who, 'restore_backup', 'Restored an earlier version of ' . $label, array_filter(['restore' => op_latest_backup($r['name'])]));
            return ['restored' => $r['name'], 'photos_restored' => $r['photos_restored'], 'photos_moved_to_trash' => $r['trashed']];
        });

    return $t;
}

/** Reads a backup file (content files start with a guard line). */
function store_read_backup(string $file): array
{
    $raw = (string) file_get_contents($file);
    if (strncmp($raw, BENCH_GUARD, strlen(BENCH_GUARD)) === 0) {
        $raw = substr($raw, strlen(BENCH_GUARD));
    }
    return json_decode($raw, true) ?: [];
}
