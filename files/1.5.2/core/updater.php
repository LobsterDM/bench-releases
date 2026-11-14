<?php
/**
 * Bench CMS — updates (Admin → Updates)
 *
 * How it works
 *  - Each release is published as a zip plus a small manifest (stable.json) on the
 *    release channel. The manifest is signed (Ed25519) by the release workflow; the
 *    zip's SHA-256 is in the manifest, and every file's SHA-256 is in the zip's
 *    core/bench-files.json. Nothing is installed unless all three check out, so a
 *    hacked download server can't push code to sites.
 *  - Updates replace only the files Bench ships. content/, uploads/, custom/ and
 *    config.php are never touched. Shipped files you changed are backed up first;
 *    a customised .htaccess is kept and the new one saved as .htaccess.new.
 *  - Before installing, the files being replaced are backed up. After installing,
 *    the site's home page and admin are loaded; if either fails, the update is
 *    rolled back automatically.
 *  - Works without PHP's sodium and zip extensions, which some hosts (e.g. Fasthosts)
 *    don't offer: signatures are then checked with the bundled pure-PHP Ed25519 code
 *    (core/vendor/sodium_compat), and instead of the zip, only the files that changed
 *    are downloaded one by one from the release channel and checked against the
 *    signed file list.
 *  - Sites check once every 12 hours. Fix releases (1.5.0 → 1.5.1) install by
 *    themselves unless turned off; bigger releases wait for someone to click Update.
 *
 * config.php options: 'updates' => false turns all of this off (e.g. for sites
 * deployed from Git). 'update_url' / 'update_public_key' point a site at another
 * release channel (used by the tests).
 */

declare(strict_types=1);

const BENCH_UPDATE_URL = 'https://raw.githubusercontent.com/LobsterDM/bench-releases/main/stable.json';
// Public half of the release signing key (base64). The private half is the
// BENCH_SIGNING_KEY secret in the GitHub repo and never leaves GitHub.
const BENCH_UPDATE_KEY = 'HJaRdQleoaQg05IBW2lTFtt8ZFuddchwlvXhOTioDGo=';
const BENCH_UPDATE_EVERY = 43200; // seconds between checks (12 hours)
const BENCH_UPDATE_BACKUPS = 3;   // previous versions kept for rollback

function bench_updates_dir(): string
{
    return BENCH_CONTENT . '/_updates';
}

function bench_updates_enabled(): bool
{
    return cfg('updates', true) !== false;
}

function bench_update_key(): string
{
    return (string) (cfg('update_public_key') ?: BENCH_UPDATE_KEY);
}

function bench_update_url(): string
{
    return (string) (cfg('update_url') ?: BENCH_UPDATE_URL);
}

function bench_update_status(): array
{
    return store_read('_updates/status', []) + ['checked' => 0, 'latest' => null, 'error' => null, 'site_url' => ''];
}

function bench_update_settings(): array
{
    $s = store_read('_updates/settings', []) + ['auto' => 'patch', 'skip' => []];
    // A site deployed with Git should be updated through Git, not behind its back
    if (bench_is_git_checkout()) {
        $s['auto'] = 'off';
    }
    return $s;
}

function bench_is_git_checkout(): bool
{
    return is_dir(BENCH_ROOT . '/.git');
}

function bench_update_log(): array
{
    return store_read('_updates/log', []);
}

function bench_update_log_add(string $event, string $message, array $extra = []): void
{
    $log = bench_update_log();
    array_unshift($log, ['time' => date('c'), 'event' => $event, 'message' => $message] + $extra);
    store_write('_updates/log', array_slice($log, 0, 30), false);
}

