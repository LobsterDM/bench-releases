<?php
/**
 * Bench CMS — content operations shared by the admin (admin/api.php) and the
 * Claude connector (admin/mcp.php): schema-based input cleaning, and saving pages,
 * collection items, settings and images. Both callers go through these functions,
 * so a change made by Claude is checked, backed up and trashed exactly like one
 * made in the admin.
 */


/** A problem the caller should show to the person (or to Claude), with an HTTP-style status. */
class BenchOpError extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

/* ------------------------------------------------------------------ *
 * Input cleaning against the schema
 * ------------------------------------------------------------------ */

/** Image values must be an upload path or a plain https URL (no quotes or brackets that could break out of HTML/CSS). */
function safe_image_path(string $p): bool
{
    return (bool) preg_match('#^(uploads/[\w\-./]+|https://[^\s\'"()<>\\\\]+)$#', $p) && strpos($p, '..') === false;
}

/** Keeps only fields defined in the schema, coerced to the right shape. */
function clean_fields(array $input, array $fields): array
{
    $out = [];
    foreach ($fields as $field) {
        if ($field['type'] === 'heading') {
            continue;
        }
        $k = $field['key'];
        $v = $input[$k] ?? null;
        switch ($field['type']) {
            case 'checkbox':
                $out[$k] = (bool) $v;
                break;
            case 'list':
                $out[$k] = [];
                foreach (is_array($v) ? array_slice($v, 0, 100) : [] as $item) {
                    if (is_array($item)) {
                        $out[$k][] = clean_fields($item, $field['fields']);
                    }
                }
                break;
            case 'select':
                $v = (string) $v;
                $opts = array_map('strval', schema_options($field));
                $out[$k] = in_array($v, $opts, true) ? $v : (string) ($field['default'] ?? $opts[0] ?? '');
                break;
            case 'color':
                $out[$k] = css_color((string) $v) ?? '';
                break;
            case 'number':
                $n = is_scalar($v) ? str_replace([',', '£', ' '], '', (string) $v) : '';
                $out[$k] = is_numeric($n) ? (string) (0 + $n) : '';
                break;
            case 'date':
                $out[$k] = is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
                break;
            case 'datetime':
                // Site-local time, as a datetime-local input sends it: 2026-11-20T09:30 (seconds dropped)
                $out[$k] = is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', trim($v), $m)
                    && strtotime($m[1] . ' ' . $m[2]) !== false ? $m[1] . 'T' . $m[2] : '';
                break;
            case 'multiselect':
                // Several of the allowed options, kept in the order they're defined
                $picked = array_map('strval', is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]));
                $out[$k] = array_values(array_filter(array_map('strval', schema_options($field)), fn($o) => $o !== '' && in_array($o, $picked, true)));
                break;
            case 'tags':
                // Free-form labels: an array or "a, b, c". Trimmed, de-duplicated (ignoring case), max 30 × 60 chars
                $list = is_array($v) ? $v : explode(',', is_scalar($v) ? (string) $v : '');
                $out[$k] = [];
                $seen = [];
                foreach ($list as $t) {
                    $t = is_scalar($t) ? trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', (string) $t))) : '';
                    $t = mb_substr($t, 0, 60);
                    if ($t === '' || isset($seen[mb_strtolower($t)])) {
                        continue;
                    }
                    $seen[mb_strtolower($t)] = true;
                    $out[$k][] = $t;
                    if (count($out[$k]) >= 30) {
                        break;
                    }
                }
                break;
            case 'gallery':
                $out[$k] = [];
                foreach (is_array($v) ? array_slice($v, 0, 40) : [] as $img) {
                    if (is_string($img) && safe_image_path($img)) {
                        $out[$k][] = $img;
                    }
                }
                break;
            case 'image':
                $s = is_scalar($v) ? trim((string) $v) : '';
                $out[$k] = safe_image_path($s) ? $s : '';
                break;
            default:
                $s = is_scalar($v) ? (string) $v : '';
                $s = str_replace("\r\n", "\n", $s);
                $out[$k] = in_array($field['type'], ['code', 'richtext', 'textarea'], true) ? mb_substr($s, 0, 50000) : mb_substr(trim($s), 0, 1000);
        }
    }
    // Translations of this object's text fields (bilingual sites, see core/i18n.php)
    if (isset($input['_t'])) {
        $t = clean_translations($input['_t'], $fields);
        if ($t) {
            $out['_t'] = $t;
        }
    }
    return $out;
}

