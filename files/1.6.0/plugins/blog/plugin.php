<?php
/**
 * Blog plugin
 *
 * Registers:
 *   - a "posts" collection (Admin → Blog posts) with a page per post at /blog/<post-address>
 *   - a "Blog posts" section for the page editor: the main blog list (with category, tag and
 *     author archives and page numbers) or a "latest posts" strip anywhere on the site
 *   - an RSS feed at /blog/feed
 *   - a "Blog" tab in Settings (address, categories, default author, feed)
 *
 * Scheduling: a published post whose publish date is in the future stays hidden until then.
 * Bench builds each page when it's requested, so the post appears on time with no cron job.
 *
 * The blog list lives on an ordinary page whose address matches the blog address
 * (a page with the slug "blog", holding a "Blog posts" section).
 */

const BLOG_STATUS = ['draft', 'published'];

/** The blog's address ("blog" → /blog and /blog/<post>). Read straight from the file: site() isn't safe to call while plugins load. */
function blog_route(): string
{
    static $route = null;
    if ($route === null) {
        $raw = (string) (store_read('site', [])['blog_address'] ?? '');
        $route = preg_match('/^[a-z0-9\-]+$/', $raw) ? $raw : 'blog';
    }
    return $route;
}

/**
 * Category names from Settings → Blog, one per line, in the site's main language.
 * Posts store these names, and their slugs are used in links, so they're the same
 * on every language version. blog_category_label() gives the name to show.
 */
function blog_categories(): array
{
    $raw = store_read('site', []);
    return blog_category_lines((string) ($raw['blog_categories'] ?? schema_defaults(schema_settings())['blog_categories'] ?? ''));
}

function blog_category_lines(string $text): array
{
    $lines = preg_split('/\R/', $text);
    $out = [];
    foreach ($lines as $l) {
        $l = mb_substr(trim(str_replace(',', ' ', $l)), 0, 60);
        if ($l !== '' && !isset($out[slugify($l)])) {
            $out[slugify($l)] = $l;
        }
    }
    return array_values($out);
}

register_collection('posts', [
    'label'       => 'Blog posts',
    'singular'    => 'post',
    'icon'        => 'pen',
    'route'       => blog_route(),
    'image_field' => 'image',
    'sort'        => ['date', 'desc'],
    'clean_ids'   => true,
    'a11y_check'  => true,
    'title'       => '{title}',
    'subtitle'    => fn($p) => implode(' · ', array_filter([
        $p['date'] !== '' ? blog_date($p, true) : '',
        $p['author'],
        implode(', ', $p['categories']),
    ])),
    'is_public'   => fn($p) => blog_is_live($p),
    'badge'       => function ($p) {
        if ($p['status'] !== 'published') {
            return ['label' => 'Draft (hidden)', 'tone' => 'plain'];
        }
        return blog_is_live($p) ? ['label' => 'Published', 'tone' => 'ok'] : ['label' => 'Scheduled · ' . blog_date($p, true), 'tone' => 'warn'];
    },
    'before_save' => function (array $p, ?array $existing): array {
        if (trim($p['title']) === '') {
            throw new BenchOpError('Give the post a title.');
        }
        // Publishing with no date: it goes live now, like WordPress
        if ($p['status'] === 'published' && $p['date'] === '') {
            $p['date'] = date('Y-m-d\TH:i');
        }
        if (trim($p['author']) === '') {
            $p['author'] = trim((string) (site()['blog_default_author'] ?? ''));
        }
        return $p;
    },
    'help'        => 'Posts appear on the page with the address "' . blog_route() . '" (add a "Blog posts" section to it). '
        . 'To schedule a post, set it to Published and pick a future publish date: it goes live by itself at that time.',
    'detail_template' => __DIR__ . '/templates/detail.php',
    'subroutes'   => ['feed' => 'blog_feed'],
    'describe'    => fn($p) => mb_substr(blog_excerpt($p, 30), 0, 160),
    'fields' => [
        f('_h_post', 'Post', 'heading'),
        f('title', 'Title', 'text'),
        f('status', 'Status', 'select', ['width' => 'third', 'options' => BLOG_STATUS, 'labels' => ['draft' => 'Draft (hidden from site)', 'published' => 'Published'], 'default' => 'draft']),
        f('date', 'Publish date and time', 'datetime', ['width' => 'third', 'help' => 'Leave blank to publish straight away. Pick a future time to schedule the post.']),
        f('author', 'Author', 'text', ['width' => 'third', 'translate' => false, 'suggest' => fn() => blog_authors(), 'help' => 'The name shown on the post. Leave blank for the default in Settings → Blog.']),
        f('sticky', 'Pin to the top of the blog', 'checkbox'),

        f('_h_image', 'Featured image', 'heading'),
        f('image', 'Featured image', 'image', ['help' => 'Shown at the top of the post, on blog lists and when the post is shared.']),
        f('image_alt', 'Image description (alt text)', 'text', ['help' => 'What the image shows, for people who can\'t see it. Leave empty only if it\'s purely decorative.']),

        f('_h_content', 'Content', 'heading'),
        f('excerpt', 'Summary (optional)', 'textarea', ['help' => 'One or two sentences for blog lists and search results. Leave blank to use the start of the post.']),
        f('body', 'Post', 'richtext', ['images' => true]),

        f('_h_terms', 'Categories and tags', 'heading'),
        f('categories', 'Categories', 'multiselect', ['options' => fn() => blog_categories(), 'empty' => 'No categories yet. Add them in Settings → Blog.']),
        f('tags', 'Tags', 'tags', ['suggest' => fn() => blog_all_tags(), 'help' => 'Press Enter or type a comma after each tag.']),

        f('_h_seo', 'Search engines', 'heading'),
        f('seo_title', 'SEO title (optional)', 'text', ['help' => 'Shown in Google and the browser tab. Leave blank to use the title.']),
        f('meta_description', 'Search engine description (optional)', 'textarea', ['help' => 'About 150 characters. Leave blank to use the summary.']),
    ],
]);

