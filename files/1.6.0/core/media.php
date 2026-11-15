<?php
/**
 * Bench CMS — media usage and the media trash (admin only).
 *
 * Where an image is used: every content file (site settings, pages, collection
 * items, plugin data) is scanned for paths like "uploads/2026/11/van-a1b2c3.jpg",
 * in any field, rich text included. Files in content/_* folders (logins, backups,
 * the trash itself) don't count as use.
 *
 * The trash: when a vehicle or page is deleted, or a photo is removed from it and
 * saved, each photo it used that nothing else uses is moved to content/_trash/.
 * The content/ folder is blocked from the web, so a trashed photo stops being
 * public straight away. Restoring the vehicle or page from Backups puts its photos
 * back. Trashed photos stay until someone empties the trash (Admin → Media → Trash).
 */

const BENCH_TRASH_DIR = BENCH_CONTENT . '/_trash/files';
const BENCH_IMAGE_EXT = '/\.(jpe?g|png|webp|gif)$/i';

/**
 * Upload paths ("uploads/…") referenced anywhere in $data. A path inside a full
 * URL or after a sub-folder (/site/uploads/…) counts too, so an image is never
 * treated as unused while something still points at it.
 */
function media_refs($data): array
{
    $found = [];
    $walk = function ($v) use (&$walk, &$found) {
        if (is_array($v)) {
            foreach ($v as $x) {
                $walk($x);
            }
            return;
        }
        if (!is_string($v) || stripos($v, 'uploads/') === false) {
            return;
        }
        if (preg_match_all('~(?<![A-Za-z0-9_.\-])uploads/((?:[A-Za-z0-9_\-][A-Za-z0-9_.\-]*/)*[A-Za-z0-9_\-][A-Za-z0-9_.\-]*\.(?:jpe?g|png|webp|gif))~i', $v, $m)) {
            foreach ($m[1] as $p) {
                if (strpos($p, '..') === false) {
                    $found['uploads/' . $p] = true;
                }
            }
        }
    };
    $walk($data);
    return array_keys($found);
}

/** Every live content file, as name ("pages/home", "collections/vans/x") => decoded data. */
function media_content_files(): array
{
    $out = [];
    if (!is_dir(BENCH_CONTENT)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BENCH_CONTENT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') {
            continue;
        }
        $name = substr(str_replace('\\', '/', substr($f->getPathname(), strlen(BENCH_CONTENT) + 1)), 0, -4);
        if (preg_match('#(^|/)_#', $name)) {
            continue; // _auth, _backups, _trash, _updates… are not content
        }
        $raw = (string) file_get_contents($f->getPathname());
        if (strncmp($raw, BENCH_GUARD, strlen(BENCH_GUARD)) === 0) {
            $raw = substr($raw, strlen(BENCH_GUARD));
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $out[$name] = $data;
        }
    }
    ksort($out);
    return $out;
}

/** A readable name for where an image is used, e.g. "Vehicle: 2019 Ford Transit". */
function media_label(string $name, array $data): string
{
    if ($name === 'site') {
        return 'Site settings';
    }
    if (strpos($name, 'pages/') === 0) {
        return 'Page: ' . (($data['title'] ?? '') !== '' ? $data['title'] : substr($name, 6));
    }
    if (preg_match('#^collections/([a-z0-9_\-]+)/([a-z0-9_\-]+)$#', $name, $m)) {
        $c = function_exists('collection') ? collection($m[1]) : null;
        if ($c) {
            return ucfirst($c['singular']) . ': ' . item_title($c, $data + ['id' => $m[2]]);
        }
        return ucfirst($m[1]) . ' (plugin switched off): ' . $m[2];
    }
    return $name;
}

/** Where each image is used: path => [labels]. Only images that are used appear. */
function media_usage(): array
{
    $use = [];
    foreach (media_content_files() as $name => $data) {
        $label = media_label($name, $data);
        foreach (media_refs($data) as $p) {
            $use[$p][] = $label;
        }
    }
    foreach ($use as &$labels) {
        $labels = array_values(array_unique($labels));
    }
    return $use;
}

/** Absolute path of an image in uploads/, or null if $rel isn't one. */
function media_upload_file(string $rel): ?string
{
    if (strpos($rel, 'uploads/') !== 0 || strpos($rel, '..') !== false || !preg_match(BENCH_IMAGE_EXT, $rel)) {
        return null;
    }
    $real = realpath(BENCH_ROOT . '/' . $rel);
    $root = realpath(BENCH_UPLOADS);
    if (!$real || !$root || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
        return null;
    }
    return $real;
}

/* ---------- Trash ---------- */

function trash_index(): array
{
    return store_read('_trash/index', []);
}

function trash_save(array $index): void
{
    store_write('_trash/index', $index, false);
}

function trash_file(array $entry): string
{
    return BENCH_TRASH_DIR . '/' . $entry['file'];
}

