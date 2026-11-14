<?php
/**
 * Router for PHP's built-in dev server, so pretty URLs work locally:
 *   php -S localhost:8000 router.php
 * Not used on Apache/nginx.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// Mirror the .htaccess protections
if (preg_match('#^/(content|core|demos|docs|tests|tools)(/|$)#', $uri) || preg_match('#^/(plugins|custom)/.*\.php$#i', $uri) || preg_match('#^/uploads/.*\.php#i', $uri) || basename($uri) === 'config.php') {
    http_response_code(403);
    exit('Forbidden');
}

// Claude connector sign-in (see .htaccess)
if (preg_match('#^/\.well-known/(oauth-authorization-server|openid-configuration|oauth-protected-resource)(/.*)?$#', $uri, $m)) {
    $map = ['oauth-authorization-server' => 'authorization-server', 'openid-configuration' => 'openid-configuration', 'oauth-protected-resource' => 'protected-resource'];
    $_SERVER['PATH_INFO'] = '/' . $map[$m[1]];
    $_SERVER['SCRIPT_NAME'] = '/admin/oauth.php';
    require __DIR__ . '/admin/oauth.php';
    return true;
}
if (preg_match('#^/admin/oauth\.php(/[a-z\-]+)?$#', $uri, $m)) {
    $_SERVER['PATH_INFO'] = $m[1] ?? '';
    $_SERVER['SCRIPT_NAME'] = '/admin/oauth.php';
    require __DIR__ . '/admin/oauth.php';
    return true;
}

if ($uri !== '/' && is_file($file)) {
    return false; // let the server send the file
}
if (preg_match('#^/admin/?$#', $uri)) {
    if ($uri === '/admin') { header('Location: /admin/'); return true; }
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    require __DIR__ . '/admin/index.php';
    return true;
}
if (preg_match('#^/([a-z0-9\-]+(?:/[a-z0-9\-]+)?)/?$#', $uri, $m)) {
    $_GET['p'] = $m[1];
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