register_block('blog_posts', [
    'label'       => 'Blog posts',
    'description' => 'Your posts from Admin → Blog posts: the main blog list, or the latest few anywhere',
    'fields' => [
        f('heading', 'Heading'),
        f('intro', 'Intro', 'textarea'),
        f('category', 'Only posts in this category', 'select', ['options' => fn() => array_merge([''], blog_categories()), 'labels' => ['' => 'All categories']]),
        f('limit', 'Posts per page (or how many to show)', 'text', ['default' => '9', 'translate' => false]),
        f('archive', 'Main blog list', 'checkbox', ['default' => true, 'help' => 'Adds page numbers, and shows the posts for category, tag and author links. Turn off for a "latest posts" strip on other pages.']),
        f('show_categories', 'Show category links above the list', 'checkbox', ['default' => true]),
        f('layout', 'Layout', 'select', ['options' => ['grid', 'list'], 'labels' => ['grid' => 'Cards in a grid', 'list' => 'List with image beside'], 'default' => 'grid']),
        f('show_excerpt', 'Show each post\'s summary', 'checkbox', ['default' => true]),
        f('button_text', 'Button under the list', 'text', ['help' => 'e.g. "Read our blog"']),
        f('button_link', 'Button link', 'link', ['default' => blog_route()]),
    ],
]);

register_settings('Blog', [
    f('blog_address', 'Blog address', 'text', ['default' => 'blog', 'translate' => false, 'help' => 'Posts live at /' . blog_route() . '/<post>. Make a page with this address and add a "Blog posts" section to it. Lower-case letters, numbers and dashes.']),
    f('blog_categories', 'Categories', 'textarea', ['default' => "News", 'help' => 'One per line. Renaming a category here removes it from posts that had the old name.', 'translate_help' => 'The same categories in the same order, one per line, e.g. "Newyddion" for News.']),
    f('blog_default_author', 'Default author', 'text', ['translate' => false, 'help' => 'Used when a post has no author, e.g. your business name. Don\'t use a login name.']),
    f('blog_show_author', 'Show the author on posts', 'checkbox', ['default' => true]),
    f('blog_feed', 'Publish an RSS feed', 'checkbox', ['default' => true, 'help' => 'At /' . blog_route() . '/feed, for feed readers and email newsletters.']),
]);

add_hook('head', function () {
    $out = '<link rel="stylesheet" href="' . e(plugin_asset(__DIR__, 'assets/blog.css')) . '">' . "\n";
    if (!empty(site()['blog_feed'])) {
        $out .= '<link rel="alternate" type="application/rss+xml" title="' . e(t('%s blog', site()['business_name'])) . '" href="' . e(url(blog_route() . '/feed')) . '">' . "\n";
    }
    // Set by the post template / list section, which render before the page head
    $meta = $GLOBALS['blog_head'] ?? [];
    if (!empty($meta['image'])) {
        $out .= '<meta property="og:image" content="' . e(blog_abs_url(media_url($meta['image']))) . '">' . "\n";
    }
    if (!empty($meta['published'])) {
        $out .= '<meta property="article:published_time" content="' . e($meta['published']) . '">' . "\n";
    }
    if (!empty($meta['noindex'])) {
        $out .= '<meta name="robots" content="noindex, follow">' . "\n";
    }
    return $out;
});

