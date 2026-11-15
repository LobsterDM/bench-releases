<?php
/**
 * Bench CMS — admin area
 */
require __DIR__ . '/_admin.php';

$view = preg_replace('/[^a-z]/', '', $_GET['view'] ?? 'pages') ?: 'pages';
$error = '';
$notice = '';

/* ---------- First run: create the admin account ---------- */
if (!has_users()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok($_POST['csrf'] ?? null)) {
        $u = strtolower(trim((string) ($_POST['username'] ?? '')));
        $p = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[a-z0-9._\-]{3,40}$/', $u)) {
            $error = 'Username: 3–40 letters, numbers, dots or dashes.';
        } elseif (strlen($p) < 10) {
            $error = 'Password needs at least 10 characters.';
        } elseif ($p !== ($_POST['password2'] ?? '')) {
            $error = 'Passwords don\'t match.';
        } else {
            store_write('_auth/users', [$u => ['hash' => password_hash($p, PASSWORD_DEFAULT), 'created' => date('c')]], false);
            if (!is_file(content_path('site'))) {
                store_write('site', schema_defaults(schema_settings()), false);
                store_write('pages/home', ['title' => 'Home', 'published' => true, 'in_nav' => true, 'order' => '0', 'blocks' => clean_blocks([['type' => 'hero', 'heading' => 'Welcome']])], false);
            }
            session_regenerate_id(true);
            $_SESSION['bench_user'] = $u;
            header('Location: ./');
            exit;
        }
    }
    admin_auth_screen('setup', $error);
    exit;
}