/** Downloads the release zip, checks it, and reads every file from it. Returns [list, files]. */
function bench_update_fetch_zip(array $m, string $zipFile): array
{
    $to = $m['version'];
    // 1. Download and check the package against the signed manifest
    [$code, $zipData] = bench_http_get($m['package'], max(1024, (int) $m['size'] + 1024), 120);
    if ($code !== 200) {
        throw new RuntimeException("The update package couldn't be downloaded (HTTP $code).");
    }
    if (strlen($zipData) !== $m['size'] || !hash_equals($m['sha256'], hash('sha256', $zipData))) {
        throw new RuntimeException('The downloaded package doesn\'t match the signed release, so it wasn\'t installed.');
    }
    file_put_contents($zipFile, $zipData);
    unset($zipData);

    // 2. Read every file and check it against the package's file list
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('The update package couldn\'t be opened.');
    }
    $list = json_decode((string) $zip->getFromName('core/bench-files.json'), true);
    if (!is_array($list['files'] ?? null) || ($list['version'] ?? '') !== $to) {
        throw new RuntimeException('The update package has no valid file list.');
    }
    $new = $list['files'];
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (substr($name, -1) === '/') {
            continue;
        }
        if ($name === 'core/bench-files.json') {
            continue;
        }
        if (!bench_update_path_ok($name) || !isset($new[$name])) {
            throw new RuntimeException('The update package contains an unexpected file: ' . $name);
        }
        $data = $zip->getFromIndex($i);
        if ($data === false || !hash_equals((string) $new[$name], hash('sha256', $data))) {
            throw new RuntimeException('A file in the update package is damaged: ' . $name);
        }
        $files[$name] = $data;
    }
    $zip->close();
    if (count($files) !== count($new)) {
        throw new RuntimeException('The update package is missing files.');
    }
    $files['core/bench-files.json'] = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return [$list, $files];
}

/**
 * The no-zip way to get a release: downloads the signed file list, then each file
 * that differs from what's installed, from the release channel's unpacked copy
 * ($m['files']). Every file is checked against the list, whose SHA-256 is in the
 * signed manifest. Files already identical are read from disk. Returns [list, files].
 */
function bench_update_fetch_files(array $m): array
{
    $base = (string) ($m['files'] ?? '');
    $listHash = (string) ($m['file_list_sha256'] ?? '');
    if (!preg_match('#^https?://#', $base) || !preg_match('/^[a-f0-9]{64}$/', $listHash)) {
        throw new RuntimeException("This release can only be installed with PHP's zip extension, which this server doesn't have. Upload it by FTP instead.");
    }
    $base = rtrim($base, '/');
    $url = fn(string $rel) => $base . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
    [$code, $json] = bench_http_get($url('core/bench-files.json'), 1024 * 1024, 30);
    if ($code !== 200) {
        throw new RuntimeException("The release's file list couldn't be downloaded (HTTP $code).");
    }
    if (!hash_equals($listHash, hash('sha256', $json))) {
        throw new RuntimeException('The downloaded file list doesn\'t match the signed release, so nothing was installed.');
    }
    $list = json_decode($json, true);
    if (!is_array($list['files'] ?? null) || ($list['version'] ?? '') !== $m['version']) {
        throw new RuntimeException('The release has no valid file list.');
    }
    $files = [];
    foreach ($list['files'] as $rel => $hash) {
        $rel = (string) $rel;
        if (!bench_update_path_ok($rel) || $rel === 'core/bench-files.json' || !preg_match('/^[a-f0-9]{64}$/', (string) $hash)) {
            throw new RuntimeException('The release lists an unexpected file: ' . $rel);
        }
        $target = BENCH_ROOT . '/' . $rel;
        if (is_file($target) && hash_equals($hash, (string) hash_file('sha256', $target))) {
            $files[$rel] = (string) file_get_contents($target); // already up to date
            continue;
        }
        [$code, $data] = bench_http_get($url($rel), 20 * 1024 * 1024, 60);
        if ($code !== 200) {
            throw new RuntimeException("$rel couldn't be downloaded (HTTP $code), so nothing was installed.");
        }
        if (!hash_equals($hash, hash('sha256', $data))) {
            throw new RuntimeException("$rel doesn't match the signed release, so nothing was installed.");
        }
        $files[$rel] = $data;
    }
    $files['core/bench-files.json'] = $json;
    return [$list, $files];
}

