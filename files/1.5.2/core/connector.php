<?php
/**
 * Bench CMS — the Claude connector (Admin → Connections)
 *
 * Lets Claude edit this site from a chat: pages, sections, collection items
 * (vehicles…), images and site settings. It's an MCP server (admin/mcp.php) with
 * OAuth sign-in (admin/oauth.php), so it can be added to Claude as a custom
 * connector. It's off until switched on in Admin → Connections.
 *
 * Signing in uses a Bench admin login and an "Allow Claude?" page. Claude then
 * gets a short-lived access token (1 hour) and a refresh token (30 days, single
 * use). Tokens are stored hashed. Turning the connector off, disconnecting, or
 * removing the user from content/_auth/users.php stops access at once.
 *
 * Everything Claude changes goes through core/content-ops.php — the same code as
 * the admin — so it's validated, backed up and can be undone, and each change is
 * listed in Admin → Connections.
 *
 * config.php options:
 *   'connector_redirect_hosts' => ['claude.ai', 'claude.com']  apps allowed to connect
 *                                  (plus localhost, for Claude Code / Desktop)
 *   'connector_allow_code'     => false   let Claude edit "code" settings (<head> code,
 *                                  form embed code). Off, as those can run scripts.
 *   'site_url' => 'https://example.co.uk'  if the site's address can't be detected
 */

const CONNECTOR_ACCESS_TTL = 3600;          // 1 hour
const CONNECTOR_REFRESH_TTL = 30 * 86400;   // 30 days
const CONNECTOR_CODE_TTL = 300;             // 5 minutes
const CONNECTOR_MAX_CLIENTS = 50;
const CONNECTOR_PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26'];

/* ------------------------------------------------------------------ *
 * State (content/_auth/connector.php) — always changed under a lock
 * ------------------------------------------------------------------ */

function connector_state(): array
{
    return store_read('_auth/connector', []) + ['enabled' => false, 'clients' => [], 'codes' => [], 'tokens' => []];
}

