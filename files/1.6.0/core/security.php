<?php
/**
 * Bench CMS — security checks shown in Admin → Security.
 *
 * Bench can't patch PHP itself, but it can tell the site owner when the server
 * is running a PHP version with known critical bugs, when risky settings are on,
 * and when PHP files turn up where they shouldn't (a sign of a web shell).
 *
 * To add a new advisory, add an entry to bench_php_advisories(): the minimum
 * fixed version for each supported branch, plus the oldest supported branch.
 */

function bench_php_advisories(): array
{
    return [
        'php2shell' => [
            'name'     => 'PHP2Shell (CVE-2026-109618, CVE-2026-109619)',
            'date'     => '2026-10-21',
            'severity' => 'Critical — remote code execution, no login needed',
            'fixed'    => ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'],
            'url'      => 'https://www.php.net/archive/2026.php',
        ],
    ];
}

/** Oldest PHP branch that still gets security fixes from the PHP project. */
const BENCH_PHP_OLDEST_SUPPORTED = '8.2';

/** Version without distro suffixes: "8.3.6-1ubuntu" → "8.3.6" */
function bench_php_version(): string
{
    return preg_replace('/[^0-9.].*$/', '', PHP_VERSION);
}

/**
 * Each finding: ['id', 'level' => critical|warn|ok, 'title', 'detail', 'ack' => bool]
 * Admins can acknowledge an advisory (after the host confirms a backported fix)
 * by listing its id in config.php → 'security_acknowledged'.
 */
function bench_php_findings(): array
{
    $v = bench_php_version();
    $branch = implode('.', array_slice(explode('.', $v), 0, 2));
    $acked = (array) cfg('security_acknowledged', []);
    $out = [];

    if (version_compare($branch, BENCH_PHP_OLDEST_SUPPORTED, '<')) {
        $out[] = [
            'id' => 'php-eol', 'level' => 'critical', 'ack' => in_array('php-eol', $acked, true),
            'title' => "PHP $v is no longer supported",
            'detail' => "PHP $branch gets no security fixes from the PHP project, including for PHP2Shell. Ask your host to move the site to PHP 8.3 or newer — Bench works on 8.0 and up.",
        ];
    }
    foreach (bench_php_advisories() as $id => $a) {
        $fixed = $a['fixed'][$branch] ?? null;
        if ($fixed === null || version_compare($v, $fixed, '>=')) {
            continue;
        }
        $out[] = [
            'id' => $id, 'level' => 'critical', 'ack' => in_array($id, $acked, true),
            'title' => "PHP $v is older than the fix for " . $a['name'],
            'detail' => $a['severity'] . ". Fixed in PHP $fixed ({$a['date']}). Ask your host to update PHP and restart it. "
                . "Some Linux distributions and control panels (Ubuntu, Debian, AlmaLinux/RHEL, CloudLinux) backport fixes without changing the version number — "
                . "if your host confirms the fix is installed, add '$id' to 'security_acknowledged' in config.php to hide this warning.",
            'url' => $a['url'],
        ];
    }
    return $out;
}

/** Cheap check used for the admin-wide banner. */
function bench_security_alert(): ?array
{
    foreach (bench_php_findings() as $f) {
        if ($f['level'] === 'critical' && !$f['ack']) {
            return $f;
        }
    }
    return null;
}

