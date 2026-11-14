<?php
/**
 * Bench CMS — admin helpers (auth, CSRF, sanitising input against the schema)
 */
require dirname(__DIR__) . '/core/bootstrap.php';
require BENCH_CORE . '/security.php';
require BENCH_CORE . '/media.php';
require BENCH_CORE . '/content-ops.php';

session_boot();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
// Content-Security-Policy: the admin only runs its own scripts, so injected
// script (XSS) can't run even if something slipped past output escaping.
$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$GLOBALS['csp_nonce']}'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; font-src 'self' data:; "
    . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

function users(): array
{
    return store_read('_auth/users', []);
}

function has_users(): bool
{
    return (bool) users();
}

function current_user(): ?string
{
    return $_SESSION['bench_user'] ?? null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/* ---------- Brute-force protection ---------- */

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function login_locked(): int
{
    $att = store_read('_auth/attempts', []);
    $rec = $att[hash('sha256', client_ip())] ?? null;
    if (!$rec || $rec['count'] < 5) {
        return 0;
    }
    $until = $rec['last'] + 60 * (int) cfg('lockout_minutes', 15);
    return max(0, $until - time());
}

function login_attempt(bool $success): void
{
    $att = store_read('_auth/attempts', []);
    $key = hash('sha256', client_ip());
    // Drop stale records
    foreach ($att as $k => $r) {
        if ($r['last'] < time() - 86400) {
            unset($att[$k]);
        }
    }
    if ($success) {
        unset($att[$key]);
    } else {
        $att[$key] = ['count' => ($att[$key]['count'] ?? 0) + 1, 'last' => time()];
    }
    store_write('_auth/attempts', $att, false);
}

function do_login(string $user, string $pass): bool
{
    $users = users();
    $hash = $users[$user]['hash'] ?? null;
    // Always run password_verify to keep timing similar for unknown users
    $ok = password_verify($pass, $hash ?? '$2y$10$abcdefghijklmnopqrstuuCYmZ3E7S2JxFZ4E1mK7Z1h1Q0p0lW6');
    if ($ok && $hash) {
        session_regenerate_id(true);
        $_SESSION['bench_user'] = $user;
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $users[$user]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
            store_write('_auth/users', $users, false);
        }
        return true;
    }
    return false;
}

/* ---------- JSON replies (input sanitising is in core/content-ops.php) ---------- */

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- Demo packs ---------- */

function demos_available(): array
{
    $out = [];
    foreach (glob(BENCH_ROOT . '/demos/*/demo.json') ?: [] as $f) {
        $id = basename(dirname($f));
        if (preg_match('/^[a-z0-9\-]+$/', $id)) {
            $out[$id] = (json_decode((string) file_get_contents($f), true) ?: []) + ['name' => $id, 'description' => ''];
        }
    }
    return $out;
}

/** Replaces pages, settings, plugins and collection items with a demo pack. Users are kept; everything replaced is backed up. */
function demo_load(string $id): void
{
    $dir = BENCH_ROOT . '/demos/' . $id;
    if (!isset(demos_available()[$id])) {
        throw new RuntimeException('Demo not found');
    }
    foreach (glob(BENCH_CONTENT . '/pages/*.php') ?: [] as $f) {
        store_delete('pages/' . basename($f, '.php'));
    }
    foreach (glob(BENCH_CONTENT . '/collections/*/*.php') ?: [] as $f) {
        store_delete('collections/' . basename(dirname($f)) . '/' . basename($f, '.php'));
    }
    $src = $dir . '/content';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($src) + 1);
        $name = str_replace('\\', '/', substr($rel, 0, -4));
        if (substr($rel, -4) === '.php' && strpos($name, '_') !== 0) {
            store_write($name, store_read_file($f->getPathname()));
        }
    }
    if (is_dir($dir . '/uploads')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/uploads', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $target = BENCH_UPLOADS . '/' . substr($f->getPathname(), strlen($dir . '/uploads') + 1);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            copy($f->getPathname(), $target);
        }
    }
}

function store_read_file(string $file): array
{
    $raw = (string) file_get_contents($file);
    if (strncmp($raw, BENCH_GUARD, strlen(BENCH_GUARD)) === 0) {
        $raw = substr($raw, strlen(BENCH_GUARD));
    }
    return json_decode($raw, true) ?: [];
}