/** Read-modify-write the connector state under an exclusive lock. $fn gets the state by reference and may return a value. */
function connector_update(callable $fn)
{
    $dir = BENCH_CONTENT . '/_auth';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $lock = fopen($dir . '/connector.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $s = connector_state();
        $now = time();
        // Drop anything expired while we're here
        $s['codes'] = array_filter($s['codes'], fn($c) => $c['expires'] > $now);
        $s['tokens'] = array_filter($s['tokens'], fn($t) => $t['expires'] > $now);
        try {
            $result = $fn($s);
        } catch (BenchOpError $e) {
            store_write('_auth/connector', $s, false); // keep e.g. a used-up sign-in code gone
            throw $e;
        }
        store_write('_auth/connector', $s, false);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function connector_enabled(): bool
{
    return !empty(connector_state()['enabled']);
}

/* ------------------------------------------------------------------ *
 * Addresses
 * ------------------------------------------------------------------ */

/** The site's own address, e.g. https://example.co.uk (no trailing slash). */
function connector_base(): string
{
    $cfg = rtrim((string) cfg('site_url', ''), '/');
    if ($cfg !== '') {
        return $cfg;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strpos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"') !== false;
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$|^\[[0-9a-fA-F:]+\](:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https://' : 'http://') . $host . base_path();
}

function connector_mcp_url(): string
{
    return connector_base() . '/admin/mcp.php';
}

function connector_oauth_url(string $endpoint): string
{
    return connector_base() . '/admin/oauth.php/' . $endpoint;
}

/** OAuth authorization server metadata (RFC 8414). */
function connector_as_metadata(): array
{
    return [
        'issuer' => connector_base(),
        'authorization_endpoint' => connector_oauth_url('authorize'),
        'token_endpoint' => connector_oauth_url('token'),
        'registration_endpoint' => connector_oauth_url('register'),
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'scopes_supported' => ['bench'],
        'service_documentation' => 'https://github.com/LobsterDM/bench#claude-connector',
    ];
}

/** OAuth protected resource metadata (RFC 9728) for the MCP endpoint. */
function connector_pr_metadata(): array
{
    return [
        'resource' => connector_mcp_url(),
        'authorization_servers' => [connector_base()],
        'scopes_supported' => ['bench'],
        'bearer_methods_supported' => ['header'],
        'resource_name' => (site()['business_name'] ?? 'Bench site') . ' (Bench)',
    ];
}

/* ------------------------------------------------------------------ *
 * Clients (dynamic registration), codes and tokens
 * ------------------------------------------------------------------ */

/** Only Claude's own addresses (and localhost, for apps on your computer) may receive sign-in codes. */
function connector_redirect_ok(string $uri): bool
{
    $p = parse_url($uri);
    if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['fragment'])) {
        return false;
    }
    $host = strtolower($p['host']);
    if (in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
        return in_array($p['scheme'], ['http', 'https'], true);
    }
    if ($p['scheme'] !== 'https') {
        return false;
    }
    foreach ((array) cfg('connector_redirect_hosts', ['claude.ai', 'claude.com']) as $allowed) {
        $allowed = strtolower((string) $allowed);
        if ($host === $allowed || substr($host, -strlen('.' . $allowed)) === '.' . $allowed) {
            return true;
        }
    }
    return false;
}

/** Registers an app (RFC 7591). Returns the client record, or throws BenchOpError. */
function connector_register(array $req): array
{
    if (!connector_enabled()) {
        throw new BenchOpError('Claude access is turned off for this site. Turn it on in Admin → Connections.', 403);
    }
    $uris = array_values(array_filter((array) ($req['redirect_uris'] ?? []), 'is_string'));
    if (!$uris || count($uris) > 5) {
        throw new BenchOpError('redirect_uris is required.', 400);
    }
    foreach ($uris as $u) {
        if (!connector_redirect_ok($u)) {
            throw new BenchOpError('This site only accepts connections from Claude. Redirect address not allowed: ' . $u, 400);
        }
    }
    $name = mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', (string) ($req['client_name'] ?? 'Claude'))), 0, 80) ?: 'Claude';
    $id = 'bench_' . bin2hex(random_bytes(16));
    $client = ['name' => $name, 'redirect_uris' => $uris, 'created' => time(), 'last_used' => 0];
    connector_update(function (&$s) use ($id, $client) {
        // Keep the list bounded: forget apps that never finished connecting
        $inUse = array_column($s['tokens'], 'client');
        foreach ($s['clients'] as $cid => $c) {
            if (!in_array($cid, $inUse, true) && $c['created'] < time() - 86400) {
                unset($s['clients'][$cid]);
            }
        }
        if (count($s['clients']) >= CONNECTOR_MAX_CLIENTS) {
            throw new BenchOpError('Too many apps are registered. Disconnect some in Admin → Connections.', 400);
        }
        $s['clients'][$id] = $client;
    });
    return ['client_id' => $id, 'client_id_issued_at' => $client['created'], 'client_name' => $name, 'redirect_uris' => $uris,
        'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none'];
}

function connector_client(string $id): ?array
{
    return connector_state()['clients'][$id] ?? null;
}

/** Issues a one-time sign-in code after the person clicks Allow. */
function connector_issue_code(string $clientId, string $user, string $redirect, string $challenge, string $resource): string
{
    $code = bin2hex(random_bytes(32));
    connector_update(function (&$s) use ($code, $clientId, $user, $redirect, $challenge, $resource) {
        $s['codes'][hash('sha256', $code)] = ['client' => $clientId, 'user' => $user, 'redirect' => $redirect,
            'challenge' => $challenge, 'resource' => $resource, 'expires' => time() + CONNECTOR_CODE_TTL];
    });
    return $code;
}

/** Makes a new access + refresh token pair (inside connector_update). */
function connector_new_tokens(array &$s, string $clientId, string $user): array
{
    $access = bin2hex(random_bytes(32));
    $refresh = bin2hex(random_bytes(32));
    $now = time();
    $s['tokens'][hash('sha256', $access)] = ['type' => 'access', 'client' => $clientId, 'user' => $user, 'expires' => $now + CONNECTOR_ACCESS_TTL, 'created' => $now];
    $s['tokens'][hash('sha256', $refresh)] = ['type' => 'refresh', 'client' => $clientId, 'user' => $user, 'expires' => $now + CONNECTOR_REFRESH_TTL, 'created' => $now];
    if (isset($s['clients'][$clientId])) {
        $s['clients'][$clientId]['last_used'] = $now;
        $s['clients'][$clientId]['user'] = $user;
    }
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => CONNECTOR_ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => 'bench'];
}

/** The token endpoint. $p is the form POST. Returns the token response or throws BenchOpError (message = OAuth error code). */
function connector_token(array $p): array
{
    if (!connector_enabled()) {
        throw new BenchOpError('access_denied', 400);
    }
    $grant = (string) ($p['grant_type'] ?? '');
    $clientId = (string) ($p['client_id'] ?? '');
    return connector_update(function (&$s) use ($grant, $clientId, $p) {
        if (!isset($s['clients'][$clientId])) {
            throw new BenchOpError('invalid_client', 401);
        }
        if ($grant === 'authorization_code') {
            $key = hash('sha256', (string) ($p['code'] ?? ''));
            $c = $s['codes'][$key] ?? null;
            unset($s['codes'][$key]); // single use, whatever happens next
            $verifier = (string) ($p['code_verifier'] ?? '');
            if (!$c || $c['client'] !== $clientId || $c['redirect'] !== (string) ($p['redirect_uri'] ?? $c['redirect'])
                || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)
                || !hash_equals($c['challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
                throw new BenchOpError('invalid_grant', 400);
            }
            if (!isset(store_read('_auth/users', [])[$c['user']])) {
                throw new BenchOpError('invalid_grant', 400);
            }
            return connector_new_tokens($s, $clientId, $c['user']);
        }
        if ($grant === 'refresh_token') {
            $key = hash('sha256', (string) ($p['refresh_token'] ?? ''));
            $t = $s['tokens'][$key] ?? null;
            unset($s['tokens'][$key]); // refresh tokens are single use (rotated)
            if (!$t || $t['type'] !== 'refresh' || $t['client'] !== $clientId || !isset(store_read('_auth/users', [])[$t['user']])) {
                throw new BenchOpError('invalid_grant', 400);
            }
            return connector_new_tokens($s, $clientId, $t['user']);
        }
        throw new BenchOpError('unsupported_grant_type', 400);
    });
}

/** Checks a Bearer access token. Returns ['user', 'client', 'client_name'] or null. */
function connector_check_token(string $token): ?array
{
    if ($token === '' || !connector_enabled()) {
        return null;
    }
    $s = connector_state();
    $key = hash('sha256', $token);
    $t = $s['tokens'][$key] ?? null;
    if (!$t || $t['type'] !== 'access' || $t['expires'] <= time() || !isset($s['clients'][$t['client']])
        || !isset(store_read('_auth/users', [])[$t['user']])) {
        return null;
    }
    $client = $s['clients'][$t['client']];
    if ($client['last_used'] < time() - 300) {
        connector_update(function (&$st) use ($t) {
            if (isset($st['clients'][$t['client']])) {
                $st['clients'][$t['client']]['last_used'] = time();
            }
        });
    }
    return ['user' => $t['user'], 'client' => $t['client'], 'client_name' => $client['name']];
}

/** Admin → Connections → Disconnect: forgets an app and its tokens ('*' for all). */
function connector_disconnect(string $clientId): void
{
    connector_update(function (&$s) use ($clientId) {
        foreach ($s['clients'] as $id => $c) {
            if ($clientId === '*' || $id === $clientId) {
                unset($s['clients'][$id]);
            }
        }
        $s['tokens'] = array_filter($s['tokens'], fn($t) => $clientId !== '*' && $t['client'] !== $clientId);
        $s['codes'] = array_filter($s['codes'], fn($c) => $clientId !== '*' && $c['client'] !== $clientId);
    });
}

/** Connected apps for the admin screen. */
function connector_clients_view(): array
{
    $s = connector_state();
    $out = [];
    foreach ($s['clients'] as $id => $c) {
        $tokens = array_filter($s['tokens'], fn($t) => $t['client'] === $id);
        if (!$tokens) {
            continue; // registered but never finished signing in, or expired
        }
        $out[] = ['id' => $id, 'name' => $c['name'], 'user' => $c['user'] ?? '', 'created' => $c['created'], 'last_used' => $c['last_used']];
    }
    usort($out, fn($a, $b) => $b['last_used'] <=> $a['last_used']);
    return $out;
}

/* ------------------------------------------------------------------ *
 * Change log (Admin → Connections), with undo
 * ------------------------------------------------------------------ */

function connector_log(): array
{
    return store_read('_auth/claude-log', []);
}

/**
 * Records one change. $undo is ['restore' => backup file] to put back the previous
 * version, ['delete' => 'pages/x' | 'collections/c/id'] for something new, or [].
 */
function connector_log_add(array $who, string $tool, string $summary, array $undo = [], string $link = ''): void
{
    $log = connector_log();
    array_unshift($log, ['id' => bin2hex(random_bytes(6)), 'time' => date('c'), 'user' => $who['user'], 'app' => $who['client_name'],
        'tool' => $tool, 'summary' => mb_substr($summary, 0, 300), 'undo' => $undo, 'link' => $link, 'undone' => false]);
    store_write('_auth/claude-log', array_slice($log, 0, 200), false);
}

/** Admin → Connections → Undo. Returns a message, or throws BenchOpError. */
function connector_undo(string $id, string $by): string
{
    $log = connector_log();
    foreach ($log as $i => $e) {
        if ($e['id'] !== $id) {
            continue;
        }
        if (!empty($e['undone'])) {
            throw new BenchOpError('That change has already been undone.', 409);
        }
        if (!empty($e['undo']['restore'])) {
            $r = op_restore_backup($e['undo']['restore']);
            $msg = 'Put back the version of ' . media_label($r['name'], store_read($r['name'], [])) . ' from before Claude\'s change.';
        } elseif (!empty($e['undo']['delete']) && preg_match('#^(pages/[a-z0-9\-]+|collections/[a-z0-9_\-]+/[a-z0-9_\-]+)$#', $e['undo']['delete'], $m)) {
            if (strpos($m[1], 'pages/') === 0) {
                op_delete_page(substr($m[1], 6));
            } else {
                [, $cid, $iid] = explode('/', $m[1]);
                op_delete_item($cid, $iid);
            }
            $msg = 'Removed what Claude added. It can be brought back from Admin → Backups.';
        } else {
            throw new BenchOpError('This change can\'t be undone automatically. Use Admin → Backups.', 409);
        }
        $log[$i]['undone'] = $by . ' at ' . date('c');
        store_write('_auth/claude-log', $log, false);
        return $msg;
    }
    throw new BenchOpError('Change not found.', 404);
}

/* ------------------------------------------------------------------ *
 * Fetching images from the web (upload_image_from_url)
 * ------------------------------------------------------------------ */

/** True if $ip is a public address (not private, loopback, link-local or reserved). */
function connector_public_ip(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        && !preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|::ffff:|fe80:|fc|fd)/i', $ip);
}

/**
 * Downloads an image to a temp file. Only public http(s) addresses, checked on
 * every redirect, so it can't be used to reach the server's own network.
 */
function connector_fetch_image(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new BenchOpError('PHP\'s curl extension is needed to fetch images from the web.', 500);
    }
    $max = (int) cfg('max_upload_mb', 5) * 1024 * 1024;
    $tmp = tempnam(sys_get_temp_dir(), 'bench-img');
    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user'])) {
            @unlink($tmp);
            throw new BenchOpError('Only http:// and https:// image addresses can be fetched.');
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips || array_filter($ips, fn($ip) => !connector_public_ip($ip))) {
            @unlink($tmp);
            throw new BenchOpError('That address isn\'t a public website, so it wasn\'t fetched.');
        }
        $port = (int) ($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        $fh = fopen($tmp, 'w');
        $size = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]], // use the address we checked
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Bench/' . BENCH_VERSION . ' (image fetch)',
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($fh, &$size, $max) {
                $size += strlen($data);
                return $size > $max ? 0 : fwrite($fh, $data);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        fclose($fh);
        if ($size > $max) {
            @unlink($tmp);
            throw new BenchOpError('That image is over ' . cfg('max_upload_mb', 5) . 'MB.');
        }
        if ($ok === false) {
            @unlink($tmp);
            throw new BenchOpError('Couldn\'t download that image.');
        }
        if ($code >= 300 && $code < 400 && $next !== '') {
            $url = $next;
            continue;
        }
        if ($code !== 200) {
            @unlink($tmp);
            throw new BenchOpError("Couldn't download that image (HTTP $code).");
        }
        return $tmp;
    }
    @unlink($tmp);
    throw new BenchOpError('Too many redirects.');
}

/** Everything Admin → Connections shows. */
function connector_view_data(): array
{
    $log = array_map(function ($e) {
        $e['can_undo'] = empty($e['undone']) && (!empty($e['undo']['restore']) ? is_file(BENCH_CONTENT . '/_backups/' . basename($e['undo']['restore'])) : !empty($e['undo']['delete']));
        unset($e['undo']);
        return $e;
    }, array_slice(connector_log(), 0, 50));
    return [
        'enabled' => connector_enabled(),
        'mcp_url' => connector_mcp_url(),
        'https' => strpos(connector_base(), 'https://') === 0,
        'clients' => connector_clients_view(),
        'log' => $log,
    ];
}