function clean_blocks($blocks): array
{
    $schema = schema_blocks();
    $out = [];
    foreach (is_array($blocks) ? $blocks : [] as $b) {
        $type = $b['type'] ?? '';
        if (!is_array($b) || !isset($schema[$type])) {
            continue;
        }
        $out[] = ['type' => $type, 'hidden' => !empty($b['hidden'])]
            + clean_fields($b, array_merge(schema_block_common(), $schema[$type]['fields']));
    }
    return $out;
}

function settings_fields(): array
{
    return array_merge(...array_values(schema_settings()));
}

function media_files(): array
{
    $out = [];
    if (!is_dir(BENCH_UPLOADS)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BENCH_UPLOADS, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $f->getFilename())) {
            $rel = 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen(BENCH_UPLOADS) + 1));
            $out[] = ['path' => $rel, 'url' => media_url($rel), 'size' => $f->getSize(), 'time' => $f->getMTime(), 'name' => $f->getFilename()];
        }
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function item_summary(array $c, array $item): array
{
    $thumb = item_image($c, $item);
    return [
        'id'       => $item['id'],
        'title'    => item_title($c, $item),
        'subtitle' => item_subtitle($c, $item),
        'thumb'    => $thumb ? media_url($thumb) : '',
        'badge'    => is_callable($c['badge']) ? ($c['badge'])($item) : null,
        'url'      => $c['route'] !== '' ? item_url($c, $item) : '',
        'updated'  => $item['updated'] ?? '',
    ];
}

/** Shrinks big phone photos to max 2000px and re-encodes (also strips EXIF/GPS data). */
function resize_if_large(string $file, string $mime, array $info): void
{
    if (!function_exists('imagecreatetruecolor') || $mime === 'image/gif') {
        return;
    }
    [$w, $h] = $info;
    $max = 2000;
    $load = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$mime] ?? null;
    if (!$load || !function_exists($load) || ($w <= $max && $h <= $max && $mime !== 'image/jpeg')) {
        return;
    }
    $src = @$load($file);
    if (!$src) {
        return;
    }
    $scale = min(1, $max / max($w, $h));
    $nw = (int) round($w * $scale);
    $nh = (int) round($h * $scale);
    $dst = imagecreatetruecolor($nw, $nh);
    if ($mime !== 'image/jpeg') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    match ($mime) {
        'image/jpeg' => imagejpeg($dst, $file, 82),
        'image/png'  => imagepng($dst, $file, 7),
        'image/webp' => imagewebp($dst, $file, 82),
    };
    imagedestroy($src);
    imagedestroy($dst);
}

/* ------------------------------------------------------------------ *
 * Pages
 * ------------------------------------------------------------------ */

const BENCH_RESERVED_SLUGS = ['home', 'admin', 'assets', 'uploads', 'themes', 'content', 'core', 'custom', 'plugins', 'tools', 'demos', 'docs', 'tests', '404'];

/**
 * Saves a page. $p has the page fields and 'blocks'; $original is its current slug
 * ('' for a new page). Returns ['slug', 'url', 'trashed'].
 */
function op_save_page(array $p, string $original, string $by): array
{
    $meta = clean_fields($p, schema_page());
    $slug = $original === 'home' ? 'home' : slugify($meta['slug'] ?: $meta['title']);
    if ($slug === '404' && $original !== '404') {
        $slug = '404-page';
    }
    if ($meta['title'] === '') {
        throw new BenchOpError('Please give the page a title.');
    }
    if ($slug !== $original && bench_lang_known($slug)) {
        throw new BenchOpError("\"$slug\" is a language code, so it can't be a page address.");
    }
    if ($slug !== $original && (page_get($slug) || ($original === '' && in_array($slug, BENCH_RESERVED_SLUGS, true)))) {
        throw new BenchOpError("A page with the address \"$slug\" already exists.");
    }
    if ($original !== '' && !page_get($original)) {
        throw new BenchOpError("There's no page with the address \"$original\".", 404);
    }
    unset($meta['slug']);
    // Page addresses in other languages: made URL-safe, and unique within that language
    foreach ($meta['_t'] ?? [] as $lang => $tr) {
        if (!isset($tr['slug'])) {
            continue;
        }
        $ts = $slug === 'home' ? '' : slugify($tr['slug']);
        if ($ts === '' || $ts === $slug) {
            unset($meta['_t'][$lang]['slug']);
        } else {
            $map = bench_slug_map($lang);
            $usedBy = array_search($ts, $map, true); // another page's address in this language
            $mine = [$original, $slug];
            // a page whose main address is $ts and that has no address of its own in this language
            $clash = is_file(content_path('pages/' . $ts)) && !in_array($ts, $mine, true) && !isset($map[$ts]);
            if (($usedBy !== false && !in_array($usedBy, $mine, true)) || $clash || in_array($ts, BENCH_RESERVED_SLUGS, true) || bench_lang_known($ts)) {
                throw new BenchOpError('The ' . (bench_languages()[$lang]['english_name'] ?? $lang) . " address \"$ts\" is already used by another page.");
            }
            $meta['_t'][$lang]['slug'] = $ts;
        }
        if (!$meta['_t'][$lang]) {
            unset($meta['_t'][$lang]);
        }
    }
    if (isset($meta['_t']) && !$meta['_t']) {
        unset($meta['_t']);
    }
    $before = $original !== '' ? (page_get($original) ?? []) : [];
    $meta['order'] = (string) (int) $meta['order'];
    $data = $meta + ['blocks' => clean_blocks($p['blocks'] ?? []), 'updated' => date('c'), 'updated_by' => $by];
    if (!store_write('pages/' . $slug, $data)) {
        throw new BenchOpError('Could not write the file. Check the content/ folder is writable.', 500);
    }
    if ($original !== '' && $original !== $slug && $original !== 'home') {
        store_delete('pages/' . $original);
    }
    // Photos removed from the page go to the trash if nothing else uses them
    $trashed = media_trash_unused(media_refs($before), 'Page: ' . $meta['title']);
    return ['slug' => $slug, 'url' => url($slug), 'trashed' => $trashed];
}