/* ---------- Helpers used by the templates ---------- */

/**
 * A category's name in the language being shown. On bilingual sites, Settings → Blog →
 * Categories has a translation box: its lines match the main list line by line.
 */
function blog_category_label(string $name): string
{
    if (!bench_localising()) {
        return $name;
    }
    $main = blog_categories();
    $shown = blog_category_lines((string) (site()['blog_categories'] ?? ''));
    $i = array_search(slugify($name), array_map('slugify', $main), true);
    return $i !== false && isset($shown[$i]) && count($shown) === count($main) ? $shown[$i] : $name;
}

/** Publish time (site-local): the publish date, else when it was first saved. */
function blog_time(array $p): int
{
    $t = $p['date'] !== '' ? strtotime(str_replace('T', ' ', $p['date'])) : false;
    return $t ?: (strtotime((string) ($p['created'] ?? '')) ?: 0);
}

/** Published, and its publish time has come. */
function blog_is_live(array $p): bool
{
    return ($p['status'] ?? '') === 'published' && blog_time($p) <= time();
}

function blog_date(array $p, bool $withTime = false): string
{
    $t = blog_time($p);
    if (!$t) {
        return '';
    }
    // The admin (with time) stays in English; visitors get the month in the page's language
    return $withTime ? date('j M Y, H:i', $t) : date('j', $t) . ' ' . t(date('F', $t)) . ' ' . date('Y', $t);
}

/** <time> with a machine-readable date. */
function blog_time_tag(array $p): string
{
    $t = blog_time($p);
    return $t ? '<time datetime="' . e(date('c', $t)) . '">' . e(blog_date($p)) . '</time>' : '';
}

/** Live posts, newest first. $f: category, tag, author (slugs), sticky_first (bool). */
function blog_posts(array $f = []): array
{
    $posts = array_values(array_filter(items_all('posts', true), function ($p) use ($f) {
        if (!empty($f['category']) && !in_array($f['category'], array_map('slugify', $p['categories']), true)) return false;
        if (!empty($f['tag']) && !in_array($f['tag'], array_map('slugify', $p['tags']), true)) return false;
        if (!empty($f['author']) && slugify($p['author']) !== $f['author']) return false;
        return true;
    }));
    usort($posts, function ($a, $b) use ($f) {
        if (!empty($f['sticky_first']) && ($s = (int) !empty($b['sticky']) <=> (int) !empty($a['sticky'])) !== 0) {
            return $s;
        }
        return blog_time($b) <=> blog_time($a) ?: strcmp($a['id'], $b['id']);
    });
    return $posts;
}

/** Plain-text summary: the excerpt, or the start of the post. */
function blog_excerpt(array $p, int $words = 32): string
{
    if (trim($p['excerpt']) !== '') {
        return trim($p['excerpt']);
    }
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>'], ' ', (string) $p['body'])), ENT_QUOTES, 'UTF-8')));
    $parts = preg_split('/\s+/', $text);
    return count($parts) > $words ? implode(' ', array_slice($parts, 0, $words)) . '…' : $text;
}

/** Link to a blog list with filters, e.g. ['category' => 'news']. $slug: the page holding the list (default: the blog page). */
function blog_list_url(array $query = [], ?string $slug = null): string
{
    $base = url($slug ?? blog_route());
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null && $v !== 1);
    return $query ? $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($query) : $base;
}

function blog_category_url(string $name): string { return blog_list_url(['category' => slugify($name)]); }
function blog_tag_url(string $name): string { return blog_list_url(['tag' => slugify($name)]); }
function blog_author_url(string $name): string { return blog_list_url(['author' => slugify($name)]); }

/** The display name for a category, tag or author slug (from the posts), or ''. */
function blog_term_name(string $kind, string $slug): string
{
    $pool = $kind === 'category' ? blog_categories() : ($kind === 'tag' ? blog_all_tags() : blog_authors());
    foreach ($pool as $name) {
        if (slugify($name) === $slug) {
            return $name;
        }
    }
    return '';
}