/** Things that would stop an update from working on this server. */
function bench_update_problems(bool $checkWritable = true): array
{
    $p = [];
    if (!bench_updates_enabled()) {
        $p[] = "Updates are turned off in config.php ('updates' => false).";
    }
    if (bench_update_key() === '' || strlen((string) base64_decode(bench_update_key(), true)) !== 32) {
        $p[] = "This copy of Bench has no release signing key, so it can't check updates are genuine.";
    }
    if (!function_exists('sodium_crypto_sign_verify_detached') && !is_file(BENCH_SODIUM_COMPAT . '/src/Core/Ed25519.php')) {
        $p[] = "PHP's sodium extension is missing and Bench's built-in replacement (core/vendor/sodium_compat) isn't there, so updates can't be checked. Upload Bench again by FTP.";
    }
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
        $p[] = 'PHP can\'t make web requests on this server (no curl, allow_url_fopen off), so it can\'t download updates.';
    }
    if ($checkWritable) {
        $bad = [];
        foreach (['', 'core', 'admin', 'assets', 'themes', 'plugins'] as $d) {
            $path = BENCH_ROOT . ($d === '' ? '' : '/' . $d);
            if (is_dir($path) && !is_writable($path)) {
                $bad[] = $d ?: 'the main Bench folder';
            }
        }
        if ($bad) {
            $p[] = 'PHP can\'t write to ' . implode(', ', $bad) . ', so it can\'t replace Bench\'s files. Updates can still be installed by uploading the new version by FTP.';
        }
    }
    return $p;
}

/* ------------------------------------------------------------------ *
 * Release channel
 * ------------------------------------------------------------------ */

/** GET a URL. https only (plain http allowed for localhost, for testing). Returns [status, body]. */
function bench_http_get(string $url, int $maxBytes = 50 * 1024 * 1024, int $timeout = 20): array
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)))) {
        throw new RuntimeException('Refusing to download from a non-HTTPS address: ' . $url);
    }
    $ua = 'Bench/' . BENCH_VERSION . ' (+updates)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new RuntimeException(strlen($body) > $maxBytes ? 'Download is larger than expected.' : 'Couldn\'t connect: ' . $err);
        }
        return [$status, $body];
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => $ua, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 5]]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
    if ($body === false) {
        throw new RuntimeException('Couldn\'t connect to ' . $host . '.');
    }
    if (strlen($body) > $maxBytes) {
        throw new RuntimeException('Download is larger than expected.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $hdr) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
            $status = (int) $m[1]; // last one wins (after redirects)
        }
    }
    return [$status, $body];
}

const BENCH_SODIUM_COMPAT = __DIR__ . '/vendor/sodium_compat';

/**
 * Ed25519 signature check. Uses PHP's sodium extension when it's there, otherwise
 * the bundled pure-PHP implementation from paragonie/sodium_compat (the same code
 * WordPress ships for this), which is slower but gives the same answer.
 */
function bench_ed25519_verify(string $sig, string $message, string $publicKey): bool
{
    if (strlen($sig) !== 64 || strlen($publicKey) !== 32) {
        return false;
    }
    if (function_exists('sodium_crypto_sign_verify_detached')) {
        return sodium_crypto_sign_verify_detached($sig, $message, $publicKey);
    }
    static $loaded = false;
    if (!$loaded) {
        spl_autoload_register(function (string $class): void {
            if (strpos($class, 'ParagonIE_Sodium_') !== 0) {
                return;
            }
            $f = BENCH_SODIUM_COMPAT . '/src/' . str_replace('_', '/', substr($class, 17)) . '.php';
            if (is_file($f)) {
                require_once $f;
            }
        });
        $loaded = true;
    }
    if (!class_exists('ParagonIE_Sodium_Core_Ed25519')) {
        throw new RuntimeException("PHP's sodium extension is missing and Bench's built-in replacement isn't there.");
    }
    try {
        return ParagonIE_Sodium_Core_Ed25519::verify_detached($sig, $message, $publicKey);
    } catch (Throwable $e) {
        return false; // malformed signature or key
    }
}