/** A free slug based on $title (adds -2, -3… if taken). */
function op_free_slug(string $title): string
{
    $base = slugify($title) ?: 'page';
    $slug = $base;
    for ($i = 2; page_get($slug) || in_array($slug, BENCH_RESERVED_SLUGS, true) || bench_lang_known($slug); $i++) {
        $slug = $base . '-' . $i;
    }
    return $slug;
}

/** Deletes a page (not the home page). Returns ['trashed']. */
function op_delete_page(string $slug): array
{
    if ($slug === 'home') {
        throw new BenchOpError('The home page can\'t be deleted.');
    }
    $before = page_get($slug);
    if (!$before || !store_delete('pages/' . $slug)) {
        throw new BenchOpError('Page not found.', 404);
    }
    $trashed = media_trash_unused(media_refs($before), 'Page: ' . ($before['title'] ?? $slug) . ' (deleted)');
    return ['trashed' => $trashed];
}

/* ------------------------------------------------------------------ *
 * Site settings
 * ------------------------------------------------------------------ */

/** Saves all site settings ($s is the full set, as the admin sends it). Returns ['trashed']. */
function op_save_settings(array $s): array
{
    $s = clean_fields($s, settings_fields());
    if (!isset(themes_all()[$s['theme']])) {
        $s['theme'] = 'trade';
    }
    if ($s['second_language'] === $s['language']) {
        $s['second_language'] = '';
    }
    $before = store_read('site', []);
    if (!store_write('site', $s)) {
        throw new BenchOpError('Could not write the file. Check the content/ folder is writable.', 500);
    }
    $trashed = media_trash_unused(media_refs($before), 'Site settings');
    return ['trashed' => $trashed];
}

/* ------------------------------------------------------------------ *
 * Collection items (vehicles, etc.)
 * ------------------------------------------------------------------ */

/** Saves a collection item ($id '' for a new one). Returns ['id', 'item', 'summary', 'trashed']. */
function op_save_item(string $cid, string $id, array $input, string $by): array
{
    $c = collection($cid);
    if (!$c) {
        throw new BenchOpError('Unknown collection (is the plugin enabled?).', 404);
    }
    $data = clean_fields($input, $c['fields']);
    $existing = $id !== '' ? item_get($c['id'], $id) : null;
    if ($id !== '' && !$existing) {
        throw new BenchOpError('That ' . $c['singular'] . ' no longer exists.', 404);
    }
    if (is_callable($c['before_save'])) {
        // The plugin's last word on the cleaned data, e.g. filling in a date; it may throw BenchOpError
        $data = clean_fields(($c['before_save'])($data, $existing), $c['fields']);
    }
    if (!$existing) {
        $base = trim(substr(slugify(item_title($c, $data)), 0, 60), '-') ?: $c['singular'];
        if ($c['clean_ids']) {
            // Readable addresses (/blog/opening-hours-over-christmas), numbered if taken
            $id = $base;
            for ($n = 2; item_get($c['id'], $id) || isset($c['subroutes'][$id]); $n++) {
                $id = $base . '-' . $n;
            }
        } else {
            do {
                $id = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
            } while (item_get($c['id'], $id) || isset($c['subroutes'][$id]));
        }
    }
    $before = $existing ?? [];
    $data['created'] = $existing['created'] ?? date('c');
    $data['updated'] = date('c');
    $data['updated_by'] = $by;
    if (!store_write('collections/' . $c['id'] . '/' . $id, $data)) {
        throw new BenchOpError('Could not write the file. Check the content/ folder is writable.', 500);
    }
    $data['id'] = $id;
    // Photos removed from this item go to the trash if nothing else uses them
    $trashed = media_trash_unused(media_refs($before), ucfirst($c['singular']) . ': ' . item_title($c, $data));
    return ['id' => $id, 'item' => $data, 'summary' => item_summary($c, $data), 'trashed' => $trashed];
}

