<?php
/**
 * Bench CMS — sign-in for the Claude connector (OAuth 2.1 with PKCE and dynamic
 * client registration, as Claude's custom connectors expect).
 *
 *   /admin/oauth.php/authorization-server   metadata (also at /.well-known/oauth-authorization-server)
 *   /admin/oauth.php/protected-resource     metadata (also at /.well-known/oauth-protected-resource)
 *   /admin/oauth.php/register               POST: Claude registers itself
 *   /admin/oauth.php/authorize              Bench login + "Allow Claude?" page
 *   /admin/oauth.php/token                  POST: codes and refresh tokens → access tokens
 *
 * See core/connector.php.
 */
$endpoint = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
if ($endpoint === '') {
    $endpoint = preg_replace('/[^a-z\-]/', '', (string) ($_GET['endpoint'] ?? ''));
}

if ($endpoint === 'authorize') {
    require __DIR__ . '/_admin.php'; // session, login, CSRF, admin headers
} else {
    require dirname(__DIR__) . '/core/bootstrap.php';
    require BENCH_CORE . '/media.php';
    require BENCH_CORE . '/content-ops.php';
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
require BENCH_CORE . '/connector.php';

function oauth_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($endpoint) {
    case 'authorization-server':
    case 'openid-configuration':
        header('Access-Control-Allow-Origin: *');
        oauth_json(connector_as_metadata());

    case 'protected-resource':
        header('Access-Control-Allow-Origin: *');
        oauth_json(connector_pr_metadata());

    case 'register':
        if ($method !== 'POST') {
            oauth_json(['error' => 'invalid_request', 'error_description' => 'POST only'], 405);
        }
        $req = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
        try {
            oauth_json(connector_register(is_array($req) ? $req : []), 201);
        } catch (BenchOpError $e) {
            $code = strpos($e->getMessage(), 'Redirect') !== false ? 'invalid_redirect_uri' : 'invalid_client_metadata';
            oauth_json(['error' => $e->status === 403 ? 'access_denied' : $code, 'error_description' => $e->getMessage()], $e->status === 403 ? 403 : 400);
        }

    case 'token':
        if ($method !== 'POST') {
            oauth_json(['error' => 'invalid_request', 'error_description' => 'POST only'], 405);
        }
        $p = $_POST;
        if (!$p && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
            $p = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true) ?: [];
        }
        // A client may also authenticate with HTTP Basic (client_id only; we issue no secrets)
        if (empty($p['client_id']) && !empty($_SERVER['PHP_AUTH_USER'])) {
            $p['client_id'] = $_SERVER['PHP_AUTH_USER'];
        }
        try {
            oauth_json(connector_token($p));
        } catch (BenchOpError $e) {
            oauth_json(['error' => $e->getMessage()], $e->status);
        }

    case 'authorize':
        oauth_authorize();
        exit;

    default:
        oauth_json(['error' => 'not_found'], 404);
}

/* ------------------------------------------------------------------ *
 * The sign-in page
 * ------------------------------------------------------------------ */

function oauth_authorize(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $q = $_GET;
        $client = connector_client((string) ($q['client_id'] ?? ''));
        $redirect = (string) ($q['redirect_uri'] ?? '');
        // Never send anyone to an address the app didn't register
        if (!$client || !in_array($redirect, $client['redirect_uris'], true)) {
            oauth_page('error', ['message' => 'This sign-in link isn\'t valid for this site. Go back to Claude and try connecting again.']);
            return;
        }
        if (!connector_enabled()) {
            oauth_page('error', ['message' => 'Claude access is turned off for this site. An admin can turn it on in Admin → Connections, then try connecting again.']);
            return;
        }
        if (($q['response_type'] ?? '') !== 'code' || ($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43}$/', (string) ($q['code_challenge'] ?? ''))) {
            oauth_redirect($redirect, ['error' => 'invalid_request', 'error_description' => 'PKCE (S256) is required', 'state' => $q['state'] ?? null]);
        }
        $rid = bin2hex(random_bytes(12));
        $_SESSION['oauth_req'] = array_slice(($_SESSION['oauth_req'] ?? []), -4, null, true);
        $_SESSION['oauth_req'][$rid] = [
            'client_id' => (string) $q['client_id'], 'redirect' => $redirect, 'challenge' => (string) $q['code_challenge'],
            'state' => isset($q['state']) ? (string) $q['state'] : null, 'resource' => (string) ($q['resource'] ?? ''), 'time' => time(),
        ];
        oauth_page(current_user() ? 'consent' : 'login', ['rid' => $rid, 'client' => $client]);
        return;
    }

    // POST: log in, or Allow / Cancel
    $rid = (string) ($_POST['rid'] ?? '');
    $req = $_SESSION['oauth_req'][$rid] ?? null;
    if (!$req || $req['time'] < time() - 1800) {
        oauth_page('error', ['message' => 'This sign-in has expired. Go back to Claude and try connecting again.']);
        return;
    }
    $client = connector_client($req['client_id']);
    if (!$client || !csrf_ok($_POST['csrf'] ?? null)) {
        oauth_page('error', ['message' => 'This sign-in couldn\'t be completed. Go back to Claude and try connecting again.']);
        return;
    }

    if (($_POST['step'] ?? '') === 'login') {
        $error = '';
        if ($wait = login_locked()) {
            $error = 'Too many attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
        } else {
            $ok = do_login(strtolower(trim((string) ($_POST['username'] ?? ''))), (string) ($_POST['password'] ?? ''));
            login_attempt($ok);
            if (!$ok) {
                $error = 'Wrong username or password.';
            }
        }
        oauth_page($error ? 'login' : 'consent', ['rid' => $rid, 'client' => $client, 'error' => $error]);
        return;
    }

    if (!current_user()) {
        oauth_page('login', ['rid' => $rid, 'client' => $client]);
        return;
    }
    unset($_SESSION['oauth_req'][$rid]);
    if (($_POST['decision'] ?? '') !== 'allow') {
        oauth_redirect($req['redirect'], ['error' => 'access_denied', 'state' => $req['state']]);
    }
    if (!connector_enabled()) {
        oauth_page('error', ['message' => 'Claude access was turned off for this site.']);
        return;
    }
    $code = connector_issue_code($req['client_id'], current_user(), $req['redirect'], $req['challenge'], $req['resource']);
    oauth_redirect($req['redirect'], ['code' => $code, 'state' => $req['state'], 'iss' => connector_base()]);
}