/** Checks a manifest's signature and shape. Returns the manifest or throws. */
function bench_update_verify_manifest(string $json, string $sigB64): array
{
    $key = base64_decode(bench_update_key(), true);
    $sig = base64_decode(trim($sigB64), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('No release signing key is set up in this copy of Bench.');
    }
    if ($sig === false || strlen($sig) !== 64 || !bench_ed25519_verify($sig, $json, $key)) {
        throw new RuntimeException('The update information isn\'t signed by the Bench release key, so it was ignored.');
    }
    $m = json_decode($json, true);
    if (!is_array($m)
        || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['version'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($m['sha256'] ?? ''))
        || !is_int($m['size'] ?? null)
        || !preg_match('#^https?://#', (string) ($m['package'] ?? ''))) {
        throw new RuntimeException('The update information is in an unexpected format.');
    }
    return $m;
}

/** Fetches the release channel at most every 12 hours (or now, with $force). */
function bench_update_check(bool $force = false): array
{
    $st = bench_update_status();
    if (!$force && time() - (int) $st['checked'] < BENCH_UPDATE_EVERY) {
        return $st;
    }
    $st['checked'] = time();
    try {
        if ($p = array_filter(bench_update_problems(false), fn($x) => strpos($x, 'zip') === false)) {
            throw new RuntimeException(reset($p));
        }
        $url = bench_update_url();
        [$code, $json] = bench_http_get($url, 256 * 1024, 10);
        if ($code !== 200) {
            throw new RuntimeException("The release channel answered with HTTP $code.");
        }
        [$code, $sig] = bench_http_get($url . '.sig', 4096, 10);
        if ($code !== 200) {
            throw new RuntimeException("The update signature couldn't be downloaded (HTTP $code).");
        }
        $st['latest'] = bench_update_verify_manifest($json, $sig);
        $st['latest_json'] = $json;
        $st['latest_sig'] = trim($sig);
        $st['error'] = null;
    } catch (Throwable $e) {
        $st['error'] = $e->getMessage();
    }
    store_write('_updates/status', $st, false);
    return $st;
}

/** The newer release this site can install, or null. Re-verifies the stored manifest. */
function bench_update_available(?array $st = null): ?array
{
    $st = $st ?? bench_update_status();
    if (empty($st['latest_json']) || empty($st['latest_sig'])) {
        return null;
    }
    try {
        $m = bench_update_verify_manifest($st['latest_json'], $st['latest_sig']);
    } catch (Throwable $e) {
        return null;
    }
    if (!version_compare($m['version'], BENCH_VERSION, '>')) {
        return null;
    }
    $m['blocked'] = '';
    if (!empty($m['requires_php']) && version_compare(PHP_VERSION, (string) $m['requires_php'], '<')) {
        $m['blocked'] = 'Bench ' . $m['version'] . ' needs PHP ' . $m['requires_php'] . ' or newer (this server has ' . PHP_VERSION . '). Ask your host to update PHP.';
    } elseif (!empty($m['requires_bench']) && version_compare(BENCH_VERSION, (string) $m['requires_bench'], '<')) {
        $m['blocked'] = 'Bench ' . $m['version'] . ' can only be installed over Bench ' . $m['requires_bench'] . ' or newer. Install that by FTP first.';
    }
    return $m;
}

/** A fix release: same major.minor, higher patch (1.5.0 → 1.5.3). */
function bench_update_is_patch(string $from, string $to): bool
{
    $a = explode('.', $from);
    $b = explode('.', $to);
    return count($a) === 3 && count($b) === 3 && $a[0] === $b[0] && $a[1] === $b[1] && version_compare($to, $from, '>');
}

/** The address this site is served from, remembered from admin visits (used for the post-update check). */
function bench_update_remember_site_url(): void
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $url = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . base_path();
    $st = bench_update_status();
    if (($st['site_url'] ?? '') !== $url && preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+(/[A-Za-z0-9._~\-/]*)?$#', $url)) {
        $st['site_url'] = $url;
        store_write('_updates/status', $st, false);
    }
}