/** Move one image from uploads/ to the trash. Returns the trash entry, or null. */
function trash_move(string $rel, string $from): ?array
{
    $src = media_upload_file($rel);
    if (!$src) {
        return null;
    }
    if (!is_dir(BENCH_TRASH_DIR) && !mkdir(BENCH_TRASH_DIR, 0755, true)) {
        return null;
    }
    $id = bin2hex(random_bytes(6));
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $entry = [
        'id'      => $id,
        'path'    => $rel,
        'name'    => basename($rel),
        'file'    => $id . '.' . $ext,
        'size'    => filesize($src),
        'from'    => $from,
        'trashed' => date('c'),
        'by'      => function_exists('current_user') ? (current_user() ?? '') : '',
    ];
    $dest = trash_file($entry);
    if (!@rename($src, $dest)) {
        if (!@copy($src, $dest) || !@unlink($src)) {
            @unlink($dest);
            return null;
        }
    }
    $index = trash_index();
    $index[$id] = $entry;
    trash_save($index);
    return $entry;
}

/**
 * After a save or delete: move each of $paths to the trash if nothing uses it
 * any more. $from says what it came from, e.g. "Vehicle: 2019 Ford Transit".
 * Returns how many images were moved.
 */
function media_trash_unused(array $paths, string $from): int
{
    if (!$paths || !cfg('media_trash', true)) {
        return 0;
    }
    $used = media_usage();
    $n = 0;
    foreach (array_unique($paths) as $p) {
        if (!isset($used[$p]) && trash_move($p, $from)) {
            $n++;
        }
    }
    return $n;
}

/** Restore one trashed image to where it was. */
function trash_restore(string $id): array
{
    $index = trash_index();
    $e = $index[$id] ?? null;
    if (!$e || !is_file(trash_file($e))) {
        return ['ok' => false, 'error' => 'That image is no longer in the trash.'];
    }
    $dest = BENCH_ROOT . '/' . $e['path'];
    if (is_file($dest)) {
        return ['ok' => false, 'error' => 'There\'s already an image at ' . $e['path'] . ', so this one was left in the trash.'];
    }
    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) {
        return ['ok' => false, 'error' => 'Could not recreate the folder. Check uploads/ is writable.'];
    }
    if (!@rename(trash_file($e), $dest) && !(@copy(trash_file($e), $dest) && @unlink(trash_file($e)))) {
        return ['ok' => false, 'error' => 'Could not move the image back. Check uploads/ is writable.'];
    }
    unset($index[$id]);
    trash_save($index);
    return ['ok' => true, 'path' => $e['path']];
}

/** Put back any trashed images that $paths refer to (used when restoring a backup). */
function trash_restore_paths(array $paths): int
{
    $want = array_flip($paths);
    $n = 0;
    $entries = trash_index();
    uasort($entries, fn($a, $b) => strcmp($b['trashed'], $a['trashed'])); // newest copy first
    foreach ($entries as $id => $e) {
        if (isset($want[$e['path']]) && !is_file(BENCH_ROOT . '/' . $e['path'])) {
            if (trash_restore($id)['ok']) {
                $n++;
                unset($want[$e['path']]);
            }
        }
    }
    return $n;
}

/** Delete one trashed image for good. */
function trash_delete(string $id): bool
{
    $index = trash_index();
    if (!isset($index[$id])) {
        return false;
    }
    @unlink(trash_file($index[$id]));
    unset($index[$id]);
    trash_save($index);
    return true;
}

/** Empty the trash: deletes every trashed image for good, including any stray files. */
function trash_empty(): array
{
    $count = 0;
    $bytes = 0;
    foreach (glob(BENCH_TRASH_DIR . '/*') ?: [] as $f) {
        if (is_file($f)) {
            $bytes += (int) filesize($f);
            if (@unlink($f)) {
                $count++;
            }
        }
    }
    trash_save([]);
    return ['count' => $count, 'bytes' => $bytes];
}

/** Trash contents for the admin, newest first. */
function trash_list(): array
{
    $out = [];
    $index = trash_index();
    $changed = false;
    foreach ($index as $id => $e) {
        if (!is_file(trash_file($e))) {
            unset($index[$id]); // file removed by hand: drop the entry
            $changed = true;
            continue;
        }
        $out[] = $e + ['preview' => '?trash_image=' . $id];
    }
    if ($changed) {
        trash_save($index);
    }
    usort($out, fn($a, $b) => strcmp($b['trashed'], $a['trashed']));
    return $out;
}

/** Send a trashed image to a logged-in admin (the trash itself isn't public). */
function trash_send_preview(string $id): void
{
    $e = preg_match('/^[a-f0-9]{12}$/', $id) ? (trash_index()[$id] ?? null) : null;
    $file = $e ? trash_file($e) : '';
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!$e || !is_file($file) || !isset($types[$ext])) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}