function oauth_redirect(string $uri, array $params): void
{
    $params = array_filter($params, fn($v) => $v !== null);
    header('Location: ' . $uri . (strpos($uri, '?') === false ? '?' : '&') . http_build_query($params), true, 303);
    exit;
}

/** Login, consent and error pages, styled like the admin login. */
function oauth_page(string $mode, array $d): void
{
    $site = site();
    $css = 'assets/admin.css?v=' . filemtime(__DIR__ . '/assets/admin.css');
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/oauth.php'), '/') . '/';
    // The Allow button redirects to Claude, so the form may post there (CSP form-action applies to redirects)
    $to = '';
    if (!empty($d['rid']) && !empty($_SESSION['oauth_req'][$d['rid']]['redirect'])) {
        $p = parse_url($_SESSION['oauth_req'][$d['rid']]['redirect']);
        $to = ' ' . $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'$to; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    $titles = ['login' => 'Log in to connect Claude', 'consent' => 'Allow Claude to edit this website?', 'error' => 'Couldn\'t connect Claude'];
    $collections = array_map(fn($c) => strtolower($c['label']), collections());
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titles[$mode]) ?> · <?= e($site['business_name']) ?></title>
<link rel="stylesheet" href="<?= e($base . $css) ?>">
</head>
<body class="auth">
  <main>
  <?php if ($mode === 'error'): ?>
    <div class="auth__card">
      <div class="brand brand--center"><span class="brand__mark">B</span><span><strong>Bench</strong><small><?= e($site['business_name']) ?></small></span></div>
      <h1><?= e($titles[$mode]) ?></h1>
      <p class="alert alert--error"><?= e($d['message']) ?></p>
      <p class="muted small"><a href="<?= e(url()) ?>">← Back to website</a></p>
    </div>
  <?php else: ?>
    <form class="auth__card" method="post" action="<?= e($base . 'oauth.php/authorize') ?>">
      <div class="brand brand--center"><span class="brand__mark">B</span><span><strong>Bench</strong><small><?= e($site['business_name']) ?></small></span></div>
      <h1><?= e($titles[$mode]) ?></h1>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="rid" value="<?= e($d['rid']) ?>">
      <?php if ($mode === 'login'): ?>
        <p class="muted"><strong><?= e($d['client']['name']) ?></strong> wants to connect to this website. Log in with your Bench admin account first.</p>
        <?php if (!empty($d['error'])): ?><p class="alert alert--error" role="alert"><?= e($d['error']) ?></p><?php endif; ?>
        <input type="hidden" name="step" value="login">
        <label class="fld"><span>Username</span><input name="username" autocomplete="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></label>
        <label class="fld"><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
        <button class="button button--primary button--block" type="submit">Log in</button>
      <?php else: ?>
        <p><strong><?= e($d['client']['name']) ?></strong> is asking to edit <strong><?= e($site['business_name']) ?></strong> as <strong><?= e(current_user()) ?></strong>.</p>
        <p>Claude will be able to:</p>
        <ul class="auth__list">
          <li>read and change pages and their sections</li>
          <?php if ($collections): ?><li>add, change and delete <?= e(implode(' and ', $collections)) ?></li><?php endif; ?>
          <li>add images from the web</li>
          <li>change site settings such as contact details, opening hours, colours and theme</li>
        </ul>
        <p>Claude <strong>can't</strong> change logins, plugins, updates, security settings or code that runs scripts.</p>
        <p class="muted">Changes go live straight away. Each one is backed up and listed in Admin → Connections, where you can undo it or disconnect Claude at any time.</p>
        <button class="button button--primary button--block" type="submit" name="decision" value="allow">Allow</button>
        <button class="button button--block" type="submit" name="decision" value="deny">Cancel</button>
      <?php endif; ?>
      <p class="muted small"><a href="<?= e(url()) ?>">← Back to website</a></p>
    </form>
  <?php endif; ?>
  </main>
</body>
</html>
    <?php
}