/* ---------- Login / logout ---------- */
if ($view === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if (!current_user()) {
    $wait = login_locked();
    if ($wait) {
        $error = 'Too many attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok($_POST['csrf'] ?? null)) {
        $ok = do_login(strtolower(trim((string) ($_POST['username'] ?? ''))), (string) ($_POST['password'] ?? ''));
        login_attempt($ok);
        if ($ok) {
            $back = $_GET['view'] ?? '';
            header('Location: ./' . ($back ? '?' . http_build_query($_GET) : ''));
            exit;
        }
        $error = 'Wrong username or password.';
    }
    admin_auth_screen('login', $error);
    exit;
}

/* ---------- Trash previews (the trash folder itself isn't public) ---------- */
if (isset($_GET['trash_image'])) {
    trash_send_preview((string) $_GET['trash_image']);
}

/* ---------- Logged-in views ---------- */
$site = site();
bench_update_remember_site_url();
$updateStatus = bench_update_status();
$updateAvailable = bench_updates_enabled() ? bench_update_available($updateStatus) : null;
$boot = [
    'csrf'   => csrf_token(),
    'api'    => 'api.php',
    'base'   => base_path(),
    'view'   => $view,
    'icons'  => icon_set(),
    'maxMb'  => (int) cfg('max_upload_mb', 5),
    // admin.js asks the API to check for updates in the background when this is due
    'updateCheckDue' => bench_updates_enabled() && time() - (int) $updateStatus['checked'] >= BENCH_UPDATE_EVERY,
    'urls'   => ['mode' => cfg('pretty_urls', 'auto') === 'auto' ? 'auto' : (cfg('pretty_urls') ? 'on' : 'off'), 'ok' => pretty_urls()],
    // Bilingual sites: the editor adds a box for each translatable field in these languages
    'languages' => bench_admin_languages(),
];

switch ($view) {
    case 'edit':
        $slug = (string) ($_GET['page'] ?? 'home');
        $page = page_get($slug);
        if (!$page) {
            header('Location: ./');
            exit;
        }
        $page += schema_defaults(schema_page());
        $page['blocks'] = clean_blocks($page['blocks'] ?? []);
        $boot += ['page' => $page, 'schema' => ['page' => schema_page(), 'blocks' => array_map(fn($d) => ['fields' => schema_for_client($d['fields'])] + $d, schema_blocks()), 'common' => schema_block_common()], 'viewUrl' => url($slug), 'viewUrls' => array_combine(bench_site_languages(), array_map(fn($l) => url($slug, $l), bench_site_languages())), 'pagesSlugs' => array_values(array_diff(array_column(pages_all(), 'slug'), ['home']))];
        $title = 'Edit: ' . $page['title'];
        break;
    case 'settings':
        $boot += ['settings' => $site, 'schema' => array_map('schema_for_client', schema_settings()), 'themes' => themes_all()];
        $title = 'Settings';
        break;
    case 'media':
        $title = 'Media';
        break;
    case 'backups':
        $boot += ['backups' => backups_list(), 'demos' => demos_available()];
        $title = 'Backups';
        break;
    case 'claude':
        require_once BENCH_CORE . '/connector.php';
        $boot += ['connector' => connector_view_data(), 'siteName' => $site['business_name'] ?? '',
            'collections' => array_values(array_map(fn($c) => ['id' => $c['id'], 'label' => $c['label']], collections()))];
        $title = 'Connections';
        break;
    case 'security':
        $boot += ['report' => bench_security_report(), 'phpVersion' => PHP_VERSION, 'advisories' => bench_php_advisories()];
        $title = 'Security';
        break;
    case 'account':
        $title = 'Account';
        break;
    case 'updates':
        $boot += ['updates' => bench_updates_view_data()];
        $title = 'Updates';
        break;
    case 'plugins':
        $boot += ['plugins' => array_values(array_map(fn($p) => [
            'id' => $p['id'], 'name' => $p['name'], 'description' => $p['description'], 'version' => $p['version'],
            'author' => $p['author'], 'enabled' => in_array($p['id'], plugins_enabled(), true),
        ], plugins_available()))];
        $title = 'Plugins';
        break;
    case 'collection':
        $c = collection((string) ($_GET['c'] ?? ''));
        if (!$c) {
            header('Location: ?view=plugins');
            exit;
        }
        $itemId = (string) ($_GET['item'] ?? '');
        $boot += [
            'collection' => ['id' => $c['id'], 'label' => $c['label'], 'singular' => $c['singular'], 'fields' => schema_for_client($c['fields']), 'route' => $c['route'], 'help' => $c['help'] ?? '', 'a11y' => !empty($c['a11y_check'])],
            'items'      => array_map(fn($i) => item_summary($c, $i), items_all($c['id'])),
        ];
        if ($itemId === 'new') {
            $boot['item'] = ['id' => '', 'data' => schema_defaults($c['fields'])];
        } elseif ($itemId !== '' && ($it = item_get($c['id'], $itemId))) {
            $boot['item'] = ['id' => $it['id'], 'data' => $it, 'url' => item_summary($c, $it)['url']];
        }
        $title = $c['label'];
        break;
    default:
        $view = 'pages';
        $boot['view'] = 'pages';
        $boot += ['pages' => array_map(fn($p) => [
            'slug' => $p['slug'], 'title' => $p['title'], 'published' => !empty($p['published']),
            'in_nav' => !empty($p['in_nav']), 'updated' => $p['updated'] ?? '', 'url' => url($p['slug']), 'blocks' => count($p['blocks'] ?? []),
            'translated' => array_combine(bench_translation_langs(), array_map(fn($l) => page_translation_progress($p, $l), bench_translation_langs())) ?: new stdClass(),
        ], pages_all())];
        $title = 'Pages';
}

$nav = [
    'pages'    => ['Pages', 'home'],
];
foreach (collections() as $c) {
    $nav['collection:' . $c['id']] = [$c['label'], $c['icon']];
}
$nav += [
    'settings' => ['Settings', 'wrench'],
    'media'    => ['Media', 'badge'],
    'backups'  => ['Backups', 'clock'],
    'plugins'  => ['Plugins', 'plug'],
    'claude'   => ['Connections', 'chat'],
    'security' => ['Security', 'shield'],
    'updates'  => ['Updates' . ($updateAvailable && !$updateAvailable['blocked'] ? ' (1)' : ''), 'gear'],
    'account'  => ['Account', 'users'],
];
$asset = fn($f) => 'assets/' . $f . '?v=' . filemtime(__DIR__ . '/assets/' . $f);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($site['business_name']) ?> admin</title>
<link rel="stylesheet" href="<?= e($asset('admin.css')) ?>">
</head>
<body class="admin view-<?= e($view) ?>">
<a class="skip-link" href="#app">Skip to content</a>
<div class="shell">
  <aside class="sidebar" aria-label="Admin">
    <a class="brand" href="./"><span class="brand__mark">B</span><span><strong>Bench</strong><small><?= e($site['business_name']) ?></small></span></a>
    <nav class="sidenav" aria-label="Admin sections">
      <?php foreach ($nav as $k => [$label, $ic]):
          $isColl = strpos($k, 'collection:') === 0;
          $href = $isColl ? '?view=collection&c=' . rawurlencode(substr($k, 11)) : '?view=' . $k;
          $active = $view === $k || ($view === 'edit' && $k === 'pages') || ($isColl && $view === 'collection' && ($_GET['c'] ?? '') === substr($k, 11)); ?>
        <a href="<?= e($href) ?>" class="<?= $active ? 'is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar__foot">
      <a href="<?= e(url()) ?>" target="_blank"><?= icon('arrow') ?><span>View site<span class="sr-only"> (opens in a new tab)</span></span></a>
      <a href="?view=logout"><?= icon('users') ?><span>Log out (<?= e(current_user()) ?>)</span></a>
    </div>
  </aside>
  <main class="main">
    <?php if ($alert = bench_security_alert()): ?>
      <div class="url-warning url-warning--danger">
        <strong><?= e($alert['title']) ?>.</strong> Your hosting company needs to update PHP — this affects every PHP website, not just Bench.
        <a href="?view=security">See Admin → Security</a> for what to ask them.
      </div>
    <?php endif; ?>
    <?php if ($updateAvailable && $view !== 'updates'): ?>
      <div class="url-warning url-warning--info">
        <strong>Bench <?= e($updateAvailable['version']) ?> is available</strong> (this site has <?= e(BENCH_VERSION) ?>).
        <a href="?view=updates">See what's new and update</a>.
      </div>
    <?php endif; ?>
    <div class="url-warning" id="url-warning" hidden>
      <strong>Short links aren't working on this server yet</strong>, so the site is using links like <code>index.php?p=vans</code> instead. Everything still works.
      To get links like <code>/vans</code>: make sure the <code>.htaccess</code> file from the Bench folder was uploaded (it's hidden on most computers, so FTP and file-manager uploads often skip it), and that your host allows <code>.htaccess</code> rewrites. On nginx, add the rule from README.md. This message goes away once short links work.
    </div>
    <div id="app" tabindex="-1"><div class="loading">Loading…</div></div>
  </main>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<div class="sr-only" id="toast-alert" role="alert"></div>
<script nonce="<?= e($GLOBALS['csp_nonce']) ?>">window.BENCH = <?= json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e($asset('admin.js')) ?>"></script>
</body>
</html>
<?php

function admin_auth_screen(string $mode, string $error): void
{
    $site = is_file(content_path('site')) ? site() : ['business_name' => 'Bench CMS'];
    $css = 'assets/admin.css?v=' . filemtime(__DIR__ . '/assets/admin.css');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $mode === 'setup' ? 'Set up' : 'Log in' ?> · <?= e($site['business_name']) ?></title>
<link rel="stylesheet" href="<?= e($css) ?>">
</head>
<body class="auth">
  <main>
  <form class="auth__card" method="post">
    <div class="brand brand--center"><span class="brand__mark">B</span><span><strong>Bench</strong><small><?= e($site['business_name']) ?></small></span></div>
    <h1><?= $mode === 'setup' ? 'Create your admin account' : 'Log in to edit your site' ?></h1>
    <?php if ($mode === 'setup'): ?><p class="muted">This only appears the first time. Choose a username and a strong password.</p><?php endif; ?>
    <?php if ($error): ?><p class="alert alert--error"><?= e($error) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label class="fld"><span>Username</span><input name="username" autocomplete="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></label>
    <label class="fld"><span>Password</span><input type="password" name="password" autocomplete="<?= $mode === 'setup' ? 'new-password' : 'current-password' ?>" required></label>
    <?php if ($mode === 'setup'): ?>
      <label class="fld"><span>Confirm password</span><input type="password" name="password2" autocomplete="new-password" required></label>
    <?php endif; ?>
    <button class="button button--primary button--block" type="submit"><?= $mode === 'setup' ? 'Create account' : 'Log in' ?></button>
    <p class="muted small"><a href="<?= e(url()) ?>">← Back to website</a></p>
  </form>
  </main>
</body>
</html>
    <?php
}