/** Every tag used on any post (drafts included, for suggestions), A–Z. */
function blog_all_tags(): array
{
    $all = [];
    foreach (items_all('posts') as $p) {
        foreach ($p['tags'] as $t) {
            $all[mb_strtolower($t)] = $all[mb_strtolower($t)] ?? $t;
        }
    }
    natcasesort($all);
    return array_values($all);
}

/** Authors used so far, plus the default author. */
function blog_authors(): array
{
    $all = array_filter(array_merge([trim((string) (site()['blog_default_author'] ?? ''))], array_column(items_all('posts'), 'author')));
    $out = [];
    foreach ($all as $a) {
        $out[slugify($a)] = $out[slugify($a)] ?? $a;
    }
    return array_values($out);
}

function blog_abs_url(string $path): string
{
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https://' : 'http://') . $host . $path;
}

/** A post card for lists. $hx: heading level for the title (h2 when the section has no heading). */
function blog_card(array $p, string $hx = 'h3', bool $excerpt = true): string
{
    $c = collection('posts');
    $url = item_url($c, $p);
    $img = $p['image'];
    ob_start(); ?>
    <article class="blog-card<?= $img ? '' : ' blog-card--noimg' ?><?= !empty($p['sticky']) ? ' is-sticky' : '' ?>">
      <?php if ($img): ?>
        <a class="blog-card__media" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e(media_url($img)) ?>" alt="" loading="lazy"></a>
      <?php endif; ?>
      <div class="blog-card__body">
        <?php if ($p['categories']): ?><p class="blog-card__cats"><span class="sr-only"><?= e(t('Category:')) ?> </span><?= e(implode(', ', array_map('blog_category_label', $p['categories']))) ?></p><?php endif; ?>
        <<?= $hx ?> class="blog-card__title"><a href="<?= e($url) ?>"><?= e($p['title']) ?></a></<?= $hx ?>>
        <p class="blog-meta"><?= blog_time_tag($p) ?><?php if (!empty(site()['blog_show_author']) && $p['author'] !== ''): ?><span aria-hidden="true"> · </span><span class="sr-only">, </span><?= e(t('by %s', $p['author'])) ?><?php endif; ?><?php if (!empty($p['sticky'])): ?><span aria-hidden="true"> · </span><span class="sr-only">, </span><?= e(t('Pinned')) ?><?php endif; ?></p>
        <?php if ($excerpt && ($ex = blog_excerpt($p)) !== ''): ?><p class="blog-card__excerpt"><?= e($ex) ?></p><?php endif; ?>
        <p class="blog-card__more"><a href="<?= e($url) ?>"><?= e(t('Read the post')) ?><span class="sr-only">: <?= e($p['title']) ?></span></a></p>
      </div>
    </article>
    <?php
    return ob_get_clean();
}

/** /blog/feed — RSS 2.0 with the latest 20 live posts. */
function blog_feed(array $c): void
{
    $site = site();
    if (empty($site['blog_feed'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return;
    }
    $posts = array_slice(blog_posts(), 0, 20);
    $x = fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $home = blog_abs_url(url(blog_route()));
    header('Content-Type: application/rss+xml; charset=utf-8');
    header('Cache-Control: public, max-age=600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>' . "\n";
    echo '<title>' . $x($site['business_name']) . '</title><link>' . $x($home) . '</link>';
    echo '<description>' . $x($site['tagline'] ?: $site['default_description'] ?: t('%s blog', $site['business_name'])) . '</description>';
    echo '<language>' . $x(strtolower(bench_html_lang())) . '</language><atom:link href="' . $x(blog_abs_url(url(blog_route() . '/feed'))) . '" rel="self" type="application/rss+xml"/>';
    if ($posts) {
        echo '<lastBuildDate>' . date(DATE_RSS, blog_time($posts[0])) . '</lastBuildDate>';
    }
    echo "\n";
    foreach ($posts as $p) {
        $link = blog_abs_url(item_url($c, $p));
        echo '<item><title>' . $x($p['title']) . '</title><link>' . $x($link) . '</link><guid isPermaLink="true">' . $x($link) . '</guid>';
        echo '<pubDate>' . date(DATE_RSS, blog_time($p)) . '</pubDate>';
        if ($p['author'] !== '' && !empty($site['blog_show_author'])) echo '<dc:creator>' . $x($p['author']) . '</dc:creator>';
        foreach ($p['categories'] as $cat) echo '<category>' . $x(blog_category_label($cat)) . '</category>';
        echo '<description>' . $x(blog_excerpt($p, 60)) . '</description>';
        echo "</item>\n";
    }
    echo '</channel></rss>';
}