/** Full report for Admin → Security (scans files, so only run on that screen). */
function bench_security_report(): array
{
    $items = bench_php_findings();
    if (!array_filter($items, fn($f) => !$f['ack'])) {
        $items[] = ['id' => 'php-ok', 'level' => 'ok', 'title' => 'PHP ' . bench_php_version() . ' has the known security fixes',
            'detail' => 'Checked against the advisories Bench knows about. Keep Bench updated so this list stays current.'];
    }

    $sapi = PHP_SAPI;
    $sapiName = ['apache2handler' => 'Apache module (mod_php)', 'fpm-fcgi' => 'PHP-FPM', 'cgi-fcgi' => 'FastCGI', 'litespeed' => 'LiteSpeed', 'frankenphp' => 'FrankenPHP', 'cli-server' => 'PHP built-in server (development only)'][$sapi] ?? $sapi;
    $items[] = ['id' => 'sapi', 'level' => 'info', 'title' => 'PHP runs as: ' . $sapiName,
        'detail' => $sapi === 'apache2handler'
            ? 'This setup was the most exposed to PHP2Shell. Bench\'s .htaccess applies safer PHP settings automatically here.'
            : 'PHP settings in .htaccess don\'t apply to this setup, so safer settings need to go in php.ini or the host\'s PHP options (see README → Security).'];

    $hard = getenv('BENCH_HARDENING') ?: ($_SERVER['BENCH_HARDENING'] ?? $_SERVER['REDIRECT_BENCH_HARDENING'] ?? '');
    $items[] = $hard
        ? ['id' => 'htaccess', 'level' => 'ok', 'title' => 'Server rules from .htaccess are active',
            'detail' => 'Public pages refuse form posts and uploads, so PHP never has to read incoming data outside the admin. Internal folders are blocked.']
        : ['id' => 'htaccess', 'level' => 'warn', 'title' => 'Bench\'s .htaccess rules don\'t seem to be active',
            'detail' => 'They block posts and uploads to public pages and protect internal folders. The .htaccess file may not have been uploaded (it\'s hidden on most computers), the host may ignore it, or the server runs nginx — see README → Security for the equivalent rules.'];

    $ini = [
        ['session.upload_progress.enabled', false, 'Session upload progress is off', 'Session upload progress is on', 'Bench doesn\'t use it, and it was one route into PHP2Shell. Turn it off in php.ini or the host\'s PHP options (.user.ini and ini_set() are too late).'],
        ['display_errors', false, 'Errors are hidden from visitors', 'PHP shows errors to visitors', 'Error messages can reveal file paths and versions. Turn display_errors off on live sites.'],
        ['allow_url_include', false, 'Remote file includes are off', 'allow_url_include is on', 'This lets PHP run code from other websites. It should always be off.'],
        ['expose_php', false, 'PHP doesn\'t announce its version', 'PHP announces its version in headers', 'Mass scanners use this to find unpatched servers. Turn expose_php off in php.ini (Bench also removes the header where it can).'],
    ];
    foreach ($ini as [$key, $want, $good, $bad, $why]) {
        $on = filter_var(ini_get($key), FILTER_VALIDATE_BOOLEAN);
        $items[] = $on === $want
            ? ['id' => $key, 'level' => 'ok', 'title' => $good, 'detail' => "$key = " . ($on ? 'On' : 'Off')]
            : ['id' => $key, 'level' => $key === 'expose_php' ? 'info' : 'warn', 'title' => $bad, 'detail' => $why];
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $items[] = $https
        ? ['id' => 'https', 'level' => 'ok', 'title' => 'The admin is using HTTPS', 'detail' => 'Logins and session cookies are encrypted.']
        : ['id' => 'https', 'level' => 'warn', 'title' => 'The admin isn\'t using HTTPS', 'detail' => 'Passwords and the login cookie travel unencrypted. Turn on SSL with the host (usually free) and use https:// links.'];

    $suspects = bench_suspicious_files();
    $items[] = $suspects
        ? ['id' => 'files', 'level' => 'critical', 'title' => count($suspects) . ' unexpected PHP file' . (count($suspects) === 1 ? '' : 's') . ' found',
            'detail' => 'PHP files in places Bench never puts them are often web shells left by an attacker. Check these with your host before deleting anything, and change the admin password afterwards.',
            'files' => $suspects]
        : ['id' => 'files', 'level' => 'ok', 'title' => 'No unexpected PHP files found',
            'detail' => 'Checked the site folder, uploads/ and content/ for PHP files that Bench didn\'t put there.'];

    $writable = array_values(array_filter(['core', 'admin', 'index.php', 'themes', 'plugins'], fn($p) => is_writable(BENCH_ROOT . '/' . $p)));
    $items[] = $writable
        ? ['id' => 'perms', 'level' => 'info', 'title' => 'Code files can be changed by PHP',
            'detail' => 'Only content/ and uploads/ need to be writable. If your host allows it, make the rest read-only for the web server (common on shared hosting, so not an error): ' . implode(', ', $writable) . '.']
        : ['id' => 'perms', 'level' => 'ok', 'title' => 'Code files are read-only for PHP', 'detail' => 'An attacker who gets in through PHP can\'t easily alter Bench\'s code.'];

    return $items;
}

/**
 * PHP files where Bench never creates them: extra PHP in the site root, any
 * script in uploads/, and content files missing Bench's guard line.
 */
function bench_suspicious_files(int $max = 50): array
{
    $found = [];
    $expectedRoot = ['index.php', 'config.php', 'router.php'];
    foreach (glob(BENCH_ROOT . '/*.{php,phtml,phar,php5,php7,php8,inc}', GLOB_BRACE) ?: [] as $f) {
        if (!in_array(basename($f), $expectedRoot, true)) {
            $found[] = basename($f);
        }
    }
    $scan = function (string $dir, callable $test) use (&$found, $max) {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (count($found) >= $max) {
                return;
            }
            if ($f->isFile() && $test($f)) {
                $found[] = substr($f->getPathname(), strlen(BENCH_ROOT) + 1);
            }
        }
    };
    $scriptExt = '/\.(php\d?|phtml|phar|inc|cgi|pl|py|sh|asp|aspx|jsp|htaccess|user\.ini)$/i';
    $scan(BENCH_UPLOADS, fn($f) => preg_match($scriptExt, $f->getFilename())
        && !($f->getFilename() === '.htaccess' && dirname($f->getPathname()) === BENCH_UPLOADS));
    $scan(BENCH_CONTENT, function ($f) {
        $name = $f->getFilename();
        if ($name === '.htaccess' && dirname($f->getPathname()) === BENCH_CONTENT) {
            return false;
        }
        if (preg_match('/\.(phtml|phar|php\d|inc|htaccess|user\.ini)$/i', $name)) {
            return true;
        }
        if (substr($name, -4) !== '.php') {
            return false;
        }
        $fh = @fopen($f->getPathname(), 'rb');
        $head = $fh ? (string) fread($fh, strlen(BENCH_GUARD)) : '';
        $fh && fclose($fh);
        return $head !== BENCH_GUARD;
    });
    return $found;
}
