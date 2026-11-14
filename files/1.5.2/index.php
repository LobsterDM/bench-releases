<?php
/**
 * Bench CMS — public front controller
 */
require __DIR__ . '/core/bootstrap.php';
bench_maintenance_check(); // "back in a moment" while an update swaps files

// Check for Bench updates once every 12 hours, after the page has been sent
register_shutdown_function('bench_updates_tick');

// First run: no content yet → go to admin setup
if (!is_file(content_path('site'))) {
    header('Location: ' . base_path() . '/admin/');
    exit;
}

[$slug, $viaShortLink] = request_slug();
if ($viaShortLink) {
    pretty_urls_mark_ok(); // this server routes /vans to index.php, so short links are safe to print
}
// Lightweight probe the admin uses to check short links work on this server
if ($slug === 'bench-url-check') {
    header('Cache-Control: no-store');
    header('Content-Type: text/plain');
    http_response_code(200);
    exit($viaShortLink ? 'ok' : 'no');
}
if (!preg_match('#^[a-z0-9\-]+(/[a-z0-9\-]+)?$#', $slug) && $slug !== '') {
    $slug = '404';
}
if ($slug === '' || $slug === 'index' || $slug === 'index.php') {
    $slug = 'home';
}

// Plugin detail pages, e.g. /cars/2019-ford-focus-x7k2
if (strpos($slug, '/') !== false) {
    [$base, $itemId] = explode('/', $slug, 2);
    $c = collection_by_route($base);
    $item = $c ? item_get($c['id'], $itemId) : null;
    $visible = $item && (!is_callable($c['is_public']) || ($c['is_public'])($item) || is_admin());
    if ($visible) {
        $listing = page_get($base);
        $title = item_title($c, $item);
        $page = [
            'slug'             => $base, // keeps the listing page highlighted in the menu
            'title'            => $title,
            'seo_title'        => $title . ' | ' . site()['business_name'],
            'meta_description' => $item['meta_description'] ?? '',
            'published'        => true,
            'edit_url'         => base_path() . '/admin/?view=collection&c=' . rawurlencode($c['id']) . '&item=' . rawurlencode($item['id']),
        ] + ($listing ?: []);
        $page['title'] = $title;
        render_page($page + schema_defaults(schema_page()), 200, render_item_detail($c, $item));
        exit;
    }
    $slug = '404';
}

$page = page_get($slug);

// Drafts are visible only to a logged-in admin (handy for previewing)
if ($page && empty($page['published']) && !is_admin()) {
    $page = null;
}

if (!$page) {
    $page = page_get('404') ?: [
        'slug'   => '404',
        'title'  => 'Page not found',
        'blocks' => [[
            'type'        => 'hero',
            'size'        => 'small',
            'heading'     => 'Sorry, we can\'t find that page',
            'subheading'  => 'It may have moved. Try the home page, or give us a call.',
            'button_text' => 'Back to home',
            'button_link' => 'home',
        ]],
    ];
    $page += schema_defaults(schema_page());
    render_page($page, 404);
    exit;
}

$page += schema_defaults(schema_page());
render_page($page);