/**
 * Runs after a public page has been sent (only on servers that can finish the
 * response early, so visitors never wait): checks for updates and installs fix
 * releases if automatic updates are on.
 */
function bench_updates_tick(): void
{
    if (!bench_updates_enabled() || time() - (int) bench_update_status()['checked'] < BENCH_UPDATE_EVERY) {
        return;
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        return;
    }
    try {
        bench_update_auto(bench_update_check());
    } catch (Throwable $e) {
        // never break the site over an update check
    }
}

/** Installs the available release if it's a fix release and automatic updates are on. */
function bench_update_auto(array $st): ?array
{
    $m = bench_update_available($st);
    $set = bench_update_settings();
    if (!$m || $m['blocked'] || $set['auto'] !== 'patch' || !bench_update_is_patch(BENCH_VERSION, $m['version'])
        || in_array($m['version'], (array) $set['skip'], true) || !empty($m['manual_only']) || bench_update_problems()) {
        return null;
    }
    return bench_update_install($m, 'automatic');
}

/* ------------------------------------------------------------------ *
 * Installing
 * ------------------------------------------------------------------ */

/** Paths an update may write. Never content/, uploads/, custom/ or config.php. */
function bench_update_path_ok(string $rel): bool
{
    if ($rel === '' || $rel[0] === '/' || strpos($rel, '\\') !== false || strpos($rel, "\0") !== false
        || !preg_match('#^[A-Za-z0-9._\-/ ]+$#', $rel)) {
        return false;
    }
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..' || ($seg[0] === '.' && $rel !== '.htaccess')) {
            return false;
        }
    }
    return !preg_match('#^(content|uploads|custom)(/|$)#', $rel) && $rel !== 'config.php';
}

/** The file list (path => sha256) of the installed release, if it was installed from a package. */
function bench_installed_files(): array
{
    $f = BENCH_CORE . '/bench-files.json';
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($j['files'] ?? null) ? $j['files'] : [];
}

function bench_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Writes a file atomically (temp file + rename). */
function bench_put_file(string $target, string $data): void
{
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Couldn\'t create folder ' . bench_rel($dir));
    }
    $tmp = $target . '.bench-' . bin2hex(random_bytes(3)) . '.tmp';
    if (@file_put_contents($tmp, $data) !== strlen($data)) {
        @unlink($tmp);
        throw new RuntimeException('Couldn\'t write ' . bench_rel($target));
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Couldn\'t replace ' . bench_rel($target));
    }
    if (function_exists('opcache_invalidate') && substr($target, -4) === '.php') {
        @opcache_invalidate($target, true);
    }
}

function bench_rel(string $path): string
{
    return ltrim(substr($path, strlen(BENCH_ROOT)), '/') ?: '.';
}

function bench_maintenance(bool $on): void
{
    $f = bench_updates_dir() . '/maintenance';
    $on ? @touch($f) : @unlink($f);
}

/** Called first thing by index.php: a short "back in a minute" while files are swapped. */
function bench_maintenance_check(): void
{
    $f = BENCH_CONTENT . '/_updates/maintenance';
    if (is_file($f) && time() - (int) filemtime($f) < 300) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Back in a moment</title>'
            . '<main style="font:18px/1.6 system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 16px;color:#1a1a1a">'
            . '<h1>Back in a moment</h1><p>This website is being updated. Please try again in a minute.</p></main></html>');
    }
}

/**
 * Downloads, verifies and installs a release. $m is a verified manifest from
 * bench_update_available(). Returns ['ok' => bool, 'message' => string, ...].
 */
