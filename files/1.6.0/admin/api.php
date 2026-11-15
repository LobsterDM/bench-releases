<?php
/**
 * Bench CMS — admin JSON API (used by admin.js). All actions need login + CSRF token.
 */
require __DIR__ . '/_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
if (!current_user()) {
    json_out(['error' => 'Your session has expired — please log in again.'], 401);
}
if (!csrf_ok($_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? null))) {
    json_out(['error' => 'Security token mismatch — reload the page and try again.'], 403);
}

$ct = $_SERVER['CONTENT_TYPE'] ?? '';
$in = stripos($ct, 'application/json') !== false ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $in['action'] ?? ($_GET['action'] ?? '');

try {
switch ($action) {

    /* ---------- Updates (Admin → Updates) ---------- */

    case 'update_check': {
        // admin.js calls this in the background when a check is due; "Check now" forces it
        $st = bench_update_check(!empty($in['force']));
        $auto = empty($in['force']) ? bench_update_auto($st) : null;
        json_out(['ok' => true, 'auto' => $auto, 'updates' => bench_updates_view_data()]);
    }

    case 'update_install': {
        $m = bench_update_available();
        if (!$m || $m['version'] !== (string) ($in['version'] ?? '')) {
            json_out(['error' => 'That update is no longer available — reload the page.'], 409);
        }
        $r = bench_update_install($m, current_user() ?? 'admin');
        json_out(['ok' => $r['ok'], 'result' => $r, 'updates' => bench_updates_view_data()]);
    }

    case 'update_rollback': {
        $r = bench_update_rollback((string) ($in['id'] ?? ''));
        json_out(['ok' => $r['ok'], 'result' => $r, 'updates' => bench_updates_view_data()]);
    }

    case 'update_settings': {
        $s = store_read('_updates/settings', []) + ['auto' => 'patch', 'skip' => []];
        $s['auto'] = ($in['auto'] ?? '') === 'off' ? 'off' : 'patch';
        store_write('_updates/settings', $s, false);
        json_out(['ok' => true, 'updates' => bench_updates_view_data()]);
    }

    /* ---------- Claude connector (Admin → Connections) ---------- */

    case 'connector_toggle': {
        require_once BENCH_CORE . '/connector.php';
        $on = !empty($in['enabled']);
        connector_update(function (&$s) use ($on) {
            $s['enabled'] = $on;
        });
        json_out(['ok' => true, 'connector' => connector_view_data()]);
    }

    case 'connector_disconnect': {
        require_once BENCH_CORE . '/connector.php';
        connector_disconnect((string) ($in['id'] ?? '') === '*' ? '*' : preg_replace('/[^a-z0-9_]/', '', (string) ($in['id'] ?? '')));
        json_out(['ok' => true, 'connector' => connector_view_data()]);
    }

    case 'connector_undo': {
        require_once BENCH_CORE . '/connector.php';
        $msg = connector_undo((string) ($in['id'] ?? ''), current_user());
        json_out(['ok' => true, 'message' => $msg, 'connector' => connector_view_data()]);
    }

    case 'save_page': {
        $r = op_save_page(is_array($in['page'] ?? null) ? $in['page'] : [], (string) ($in['original_slug'] ?? ''), current_user());
        json_out(['ok' => true, 'slug' => $r['slug'], 'url' => $r['url'], 'saved' => date('H:i'), 'trashed' => $r['trashed']]);
    }

    case 'create_page': {
        $title = trim((string) ($in['title'] ?? '')) ?: 'New page';
        $slug = op_free_slug($title);
        $order = count(pages_all()) * 10 + 10;
        $data = schema_defaults(schema_page());
        unset($data['slug']);
        $data = array_merge($data, [
            'title' => $title, 'published' => false, 'order' => (string) $order,
            'blocks' => clean_blocks([
                ['type' => 'hero', 'size' => 'small', 'heading' => $title],
                ['type' => 'text', 'heading' => '', 'body' => 'Start writing here…'],
            ]),
        ]);
        store_write('pages/' . $slug, $data);
        json_out(['ok' => true, 'slug' => $slug]);
    }

    case 'delete_page': {
        json_out(['ok' => true] + op_delete_page((string) ($in['slug'] ?? '')));
    }

    case 'reorder_pages': {
        foreach ((array) ($in['order'] ?? []) as $i => $slug) {
            if ($p = page_get((string) $slug)) {
                unset($p['slug']);
                $p['order'] = (string) (($i + 1) * 10);
                store_write('pages/' . $slug, $p, false);
            }
        }
        json_out(['ok' => true]);
    }

    case 'save_settings': {
        $r = op_save_settings(is_array($in['settings'] ?? null) ? $in['settings'] : []);
        json_out(['ok' => true, 'saved' => date('H:i'), 'trashed' => $r['trashed']]);
    }

    case 'upload': {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            json_out(['error' => 'Upload failed' . ($f && in_array($f['error'], [1, 2], true) ? ': file too large for the server.' : '.')], 422);
        }
        json_out(['ok' => true] + op_store_image($f['tmp_name'], (string) $f['name'], true));
    }

    case 'media': {
        // Each file says where it's used; the trash is listed alongside
        $used = media_usage();
        $files = array_map(fn($f) => $f + ['used' => $used[$f['path']] ?? []], media_files());
        json_out(['ok' => true, 'files' => $files, 'trash' => trash_list()]);
    }

    case 'trash_unused': {
        // Admin → Media → "Move unused to trash"
        $used = media_usage();
        $n = 0;
        foreach (media_files() as $f) {
            if (!isset($used[$f['path']]) && trash_move($f['path'], 'Not used anywhere')) {
                $n++;
            }
        }
        json_out(['ok' => true, 'trashed' => $n]);
    }

    case 'trash_restore': {
        $r = trash_restore((string) ($in['id'] ?? ''));
        $r['ok'] ? json_out($r) : json_out(['error' => $r['error']], 409);
    }

    case 'trash_delete': {
        if (!trash_delete((string) ($in['id'] ?? ''))) {
            json_out(['error' => 'That image is no longer in the trash.'], 404);
        }
        json_out(['ok' => true]);
    }

    case 'trash_empty': {
        json_out(['ok' => true] + trash_empty());
    }

    case 'delete_media': {
        // Admin → Media → delete: moves the image to the trash (it can be restored)
        $path = (string) ($in['path'] ?? '');
        if (!media_upload_file($path)) {
            json_out(['error' => 'File not found.'], 404);
        }
        if (!trash_move($path, 'Deleted in Media')) {
            json_out(['error' => 'Could not move the image to the trash. Check the content/ folder is writable.'], 500);
        }
        json_out(['ok' => true]);
    }

    case 'restore_backup': {
        json_out(['ok' => true] + op_restore_backup((string) ($in['file'] ?? '')));
    }

    case 'change_password': {
        $users = users();
        $u = current_user();
        if (!password_verify((string) ($in['current'] ?? ''), $users[$u]['hash'] ?? '')) {
            json_out(['error' => 'Current password is wrong.'], 422);
        }
        if (strlen((string) ($in['new'] ?? '')) < 10) {
            json_out(['error' => 'New password needs at least 10 characters.'], 422);
        }
        $users[$u]['hash'] = password_hash((string) $in['new'], PASSWORD_DEFAULT);
        store_write('_auth/users', $users, false);
        json_out(['ok' => true]);
    }

    case 'ping': {
        // Keep-alive from open admin pages, so a session doesn't time out mid-edit (WCAG 2.2.5 / 2.2.6)
        json_out(['ok' => true]);
    }

    case 'toggle_plugin': {
        $id = (string) ($in['id'] ?? '');
        if (!isset(plugins_available()[$id])) {
            json_out(['error' => 'Plugin not found.'], 404);
        }
        $enabled = plugins_enabled();
        $enabled = !empty($in['enabled']) ? array_values(array_unique(array_merge($enabled, [$id]))) : array_values(array_diff($enabled, [$id]));
        store_write('plugins', ['enabled' => $enabled], false);
        json_out(['ok' => true, 'enabled' => $enabled]);
    }

    case 'save_item': {
        $r = op_save_item((string) ($in['collection'] ?? ''), (string) ($in['id'] ?? ''), is_array($in['item'] ?? null) ? $in['item'] : [], current_user());
        json_out(['ok' => true, 'id' => $r['id'], 'saved' => date('H:i'), 'summary' => $r['summary'], 'trashed' => $r['trashed']]);
    }

    case 'delete_item': {
        $r = op_delete_item((string) ($in['collection'] ?? ''), (string) ($in['id'] ?? ''));
        json_out(['ok' => true, 'trashed' => $r['trashed']]);
    }

    case 'load_demo': {
        try {
            demo_load((string) ($in['id'] ?? ''));
        } catch (Throwable $e) {
            json_out(['error' => $e->getMessage()], 422);
        }
        json_out(['ok' => true]);
    }

    default:
        json_out(['error' => 'Unknown action'], 400);
}
} catch (BenchOpError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
}