/** Deletes a collection item. Returns ['title', 'trashed']. */
function op_delete_item(string $cid, string $id): array
{
    $c = collection($cid);
    $before = $c ? item_get($c['id'], $id) : null;
    if (!$before || !store_delete('collections/' . $c['id'] . '/' . $id)) {
        throw new BenchOpError('Not found.', 404);
    }
    // Its photos go to the trash unless something else uses them; restoring it from Backups brings them back
    $trashed = media_trash_unused(media_refs($before), ucfirst($c['singular']) . ': ' . item_title($c, $before) . ' (deleted)');
    return ['title' => item_title($c, $before), 'trashed' => $trashed];
}

/* ------------------------------------------------------------------ *
 * Images
 * ------------------------------------------------------------------ */

/**
 * Checks an image file and files it under uploads/YYYY/MM/. $uploaded: it came
 * from a form upload (move_uploaded_file); otherwise it's a temp file we made.
 * Returns ['path', 'url'].
 */
function op_store_image(string $tmp, string $originalName, bool $uploaded): array
{
    $max = (int) cfg('max_upload_mb', 5) * 1024 * 1024;
    if (!is_file($tmp) || filesize($tmp) > $max) {
        throw new BenchOpError('Image is over ' . cfg('max_upload_mb', 5) . 'MB.');
    }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    if (!isset($types[$mime]) || !$info) {
        throw new BenchOpError('Only JPG, PNG, WebP or GIF images can be uploaded.');
    }
    $name = slugify(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'image';
    $name = substr($name, 0, 60) . '-' . bin2hex(random_bytes(3)) . '.' . $types[$mime];
    $sub = date('Y/m');
    $dir = BENCH_UPLOADS . '/' . $sub;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $dest = $dir . '/' . $name;
    $ok = $uploaded ? move_uploaded_file($tmp, $dest) : (@rename($tmp, $dest) || (copy($tmp, $dest) && @unlink($tmp)));
    if (!$ok) {
        throw new BenchOpError('Could not save the file. Check the uploads/ folder is writable.', 500);
    }
    @chmod($dest, 0644);
    resize_if_large($dest, $mime, $info);
    $rel = 'uploads/' . $sub . '/' . $name;
    return ['path' => $rel, 'url' => media_url($rel)];
}

/* ------------------------------------------------------------------ *
 * Backups
 * ------------------------------------------------------------------ */

/** The newest backup file name for a content file (e.g. "pages/home"), or null. */
function op_latest_backup(string $name): ?string
{
    $all = glob(BENCH_CONTENT . '/_backups/' . str_replace('/', '__', $name) . '@*.php') ?: [];
    rsort($all);
    return $all ? basename($all[0]) : null;
}

/** Puts a backup back (the current version is backed up first). Returns ['name', 'photos_restored', 'trashed']. */
function op_restore_backup(string $file): array
{
    $file = basename($file);
    $src = BENCH_CONTENT . '/_backups/' . $file;
    if (!preg_match('/^([a-z0-9_\-]+)@[0-9\-]+\.php$/', $file, $m) || !is_file($src)) {
        throw new BenchOpError('Backup not found.', 404);
    }
    $name = str_replace('__', '/', $m[1]);
    if (strpos($name, '_') === 0) {
        throw new BenchOpError('That backup can\'t be restored here.', 403);
    }
    $target = content_path($name);
    $before = store_read($name, []);
    if (is_file($target)) {
        backup_file($name, $target); // so a restore can itself be undone
    }
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true); // e.g. restoring a deleted vehicle
    }
    copy($src, $target);
    // Bring back photos the restored version uses, and trash ones only the replaced version used
    $restored = trash_restore_paths(media_refs(store_read($name, [])));
    $label = media_label($name, store_read($name, []));
    $trashed = media_trash_unused(media_refs($before), $label . ' (replaced by a backup)');
    return ['name' => $name, 'photos_restored' => $restored, 'trashed' => $trashed];
}