function bench_update_install(array $m, string $by = 'admin'): array
{
    @set_time_limit(300);
    ignore_user_abort(true);
    $dir = bench_updates_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $lock = fopen($dir . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ok' => false, 'message' => 'Another update is already running. Try again in a few minutes.'];
    }
    $from = BENCH_VERSION;
    $to = $m['version'];
    $zipFile = $dir . '/download.zip';
    $backup = null;
    $applied = false;
    try {
        if ($p = bench_update_problems()) {
            throw new RuntimeException(implode(' ', $p));
        }
        if (!empty($m['blocked'])) {
            throw new RuntimeException($m['blocked']);
        }

        // 1–2. Get the release's files and check every one against the signed manifest
        [$list, $files] = class_exists('ZipArchive')
            ? bench_update_fetch_zip($m, $zipFile)
            : bench_update_fetch_files($m); // no zip extension: changed files one by one
        $new = $list['files'];

        // 3. Work out what changes: modified files, a customised .htaccess, files to remove
        $old = bench_installed_files();
        $plan = ['write' => [], 'delete' => [], 'modified' => [], 'kept' => [], 'notes' => []];
        foreach ($files as $rel => $data) {
            $target = BENCH_ROOT . '/' . $rel;
            $cur = is_file($target) ? hash_file('sha256', $target) : null;
            if ($cur !== null && $cur === hash('sha256', $data)) {
                continue; // already identical
            }
            $changedLocally = $cur !== null && isset($old[$rel]) && $cur !== $old[$rel];
            if ($rel === '.htaccess' && $cur !== null && (!isset($old[$rel]) || $changedLocally)) {
                $plan['write']['.htaccess.new'] = $data;
                $plan['notes'][] = 'Your .htaccess has been customised, so it was kept. The new version is saved next to it as .htaccess.new — compare the two and copy across any new rules.';
                continue;
            }
            if ($changedLocally && $rel !== 'core/bench-files.json') {
                $plan['modified'][] = $rel;
            }
            $plan['write'][$rel] = $data;
        }
        foreach ($old as $rel => $hash) {
            if (!isset($files[$rel]) && bench_update_path_ok($rel) && is_file(BENCH_ROOT . '/' . $rel)) {
                if (hash_file('sha256', BENCH_ROOT . '/' . $rel) === $hash) {
                    $plan['delete'][] = $rel;
                } else {
                    $plan['kept'][] = $rel;
                }
            }
        }

        // 4. Make sure everything can be written before touching anything
        $cant = [];
        foreach (array_merge(array_keys($plan['write']), $plan['delete']) as $rel) {
            $t = BENCH_ROOT . '/' . $rel;
            $d = dirname($t);
            while (!is_dir($d)) {
                $d = dirname($d);
            }
            if ((is_file($t) && !is_writable($t)) || !is_writable($d)) {
                $cant[] = $rel;
            }
        }
        if ($cant) {
            throw new RuntimeException('PHP can\'t write to these files, so nothing was changed: ' . implode(', ', array_slice($cant, 0, 8)) . (count($cant) > 8 ? ' …' : ''));
        }

        // 5. Back up what's about to change
        $backup = $dir . '/backups/' . $from . '-' . date('Ymd-His');
        $record = ['from' => $from, 'to' => $to, 'time' => date('c'), 'saved' => [], 'added' => []];
        foreach (array_merge(array_keys($plan['write']), $plan['delete']) as $rel) {
            $t = BENCH_ROOT . '/' . $rel;
            if (is_file($t)) {
                $dest = $backup . '/files/' . $rel;
                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0755, true);
                }
                if (!copy($t, $dest)) {
                    throw new RuntimeException('Couldn\'t back up ' . $rel . ', so nothing was changed.');
                }
                $record['saved'][] = $rel;
            } else {
                $record['added'][] = $rel;
            }
        }
        bench_put_file($backup . '/backup.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 6. Swap the files in (core/bench-files.json last, so a failure part-way is rolled back cleanly)
        bench_maintenance(true);
        $applied = true;
        $listJson = $plan['write']['core/bench-files.json'] ?? null;
        unset($plan['write']['core/bench-files.json']);
        foreach ($plan['write'] as $rel => $data) {
            bench_put_file(BENCH_ROOT . '/' . $rel, $data);
        }
        foreach ($plan['delete'] as $rel) {
            @unlink(BENCH_ROOT . '/' . $rel);
        }
        if ($listJson !== null) {
            bench_put_file(BENCH_CORE . '/bench-files.json', $listJson);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        bench_maintenance(false);

        // 7. Check the site still works; if not, put the old version back
        $health = bench_update_health();
        if ($health['ok'] === false) {
            bench_update_restore($backup);
            bench_rrmdir($backup);
            bench_update_skip($to);
            $msg = "Bench $to was installed but the site stopped working ({$health['detail']}), so Bench $from was put back automatically. Nothing else changed.";
            bench_update_log_add('rolled-back', $msg, ['by' => $by, 'from' => $from, 'to' => $to]);
            return ['ok' => false, 'message' => $msg, 'rolled_back' => true];
        }

        $notes = $plan['notes'];
        if ($plan['modified']) {
            $notes[] = 'These Bench files had been edited on this server and were replaced (your versions are in the update backup — use custom/ for changes that should survive updates): ' . implode(', ', $plan['modified']);
        }
        if ($plan['kept']) {
            $notes[] = 'These files are no longer part of Bench but had been edited, so they were left in place: ' . implode(', ', $plan['kept']);
        }
        if ($health['ok'] === null) {
            $notes[] = 'Bench couldn\'t load the site to check it after updating (' . $health['detail'] . '). Have a quick look at the website to make sure it\'s fine.';
        }
        $msg = "Updated Bench from $from to $to.";
        bench_update_log_add('updated', $msg, ['by' => $by, 'from' => $from, 'to' => $to, 'notes' => $notes, 'changed' => count($plan['write']) + count($plan['delete'])]);
        bench_update_prune();
        return ['ok' => true, 'message' => $msg, 'notes' => $notes, 'from' => $from, 'to' => $to];
    } catch (Throwable $e) {
        if ($applied && $backup) {
            bench_update_restore($backup);
        }
        if ($backup) {
            bench_rrmdir($backup);
        }
        bench_maintenance(false);
        $msg = 'Update to Bench ' . $to . ' failed: ' . $e->getMessage() . ($applied ? ' The previous version was put back.' : '');
        bench_update_log_add('failed', $msg, ['by' => $by, 'from' => $from, 'to' => $to]);
        return ['ok' => false, 'message' => $msg];
    } finally {
        @unlink($zipFile);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Loads the home page and the admin login after an update.
 * ok: true = fine, false = broken (roll back), null = couldn't check.
 */
function bench_update_health(): array
{
    $base = rtrim((string) (cfg('update_site_url') ?: bench_update_status()['site_url']), '/');
    if ($base === '') {
        return ['ok' => null, 'detail' => 'the site\'s address isn\'t known yet'];
    }
    foreach (['/' => '</html>', '/admin/' => 'Bench'] as $path => $marker) {
        try {
            $url = $base . $path . '?bench-health=' . time();
            [$code, $body] = bench_http_get_loopback($url);
        } catch (Throwable $e) {
            return ['ok' => null, 'detail' => $e->getMessage()];
        }
        if ($code >= 500 || ($code === 200 && strpos($body, $marker) === false) || preg_match('#<b>(Fatal|Parse) error</b>|PHP (Fatal|Parse) error#', $body)) {
            return ['ok' => false, 'detail' => ($path === '/' ? 'home page' : 'admin') . " returned HTTP $code"];
        }
    }
    return ['ok' => true, 'detail' => ''];
}

/** Like bench_http_get, but for the site itself (allows http and self-signed certificates). */
function bench_http_get_loopback(string $url): array
{
    if (!preg_match('#^https?://#', $url)) {
        throw new RuntimeException('bad address');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Bench/' . BENCH_VERSION . ' (+health-check)']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code === 0) {
            throw new RuntimeException('couldn\'t connect to the site: ' . $err);
        }
        return [$code, (string) $body];
    }
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('couldn\'t connect to the site');
    }
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $mm)) {
            $code = (int) $mm[1];
        }
    }
    return [$code, $body];
}

/** Puts back the files saved in an update backup. */
function bench_update_restore(string $backup): bool
{
    $rec = json_decode((string) @file_get_contents($backup . '/backup.json'), true);
    if (!is_array($rec)) {
        return false;
    }
    bench_maintenance(true);
    foreach ((array) $rec['saved'] as $rel) {
        if (bench_update_path_ok($rel) || $rel === '.htaccess.new') {
            $src = $backup . '/files/' . $rel;
            if (is_file($src)) {
                bench_put_file(BENCH_ROOT . '/' . $rel, (string) file_get_contents($src));
            }
        }
    }
    foreach ((array) $rec['added'] as $rel) {
        if (bench_update_path_ok($rel) || $rel === '.htaccess.new') {
            @unlink(BENCH_ROOT . '/' . $rel);
        }
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    bench_maintenance(false);
    return true;
}

/** Don't auto-install this version again (after a rollback). It can still be installed by hand. */
function bench_update_skip(string $version): void
{
    $s = store_read('_updates/settings', []) + ['auto' => 'patch', 'skip' => []];
    $s['skip'] = array_values(array_unique(array_merge((array) $s['skip'], [$version])));
    store_write('_updates/settings', $s, false);
}

/** Update backups, newest first: [id, from, to, time]. */
function bench_update_backups(): array
{
    $out = [];
    foreach (glob(bench_updates_dir() . '/backups/*/backup.json') ?: [] as $f) {
        $r = json_decode((string) file_get_contents($f), true);
        if (is_array($r)) {
            $out[] = ['id' => basename(dirname($f)), 'from' => $r['from'] ?? '?', 'to' => $r['to'] ?? '?', 'time' => $r['time'] ?? ''];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['time'], $a['time']));
    return $out;
}

function bench_update_prune(): void
{
    foreach (array_slice(bench_update_backups(), BENCH_UPDATE_BACKUPS) as $b) {
        bench_rrmdir(bench_updates_dir() . '/backups/' . $b['id']);
    }
}

/** Admin → Updates → "Go back to …": restores the most recent update backup. */
function bench_update_rollback(string $id): array
{
    $all = bench_update_backups();
    if (!$all || $all[0]['id'] !== $id || !preg_match('/^[0-9.]+-\d{8}-\d{6}$/', $id)) {
        return ['ok' => false, 'message' => 'Only the most recent update can be undone.'];
    }
    $b = $all[0];
    if ($b['to'] !== BENCH_VERSION) {
        return ['ok' => false, 'message' => "That backup is from the update to {$b['to']}, but this site is on " . BENCH_VERSION . '.'];
    }
    $dir = bench_updates_dir() . '/backups/' . $id;
    if (!bench_update_restore($dir)) {
        return ['ok' => false, 'message' => 'The backup couldn\'t be read.'];
    }
    bench_update_skip($b['to']);
    bench_rrmdir($dir);
    $msg = "Went back from Bench {$b['to']} to {$b['from']}. Automatic updates will skip {$b['to']}.";
    bench_update_log_add('rolled-back', $msg, ['by' => 'admin', 'from' => $b['to'], 'to' => $b['from']]);
    return ['ok' => true, 'message' => $msg];
}

/** Everything Admin → Updates shows. */
function bench_updates_view_data(): array
{
    $st = bench_update_status();
    $m = bench_updates_enabled() ? bench_update_available($st) : null;
    $set = bench_update_settings();
    $backups = bench_update_backups();
    $last = $backups[0] ?? null;
    return [
        'version'   => BENCH_VERSION,
        'enabled'   => bench_updates_enabled(),
        'problems'  => bench_update_problems(),
        'git'       => bench_is_git_checkout(),
        'checked'   => (int) $st['checked'],
        'error'     => $st['error'],
        'available' => $m ? [
            'version'  => $m['version'],
            'released' => $m['released'] ?? '',
            'notes'    => (string) ($m['notes'] ?? ''),
            'blocked'  => $m['blocked'],
            'patch'    => bench_update_is_patch(BENCH_VERSION, $m['version']),
            'skipped'  => in_array($m['version'], (array) $set['skip'], true),
        ] : null,
        'auto'      => $set['auto'],
        'log'       => array_slice(bench_update_log(), 0, 10),
        'undo'      => $last && $last['to'] === BENCH_VERSION ? $last : null,
    ];
}
